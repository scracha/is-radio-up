<?php
/**
 * Radio diagnostic endpoint.
 * GET ?ip=x.x.x.x
 * 
 * Returns: ping status, AC2 device info (signal, LAN, rates, last seen),
 * WISP-graphing info (RSSI, MCS, LAN rate, last seen), Splynx service status.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
set_time_limit(120);
ini_set('memory_limit', '512M');

$ip = trim($_GET['ip'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

$result = ['ip' => $ip, 'server_utc_offset' => date('P')];

// --- Splynx service status ---
$splynxData = null;
if (file_exists(DATA_STORE_PATH)) {
    $all = json_decode(file_get_contents(DATA_STORE_PATH), true);
    $splynxData = $all[$ip] ?? null;
}
$result['splynx'] = $splynxData ? [
    'customer'        => $splynxData['customer_name'] ?? 'Unknown',
    'customer_id'     => $splynxData['customer_id'] ?? null,
    'customer_status' => $splynxData['customer_status'] ?? 'unknown',
    'service_status'  => $splynxData['service_status'] ?? 'unknown',
    'address'         => $splynxData['service_address'] ?? $splynxData['customer_address_fallback'] ?? '',
    'description'     => $splynxData['service_description'] ?? '',
    'service_id'      => $splynxData['service_id'] ?? null,
    'phone'           => $splynxData['customer_phone'] ?? '',
    'contact_2_name'  => $splynxData['contact_2_name'] ?? '',
    'contact_2_phone' => $splynxData['contact_2_phone'] ?? '',
] : null;

// --- Ping ---
$escaped = escapeshellarg($ip);
$fping = trim(shell_exec("which fping 2>/dev/null") ?? '');
if ($fping) {
    exec("fping -c 1 -t 1000 {$escaped} 2>/dev/null", $out, $code);
    $result['ping'] = $code === 0;
} else {
    exec("ping -c 1 -W 1 {$escaped} 2>&1", $out, $code);
    $result['ping'] = $code === 0;
}

// --- AirControl2 ---
$result['aircontrol2'] = null;
$ac2Found = false;

require_once __DIR__ . '/../aircontrol2-helpers/AirControl2Client.php';
$ac2Client = new AirControl2Client($aircontrolCredsFile);
if ($ac2Client->isConfigured() && $ac2Client->login()) {
    $device = $ac2Client->findDeviceByIp($ip);
    if ($device) {
        $ac2Found = true;
        $info = AirControl2Client::extractDeviceInfo($device);
        $result['aircontrol2'] = $info;
    }
    $ac2Client->logout();
}

// --- WISP-graphing availability flag ---
$result['wisp_graphing_available'] = file_exists($wispGraphingDbPath);

// --- WISP-graphing ---
$result['wisp_graphing'] = null;

if (file_exists($wispGraphingDbPath)) {
    try {
        $db = new PDO('sqlite:' . $wispGraphingDbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("
            SELECT s.id, s.device_name, s.mac_address, s.status, s.last_seen,
                   s.session_time, s.lan_rate, s.uptime, s.snmp_community,
                   s.last_subscriber_poll,
                   ap.name as ap_name, ap.ip_address as ap_ip
            FROM subscribers s
            JOIN access_points ap ON s.access_point_id = ap.id
            WHERE s.ip_address = ? LIMIT 1
        ");
        $stmt->execute([$ip]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sub) {
            // Get default SNMP community
            $commStmt = $db->prepare("SELECT value FROM configuration WHERE key = 'default_subscriber_snmp_community'");
            $commStmt->execute();
            $defaultComm = ($commStmt->fetch(PDO::FETCH_ASSOC))['value'] ?? 'public';
            $snmpCommunity = $sub['snmp_community'] ?? $defaultComm;

            // Get latest stats
            $statsStmt = $db->prepare("
                SELECT rssi_upload, rssi_download, mcs_upload, mcs_download,
                       retrans_download, retrans_upload
                FROM subscriber_stats WHERE subscriber_id = ?
                ORDER BY timestamp DESC LIMIT 1
            ");
            $statsStmt->execute([$sub['id']]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $result['wisp_graphing'] = [
                'device_name'    => $sub['device_name'],
                'mac'            => $sub['mac_address'],
                'status'         => $sub['status'],
                'last_seen'      => $sub['last_seen'],
                'last_seen_mins_ago' => $sub['last_seen'] 
                    ? (function() use ($sub) {
                        $seen = new DateTime($sub['last_seen'], new DateTimeZone('UTC'));
                        return round((time() - $seen->getTimestamp()) / 60);
                    })()
                    : null,
                'session_time'   => $sub['session_time'],
                'lan_rate'       => $sub['lan_rate'],
                'ap_name'        => $sub['ap_name'],
                'ap_ip'          => $sub['ap_ip'],
                'rssi_upload'    => $stats['rssi_upload'] ?? null,
                'rssi_download'  => $stats['rssi_download'] ?? null,
                'mcs_upload'     => $stats['mcs_upload'] ?? null,
                'mcs_download'   => $stats['mcs_download'] ?? null,
                'retrans_dl'     => $stats['retrans_download'] ?? null,
                'retrans_ul'     => $stats['retrans_upload'] ?? null,
                'snmp_community' => $snmpCommunity,
                'last_poll'      => $sub['last_subscriber_poll'] ?? null,
                'last_poll_mins_ago' => $sub['last_subscriber_poll'] 
                    ? (function() use ($sub) {
                        $poll = new DateTime($sub['last_subscriber_poll'], new DateTimeZone('UTC'));
                        return round((time() - $poll->getTimestamp()) / 60);
                    })()
                    : null,
            ];
        }
    } catch (Exception $e) {}
}

// --- Determine last online ---
$lastOnline = 'Unknown';
if ($result['ping']) {
    $lastOnline = 'Now (online)';
    $lastOnlineMins = 0;
} elseif ($result['aircontrol2'] && $result['aircontrol2']['last_seen']) {
    $lastOnline = $result['aircontrol2']['last_seen'];
    // AC2 timestamps are UTC with Z suffix — formatLastSeen handles this OK
    $lastOnlineMins = null;
} elseif ($result['wisp_graphing'] && $result['wisp_graphing']['last_seen_mins_ago'] !== null) {
    $lastOnline = null;
    $lastOnlineMins = $result['wisp_graphing']['last_seen_mins_ago'];
}
$result['last_online'] = $lastOnline;
$result['last_online_mins_ago'] = $lastOnlineMins ?? null;

echo json_encode($result, JSON_PRETTY_PRINT);
