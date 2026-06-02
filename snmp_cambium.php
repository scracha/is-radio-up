<?php
/**
 * SNMP query for Cambium ePMP radios.
 * GET ?ip=x.x.x.x&community=public
 * 
 * Returns LAN status, ARP table, and system info.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui_lookup.php';
header('Content-Type: application/json');
set_time_limit(30);

$ip = trim($_GET['ip'] ?? '');
$community = trim($_GET['community'] ?? 'public');

if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

$result = ['lan' => [], 'arp' => [], 'snmp_ok' => false];

// Helper to clean SNMP values
function cleanSnmp($val) {
    if ($val === false || $val === null) return null;
    $val = preg_replace('/^(STRING|INTEGER|Gauge32|Counter32|Counter64|Timeticks|OID|IpAddress):\s*/i', '', $val);
    return trim($val, '" ');
}

// Quick connectivity test — try sysName
$sysName = @snmpget($ip, $community, '1.3.6.1.2.1.1.5.0', 1000000, 1);
if ($sysName === false) {
    echo json_encode([
        'snmp_ok' => false,
        'error'   => 'SNMP query failed — check community string in WISP-graphing',
        'lan'     => [],
        'arp'     => [],
    ]);
    exit;
}
$result['snmp_ok'] = true;

// Walk IF-MIB to find ethernet interfaces and their status
$ifDescr = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.2', 1000000, 1) ?: [];
$ifSpeed = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.5', 1000000, 1) ?: [];
$ifStatus = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.8', 1000000, 1) ?: [];

$interfaces = [];
foreach ($ifDescr as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    $idx = $m[1] ?? null;
    if (!$idx) continue;
    $name = cleanSnmp($val);

    // Find speed — try all possible OID key formats (iso vs numeric, with/without leading dot)
    $speed = null;
    $speedPatterns = [
        "iso.3.6.1.2.1.2.2.1.5.{$idx}",
        "1.3.6.1.2.1.2.2.1.5.{$idx}",
        ".1.3.6.1.2.1.2.2.1.5.{$idx}",
    ];
    foreach ($speedPatterns as $k) {
        if (isset($ifSpeed[$k])) { $speed = (int)cleanSnmp($ifSpeed[$k]); break; }
    }
    // Convert bps to Mbps
    if ($speed && $speed > 1000) $speed = round($speed / 1000000);

    // Find status
    $status = null;
    $statusPatterns = [
        "iso.3.6.1.2.1.2.2.1.8.{$idx}",
        "1.3.6.1.2.1.2.2.1.8.{$idx}",
        ".1.3.6.1.2.1.2.2.1.8.{$idx}",
    ];
    foreach ($statusPatterns as $k) {
        if (isset($ifStatus[$k])) { $status = (int)cleanSnmp($ifStatus[$k]); break; }
    }

    $nameLower = strtolower($name);
    if (strpos($nameLower, 'lo') === 0) continue;

    $interfaces[] = [
        'index'  => $idx,
        'name'   => $name,
        'speed'  => $speed ? "{$speed} Mbps" : 'Unknown',
        'status' => $status === 1 ? 'UP' : ($status === 2 ? 'DOWN' : 'Unknown'),
    ];
}

// Also try Cambium-specific LAN OIDs
$cambiumLanSpeed = cleanSnmp(@snmpget($ip, $community, '1.3.6.1.4.1.17713.21.1.4.12.0', 1000000, 1)); // actual Mbps
$cambiumLanStatus = cleanSnmp(@snmpget($ip, $community, '1.3.6.1.4.1.17713.21.1.4.10.0', 1000000, 1));

if ($cambiumLanSpeed !== null || $cambiumLanStatus !== null) {
    $result['lan']['cambium_speed'] = $cambiumLanSpeed;
    $result['lan']['cambium_status'] = $cambiumLanStatus;
}

// Fetch DHCP leases via SNMP (Cambium dhcpServerLeaseTable)
// OIDs: .1.3.6.1.4.1.17713.21.1.7.6.1.2 = MAC, .3 = IP, .4 = DeviceName
$dhcpLeaseMacs = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.7.6.1.2', 1000000, 1) ?: [];
$dhcpLeaseIPs = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.7.6.1.3', 1000000, 1) ?: [];

$dhcpLeaseMap = []; // mac => ['hostname' => ..., 'ip' => ...]
$leaseMacs = [];
foreach ($dhcpLeaseMacs as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    if (isset($m[1])) $leaseMacs[$m[1]] = strtoupper(str_replace([' ', '-'], ':', trim(cleanSnmp($val))));
}
$leaseIPs = [];
foreach ($dhcpLeaseIPs as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    if (isset($m[1])) $leaseIPs[$m[1]] = cleanSnmp($val);
}

// Fetch device names individually by index (snmpwalk on .4 triggers genError on some firmware)
$leaseNames = [];
foreach (array_keys($leaseMacs) as $idx) {
    $nameVal = @snmpget($ip, $community, "1.3.6.1.4.1.17713.21.1.7.6.1.4.{$idx}", 1000000, 1);
    if ($nameVal !== false) {
        $leaseNames[$idx] = cleanSnmp($nameVal);
    }
}

foreach ($leaseMacs as $idx => $mac) {
    if ($mac && strlen($mac) >= 11) {
        $dhcpLeaseMap[$mac] = [
            'hostname' => $leaseNames[$idx] ?? '',
            'ip' => $leaseIPs[$idx] ?? '',
        ];
    }
}

