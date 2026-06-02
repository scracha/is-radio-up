<?php
/**
 * Fetch LAN/ARP/DHCP/Port Forward data from a Cambium ePMP radio via SSH CLI.
 * Used as a fallback when SNMP ARP returns empty.
 * 
 * GET ?ip=x.x.x.x
 * 
 * Returns JSON with arp, dhcp_hosts, port_forwards.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui_lookup.php';
require_once __DIR__ . '/../cambium-ePMP-helpers/cambium_epmp_ssh.php';

header('Content-Type: application/json');
set_time_limit(30);

$ip = trim($_GET['ip'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

$port = $cambiumSshPort ?? EPMP_SSH_PORT;

// --- ARP ---
$arpEntries = epmp_get_arp($ip, $port, $airosLogin, $airosPass1, $airosPass2);
if (empty($arpEntries)) {
    // SSH might have failed entirely
    $testOutput = epmp_ssh_exec($ip, $port, 'show arp', $airosLogin, $airosPass1, $airosPass2);
    if ($testOutput === null) {
        echo json_encode(['error' => 'SSH connection failed to ePMP radio', 'arp' => [], 'dhcp_hosts' => [], 'port_forwards' => []]);
        exit;
    }
}

// Add vendor info
foreach ($arpEntries as &$entry) {
    $entry['vendor'] = oui_lookup($entry['mac']);
}
unset($entry);

// --- DHCP Static Hosts ---
$dhcpOutput = epmp_ssh_exec($ip, $port, 'config show | dhcpLanHost', $airosLogin, $airosPass1, $airosPass2);
$dhcpHosts = $dhcpOutput ? epmp_parse_dhcp_hosts($dhcpOutput) : [];

// --- DHCP Active Leases ---
$leaseOutput = epmp_ssh_exec($ip, $port, 'cat /var/tmp/dhcpd.leases /tmp/dhcpd.leases /tmp/dhcp.leases /tmp/dnsmasq.leases 2>/dev/null', $airosLogin, $airosPass1, $airosPass2);
$dhcpLeases = [];
if ($leaseOutput) {
    // Try dnsmasq format: <expiry> <mac> <ip> <hostname> <client_id>
    foreach (explode("\n", trim($leaseOutput)) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) >= 4 && is_numeric($fields[0]) && filter_var($fields[2], FILTER_VALIDATE_IP)) {
            $mac = strtoupper($fields[1]);
            $leaseIp = $fields[2];
            $hostname = ($fields[3] !== '*') ? $fields[3] : '';
            $vendor = oui_lookup($mac);
            $inArp = false;
            foreach ($arpEntries as $arp) {
                if ($arp['ip'] === $leaseIp || strtoupper($arp['mac']) === $mac) { $inArp = true; break; }
            }
            $dhcpLeases[] = ['ip' => $leaseIp, 'mac' => $mac, 'hostname' => $hostname, 'vendor' => $vendor, 'in_arp' => $inArp];
        }
    }
    // Try ISC dhcpd format if dnsmasq format yielded nothing
    if (empty($dhcpLeases) && strpos($leaseOutput, 'lease ') !== false) {
        preg_match_all('/lease\s+([\d.]+)\s*\{([^}]+)\}/s', $leaseOutput, $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            $leaseIp = $block[1];
            $body = $block[2];
            $mac = ''; $hostname = '';
            if (preg_match('/hardware\s+ethernet\s+([0-9a-fA-F:]+)/', $body, $m)) $mac = strtoupper($m[1]);
            if (preg_match('/client-hostname\s+"([^"]*)"/', $body, $m)) $hostname = $m[1];
            if ($mac) {
                $vendor = oui_lookup($mac);
                $inArp = false;
                foreach ($arpEntries as $arp) {
                    if ($arp['ip'] === $leaseIp || strtoupper($arp['mac']) === $mac) { $inArp = true; break; }
                }
                $dhcpLeases[] = ['ip' => $leaseIp, 'mac' => $mac, 'hostname' => $hostname, 'vendor' => $vendor, 'in_arp' => $inArp];
            }
        }
    }
}

// --- Port Forwards ---
$pfOutput = epmp_ssh_exec($ip, $port, 'config show | portForwarding', $airosLogin, $airosPass1, $airosPass2);
$portForwards = $pfOutput ? epmp_parse_port_forwards($pfOutput) : [];

echo json_encode([
    'arp' => $arpEntries,
    'dhcp_hosts' => $dhcpHosts,
    'dhcp_leases' => $dhcpLeases,
    'port_forwards' => $portForwards,
    'source' => 'ssh_cli',
]);
