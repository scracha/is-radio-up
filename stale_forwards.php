<?php
/**
 * Stale Port Forward Detection & Cleanup
 * 
 * Supports both AirOS (Ubiquiti) and ePMP (Cambium) radios.
 * For each port forward, checks if the LAN target IP is reachable.
 * On AirOS, stale entries can be deleted. On ePMP, user must delete via web UI.
 * 
 * GET  ?ip=x.x.x.x&action=check                — Check for stale port forwards
 * POST ?ip=x.x.x.x&action=delete&radio_type=X   — Delete specific port forward(s)
 * 
 * radio_type: "airos" or "epmp" (auto-detected if not specified)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui_lookup.php';
require_once __DIR__ . '/../cambium-ePMP-helpers/cambium_epmp_ssh.php';
require_once __DIR__ . '/../ubiquiti-airos-helpers/airos_ssh.php';

header('Content-Type: application/json');
set_time_limit(60);

$radioIp = trim($_GET['ip'] ?? $_POST['ip'] ?? '');
$action = trim($_GET['action'] ?? $_POST['action'] ?? 'check');
$radioType = trim($_GET['radio_type'] ?? $_POST['radio_type'] ?? '');

if (!filter_var($radioIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

// Auto-detect radio type if not specified
if (empty($radioType)) {
    $radioType = detectRadioType($radioIp);
}

switch ($action) {
    case 'check':
        handleCheckStale($radioIp, $radioType);
        break;
    case 'delete':
        handleDeleteForwards($radioIp, $radioType);
        break;
    default:
        echo json_encode(['error' => 'Invalid action. Use: check, delete']);
        exit;
}

/**
 * Detect radio type by trying AirOS first (read system.cfg), then ePMP.
 */
function detectRadioType(string $radioIp): string {
    global $airosLogin, $airosPass1, $airosPass2, $airosDefaultPort, $cambiumSshPort;

    // Try AirOS (port 22, read system.cfg — AirOS has key=value format)
    $airosPort = $airosDefaultPort ?? 22;
    $output = airos_ssh_exec($radioIp, $airosPort, 'cat /tmp/system.cfg | head -5', $airosLogin, $airosPass1, $airosPass2 ?? '');
    if ($output !== null && preg_match('/^\w+[\.\w]+=/', $output)) {
        return 'airos';
    }

    // Try ePMP (port 8022 — ePMP has its own CLI)
    $epmpPort = $cambiumSshPort ?? EPMP_SSH_PORT;
    if ($epmpPort != $airosPort) {
        $output = epmp_ssh_exec($radioIp, $epmpPort, 'show arp', $airosLogin, $airosPass1, $airosPass2 ?? '');
        if ($output !== null) {
            return 'epmp';
        }
    }

    // If same port, distinguish by trying ePMP-specific command
    $output = epmp_ssh_exec($radioIp, $airosPort, 'config show | swVersion', $airosLogin, $airosPass1, $airosPass2 ?? '');
    if ($output !== null && strpos($output, 'swVersion') !== false) {
        return 'epmp';
    }

    return 'unknown';
}

/**
 * Check all port forwards and identify stale ones.
 */
function handleCheckStale(string $radioIp, string $radioType): void {
    if ($radioType === 'airos') {
        handleCheckStaleAiros($radioIp);
    } elseif ($radioType === 'epmp') {
        handleCheckStaleEpmp($radioIp);
    } else {
        echo json_encode(['error' => 'Could not detect radio type (SSH failed)', 'radio_type' => $radioType]);
    }
}

/**
 * Check stale forwards on AirOS radio.
 */
