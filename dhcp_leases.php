<?php
/**
 * Fetch DHCP leases from an AirOS radio via SSH.
 * GET ?ip=x.x.x.x&ssh_port=22
 * 
 * Uses sshpass with two password attempts (same as airos-to-dhcp).
 * Returns JSON array of leases.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui_lookup.php';
header('Content-Type: application/json');
set_time_limit(30);

$ip = trim($_GET['ip'] ?? '');
$sshPort = (int)($_GET['ssh_port'] ?? $airosDefaultPort);

if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

$sshOpts = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o LogLevel=ERROR"
    . " -o KexAlgorithms=+diffie-hellman-group1-sha1,diffie-hellman-group14-sha1"
    . " -o HostKeyAlgorithms=+ssh-rsa,ssh-dss"
    . " -o Ciphers=+aes128-cbc,aes256-cbc,3des-cbc";

$escapedIp = escapeshellarg($ip);
$escapedLogin = escapeshellarg($airosLogin);
$cmd = "cat /var/tmp/dhcpd.leases /tmp/dhcpd.leases /tmp/dhcp.leases /tmp/dnsmasq.leases 2>/dev/null; echo '---SEPARATOR---'; cat /proc/net/arp 2>/dev/null";

// Try password 1
$escapedPass = escapeshellarg($airosPass1);
$fullCmd = "sshpass -p {$escapedPass} ssh {$sshOpts} -p {$sshPort} {$escapedLogin}@{$escapedIp} " . escapeshellarg($cmd) . " 2>&1";
$output = shell_exec($fullCmd) ?? '';

// Check if auth failed, try password 2
if ((strpos($output, 'Permission denied') !== false || strpos($output, 'Authentication') !== false) && !empty($airosPass2)) {
    $escapedPass2 = escapeshellarg($airosPass2);
    $fullCmd = "sshpass -p {$escapedPass2} ssh {$sshOpts} -p {$sshPort} {$escapedLogin}@{$escapedIp} " . escapeshellarg($cmd) . " 2>&1";
    $output = shell_exec($fullCmd) ?? '';
}

// Check for errors
if (strpos($output, 'Permission denied') !== false) {
    echo json_encode(['error' => 'SSH auth failed (both passwords)', 'leases' => [], 'arp' => []]); exit;
}
if (strpos($output, 'Connection refused') !== false || strpos($output, 'Connection timed out') !== false) {
    echo json_encode(['error' => 'SSH connection failed', 'leases' => [], 'arp' => []]); exit;
}

// Parse output
$parts = explode('---SEPARATOR---', $output);
$arpLines = array_filter(explode("\n", trim($parts[1] ?? '')));

// Parse DHCP leases — handle both dnsmasq and dhcpd formats
$leases = [];
$leaseText = trim($parts[0] ?? '');

if (strpos($leaseText, 'lease ') !== false) {
    // ISC dhcpd format: multi-line blocks
    preg_match_all('/lease\s+([\d.]+)\s*\{([^}]+)\}/s', $leaseText, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $block) {
        $ip = $block[1];
        $body = $block[2];
        $mac = ''; $hostname = ''; $expiry = '';
        if (preg_match('/hardware\s+ethernet\s+([0-9a-fA-F:]+)/', $body, $m)) $mac = $m[1];
        if (preg_match('/client-hostname\s+"([^"]*)"/', $body, $m)) $hostname = $m[1];
        if (preg_match('/ends\s+\d+\s+([\d\/: ]+)/', $body, $m)) $expiry = trim($m[1]);
        if (!isset($leases[$ip])) {
            $leases[$ip] = ['ip' => $ip, 'mac' => $mac, 'hostname' => $hostname, 'expiry' => $expiry ?: 'active'];
        }
    }
    $leases = array_values($leases);
} else {
    // dnsmasq format: <expiry> <mac> <ip> <hostname> <client_id>
    foreach (array_filter(explode("\n", $leaseText)) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) >= 4 && is_numeric($fields[0])) {
            $expiry = (int)$fields[0];
            $leases[] = [
                'mac'      => $fields[1],
                'ip'       => $fields[2],
                'hostname' => $fields[3] !== '*' ? $fields[3] : '',
                'expiry'   => $expiry > 0 ? date('Y-m-d H:i:s', $expiry) : 'static',
            ];
        }
    }
}

// Parse ARP table (skip header): IP HW_type Flags MAC Mask Device
$arp = [];
foreach ($arpLines as $line) {
    if (strpos($line, 'IP address') !== false) continue; // skip header
    $fields = preg_split('/\s+/', trim($line));
    if (count($fields) >= 4 && filter_var($fields[0], FILTER_VALIDATE_IP)) {
        $arp[] = [
            'ip'     => $fields[0],
            'mac'    => $fields[3],
            'device' => $fields[5] ?? '',
        ];
    }
}

// Add vendor info from OUI lookup
$seen = [];
$dedupedLeases = [];
foreach ($leases as &$l) {
    $l['vendor'] = !empty($l['mac']) ? oui_lookup($l['mac']) : '';
    $key = $l['ip'] . '|' . strtolower($l['mac'] ?? '');
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $dedupedLeases[] = $l;
    }
}
$leases = $dedupedLeases;
foreach ($arp as &$a) {
    $a['vendor'] = !empty($a['mac']) ? oui_lookup($a['mac']) : '';
}
unset($l, $a);

echo json_encode(['leases' => $leases, 'arp' => $arp]);
