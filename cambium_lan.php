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

// --- Port Forwards ---
$pfOutput = epmp_ssh_exec($ip, $port, 'config show | portForwarding', $airosLogin, $airosPass1, $airosPass2);
$portForwards = $pfOutput ? epmp_parse_port_forwards($pfOutput) : [];

echo json_encode([
    'arp' => $arpEntries,
    'dhcp_hosts' => $dhcpHosts,
    'port_forwards' => $portForwards,
    'source' => 'ssh_cli',
]);