function handleCheckStaleAiros(string $radioIp): void {
    global $airosLogin, $airosPass1, $airosPass2, $airosDefaultPort;

    $port = $airosDefaultPort ?? 22;

    // Read config and ARP
    $configOutput = airos_read_config($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '');
    if ($configOutput === null) {
        echo json_encode(['error' => 'Cannot SSH to AirOS radio']);
        return;
    }

    $arpOutput = airos_ssh_exec($radioIp, $port, 'cat /proc/net/arp', $airosLogin, $airosPass1, $airosPass2 ?? '');
    $arpIps = [];
    if ($arpOutput) {
        foreach (explode("\n", trim($arpOutput)) as $line) {
            if (strpos($line, 'IP address') !== false) continue;
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) >= 4 && filter_var($fields[0], FILTER_VALIDATE_IP)) {
                $arpIps[] = $fields[0];
            }
        }
    }

    // Parse port forwards
    $portForwards = airos_parse_port_forwards($configOutput);
    if (empty($portForwards)) {
        echo json_encode(['stale_forwards' => [], 'all_forwards' => [], 'message' => 'No port forwards configured', 'radio_type' => 'airos']);
        return;
    }

    // Group by host IP
    $ipForwards = [];
    foreach ($portForwards as $idx => $fwd) {
        if (strtolower($fwd['status'] ?? '') !== 'enabled') continue;
        $lanIp = $fwd['host'] ?? '';
        if (!$lanIp) continue;
        if (!isset($ipForwards[$lanIp])) $ipForwards[$lanIp] = [];
        $fwd['index'] = $idx;
        $ipForwards[$lanIp][] = $fwd;
    }

    // Check each unique LAN IP
    $staleForwards = [];
    $checkedIps = [];
    $safeIps = ['192.168.0.1', '192.168.0.2', '169.254.1.1'];

    foreach ($ipForwards as $lanIp => $forwards) {
        if (in_array($lanIp, $safeIps)) { $checkedIps[$lanIp] = 'safe_ip'; continue; }

        $inArp = in_array($lanIp, $arpIps);
        if (!$inArp) {
            // Ping from radio
            $pingOutput = airos_ssh_exec($radioIp, $port, "ping -c 2 -W 2 {$lanIp}", $airosLogin, $airosPass1, $airosPass2 ?? '');
            $pingSuccess = $pingOutput !== null && preg_match('/(\d+)\s+packets?\s+received/', $pingOutput, $m) && (int)$m[1] > 0;

            if (!$pingSuccess) {
                foreach ($forwards as $fwd) {
                    $staleForwards[] = [
                        'index' => $fwd['index'],
                        'lan_ip' => $lanIp,
                        'wan_port_begin' => (int)($fwd['dport'] ?? 0),
                        'lan_port' => (int)($fwd['port'] ?? $fwd['dport'] ?? 0),
                        'protocol' => strtoupper($fwd['proto'] ?? 'TCP') === 'TCP' ? 2 : (strtoupper($fwd['proto'] ?? '') === 'UDP' ? 1 : 3),
                        'comment' => $fwd['comment'] ?? '',
                    ];
                }
            }
            $checkedIps[$lanIp] = $pingSuccess ? 'ping_ok' : 'unreachable';
        } else {
            $checkedIps[$lanIp] = 'in_arp';
        }
    }

    echo json_encode([
        'stale_forwards' => $staleForwards,
        'all_forwards' => $portForwards,
        'checked_ips' => $checkedIps,
        'total_forwards' => count($portForwards),
        'stale_count' => count($staleForwards),
        'radio_type' => 'airos',
        'can_delete' => true,
    ]);
}

/**
 * Check stale forwards on ePMP radio.
 */
function handleCheckStaleEpmp(string $radioIp): void {
    global $cambiumSshPort, $airosLogin, $airosPass1, $airosPass2;

    $port = $cambiumSshPort ?? EPMP_SSH_PORT;

    $arpEntries = epmp_get_arp($radioIp, $port, $airosLogin, $airosPass1, $airosPass2);
    $arpIps = array_map(fn($e) => $e['ip'], $arpEntries);

    $pfOutput = epmp_ssh_exec($radioIp, $port, 'config show | portForwarding', $airosLogin, $airosPass1, $airosPass2);
    $portForwards = $pfOutput ? epmp_parse_port_forwards($pfOutput) : [];

    if (empty($portForwards)) {
        echo json_encode(['stale_forwards' => [], 'all_forwards' => [], 'message' => 'No port forwards configured', 'radio_type' => 'epmp']);
        return;
    }

    $ipForwards = [];
    foreach ($portForwards as $idx => $fwd) {
        $lanIp = $fwd['lan_ip'] ?? '';
        if (!$lanIp) continue;
        if (!isset($ipForwards[$lanIp])) $ipForwards[$lanIp] = [];
        $fwd['index'] = $idx;
        $ipForwards[$lanIp][] = $fwd;
    }

    $staleForwards = [];
    $checkedIps = [];
    $safeIps = ['192.168.0.1', '192.168.0.2', '169.254.1.1'];

    foreach ($ipForwards as $lanIp => $forwards) {
        if (in_array($lanIp, $safeIps)) { $checkedIps[$lanIp] = 'safe_ip'; continue; }

        $inArp = in_array($lanIp, $arpIps);
        if (!$inArp) {
            $pingOutput = epmp_ssh_exec($radioIp, $port, "ping -c 2 -W 2 {$lanIp}", $airosLogin, $airosPass1, $airosPass2);
            $pingSuccess = $pingOutput !== null && preg_match('/(\d+)\s+packets?\s+received/', $pingOutput, $m) && (int)$m[1] > 0;

            if (!$pingSuccess) {
                foreach ($forwards as $fwd) {
                    $fwd['lan_ip_status'] = 'unreachable';
                    $staleForwards[] = $fwd;
                }
            }
            $checkedIps[$lanIp] = $pingSuccess ? 'ping_ok' : 'unreachable';
        } else {
            $checkedIps[$lanIp] = 'in_arp';
        }
    }

    echo json_encode([
        'stale_forwards' => $staleForwards,
        'all_forwards' => $portForwards,
        'checked_ips' => $checkedIps,
        'total_forwards' => count($portForwards),
        'stale_count' => count($staleForwards),
        'radio_type' => 'epmp',
        'can_delete' => true,
    ]);
}