// Pick the best ethernet interface to report
$ethIf = null;
foreach ($interfaces as $iface) {
    $n = strtolower($iface['name']);
    if (strpos($n, 'eth') !== false || strpos($n, 'lan') !== false) {
        $ethIf = $iface;
        break;
    }
}
if (!$ethIf && !empty($interfaces)) {
    $ethIf = $interfaces[0];
}

if ($ethIf) {
    $result['lan']['interface'] = $ethIf['name'];
    $result['lan']['lan_speed_formatted'] = $ethIf['speed'];
    $result['lan']['lan_status_formatted'] = $ethIf['status'];
} elseif ($cambiumLanSpeed !== null) {
    $speedVal = (int)$cambiumLanSpeed;
    $result['lan']['lan_speed_formatted'] = $speedVal > 0 ? "{$speedVal} Mbps" : 'Auto';
    $result['lan']['lan_status_formatted'] = $cambiumLanStatus !== null ? ((int)$cambiumLanStatus === 1 ? 'UP' : 'DOWN') : 'Unknown';
}

$result['lan']['all_interfaces'] = $interfaces;

// Get system uptime (timeticks = hundredths of a second)
$sysUptime = cleanSnmp(@snmpget($ip, $community, '1.3.6.1.2.1.1.3.0', 1000000, 1));
if ($sysUptime !== null) {
    // Parse timeticks — format might be "(12345678)" or just "12345678"
    preg_match('/(\d+)/', $sysUptime, $m);
    $ticks = (int)($m[1] ?? 0);
    $result['uptime_seconds'] = round($ticks / 100);
}

// Walk Cambium-specific ARP table
$arpEntries = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.4.22.1.2', 1000000, 1);
if ($arpEntries && is_array($arpEntries)) {
    foreach ($arpEntries as $oid => $val) {
        if (preg_match('/\.(\d+\.\d+\.\d+\.\d+)$/', $oid, $m)) {
            $arpIp = $m[1];
            $mac = strtoupper(trim(cleanSnmp($val)));
            $mac = str_replace(' ', ':', $mac);
            if (strlen($mac) >= 11) {
                $result['arp'][] = [
                    'ip'     => $arpIp,
                    'mac'    => $mac,
                    'vendor' => oui_lookup($mac),
                ];
            }
        }
    }
}

// Also try atPhysAddress (older ARP MIB)
if (empty($result['arp'])) {
    $atEntries = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.3.1.1.2', 1000000, 1);
    if ($atEntries && is_array($atEntries)) {
        foreach ($atEntries as $oid => $val) {
            if (preg_match('/\.(\d+\.\d+\.\d+\.\d+)$/', $oid, $m)) {
                $arpIp = $m[1];
                $mac = strtoupper(trim(cleanSnmp($val)));
                $mac = str_replace(' ', ':', $mac);
                if (strlen($mac) >= 11) {
                    $result['arp'][] = [
                        'ip'     => $arpIp,
                        'mac'    => $mac,
                        'vendor' => oui_lookup($mac),
                    ];
                }
            }
        }
    }
}

// Cambium-specific ARP/NAT table (always try — standard MIBs don't work on Cambium)
$cambiumMacs = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.4.20.1.2', 1000000, 1) ?: [];
$cambiumIPs = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.4.20.1.3', 1000000, 1) ?: [];
$cambiumIfaces = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.4.20.1.4', 1000000, 1) ?: [];

$macs = [];
foreach ($cambiumMacs as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    if (isset($m[1])) $macs[$m[1]] = strtoupper(trim(cleanSnmp($val)));
}
$ips = [];
foreach ($cambiumIPs as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    if (isset($m[1])) $ips[$m[1]] = cleanSnmp($val);
}
$ifaces = [];
foreach ($cambiumIfaces as $oid => $val) {
    preg_match('/\.(\d+)$/', $oid, $m);
    if (isset($m[1])) $ifaces[$m[1]] = cleanSnmp($val);
}

foreach ($macs as $idx => $mac) {
    $mac = str_replace([' ', '-'], ':', $mac);
    $arpIp = $ips[$idx] ?? null;
    if ($arpIp && $mac && strlen($mac) >= 11) {
        $result['arp'][] = [
            'ip'        => $arpIp,
            'mac'       => $mac,
            'vendor'    => oui_lookup($mac),
            'interface' => $ifaces[$idx] ?? '',
        ];
    }
}

// Merge DHCP hostnames into ARP entries
$arpMacs = [];
foreach ($result['arp'] as &$arpEntry) {
    $mac = strtoupper(str_replace([' ', '-'], ':', $arpEntry['mac']));
    $arpMacs[] = $mac;
    if (isset($dhcpLeaseMap[$mac])) {
        $arpEntry['hostname'] = $dhcpLeaseMap[$mac]['hostname'];
    }
}
unset($arpEntry);

// Add DHCP leases not in ARP table
$result['dhcp_leases'] = [];
foreach ($dhcpLeaseMap as $mac => $lease) {
    if (!in_array($mac, $arpMacs)) {
        $result['dhcp_leases'][] = [
            'ip' => $lease['ip'],
            'mac' => $mac,
            'hostname' => $lease['hostname'],
            'vendor' => oui_lookup($mac),
            'in_arp' => false,
        ];
    }
}

echo json_encode($result);