/**
 * Delete port forwards.
 */
function handleDeleteForwards(string $radioIp, string $radioType): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $deleteIndices = $body['indices'] ?? [];

    if (empty($deleteIndices)) {
        echo json_encode(['error' => 'No indices specified for deletion']);
        return;
    }

    if ($radioType === 'airos') {
        handleDeleteAiros($radioIp, $deleteIndices);
    } elseif ($radioType === 'epmp') {
        handleDeleteEpmp($radioIp, $deleteIndices);
    } else {
        echo json_encode(['error' => 'Unknown radio type']);
    }
}

/**
 * Delete port forwards on ePMP by clearing table entries.
 * Uses: config set portForwardingTable N " " followed by config commit
 */
function handleDeleteEpmp(string $radioIp, array $deleteIndices): void {
    global $cambiumSshPort, $airosLogin, $airosPass1, $airosPass2;

    $port = $cambiumSshPort ?? EPMP_SSH_PORT;

    // Build commands — clear each entry then commit
    $commands = [];
    foreach ($deleteIndices as $idx) {
        $tableIdx = $idx + 1; // ePMP table is 1-indexed
        $commands[] = "config set portForwardingTable {$tableIdx} \" \"";
    }
    $commands[] = "config commit";

    // All commands must run in a single SSH session
    $output = epmp_ssh_multi($radioIp, $port, $commands, $airosLogin, $airosPass1, $airosPass2);

    if ($output === null) {
        echo json_encode(['error' => 'SSH session failed during port forward deletion.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'deleted_count' => count($deleteIndices),
        'message' => "Deleted " . count($deleteIndices) . " port forward(s).",
    ]);
}

/**
 * Delete port forwards on AirOS by removing lines from system.cfg.
 */
function handleDeleteAiros(string $radioIp, array $deleteIndices): void {
    global $airosLogin, $airosPass1, $airosPass2, $airosDefaultPort;

    $port = $airosDefaultPort ?? 8022;

    // Backup first
    airos_ssh_exec($radioIp, $port, 'cp /tmp/system.cfg /tmp/system.cfg.bak', $airosLogin, $airosPass1, $airosPass2 ?? '');

    // Remove port forward lines for each index using sed in-place
    foreach ($deleteIndices as $idx) {
        $escapedPattern = 'iptables\\.sys\\.portfw\\.' . $idx . '\\.';
        $sedCmd = "sed -i '/^{$escapedPattern}/d' /tmp/system.cfg";
        airos_ssh_exec($radioIp, $port, $sedCmd, $airosLogin, $airosPass1, $airosPass2 ?? '');
    }

    // Save to flash
    airos_ssh_exec($radioIp, $port, 'cfgmtd -w -p /etc/', $airosLogin, $airosPass1, $airosPass2 ?? '');

    // Apply (soft restart to reload iptables)
    airos_ssh_exec($radioIp, $port, '/usr/etc/rc.d/rc.softrestart save 2>/dev/null &', $airosLogin, $airosPass1, $airosPass2 ?? '');

    echo json_encode([
        'success' => true,
        'deleted_count' => count($deleteIndices),
        'message' => "Deleted " . count($deleteIndices) . " port forward(s). Radio applying changes...",
    ]);
}
