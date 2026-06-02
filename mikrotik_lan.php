<?php
/**
 * Mikrotik (Routerboard) LAN/ARP/DHCP/Port Forward detection and management.
 * 
 * Supports both AirOS (Ubiquiti) and ePMP (Cambium) radios.
 * 
 * When a Routerboard device is detected on the radio's LAN:
 * 1. Check ARP for the default Mikrotik IP (192.168.5.2)
 * 2. If not in ARP, check DHCP leases for "routerboard" vendor/hostname
 * 3. Ensure port forwards exist:
 *    - WAN TCP/8291 -> LAN TCP/8291 (Winbox)
 *    - WAN TCP/2022 -> LAN TCP/22 (SSH, restricted to management IP)
 * 4. Ensure static DHCP lease exists for the Mikrotik MAC
 * 
 * GET  ?ip=x.x.x.x&action=check&radio_type=airos|epmp
 * POST ?ip=x.x.x.x&action=setup&radio_type=airos|epmp
 * POST ?ip=x.x.x.x&action=btest
 * 
 * The radio IP is the Cambium/AirOS radio that the Mikrotik sits behind.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/oui_lookup.php';
require_once __DIR__ . '/../mikrotik-helpers/mikrotik_ssh.php';
require_once __DIR__ . '/../cambium-ePMP-helpers/cambium_epmp_ssh.php';
require_once __DIR__ . '/../ubiquiti-airos-helpers/airos_ssh.php';

header('Content-Type: application/json');
set_time_limit(180);  // btest can take 60+ seconds plus SSH connection time

$radioIp = trim($_GET['ip'] ?? $_POST['ip'] ?? '');
$action = trim($_GET['action'] ?? $_POST['action'] ?? 'check');
$radioType = trim($_GET['radio_type'] ?? $_POST['radio_type'] ?? '');

if (!filter_var($radioIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    echo json_encode(['error' => 'Invalid IP']); exit;
}

// Mikrotik config from config.php
$mikrotikDefaultIp = $mikrotikConfig['default_ip'] ?? '192.168.5.2';
$mikrotikSshPort = $mikrotikConfig['ssh_port'] ?? 22;
$mikrotikLogin = $mikrotikConfig['login'] ?? 'admin';
$mikrotikPass = $mikrotikConfig['password'] ?? '';
$mikrotikWinboxPort = $mikrotikConfig['winbox_wan_port'] ?? 8291;
$mikrotikSshWanPort = $mikrotikConfig['ssh_wan_port'] ?? 2022;
$mikrotikSshLanPort = $mikrotikConfig['ssh_lan_port'] ?? 22;
$mikrotikMgmtSrcIp = $mikrotikConfig['mgmt_src_ip'] ?? '202.174.171.50';
$btestServer = $mikrotikConfig['btest_server'] ?? '202.174.160.2';
$btestUser = $mikrotikConfig['btest_user'] ?? 'btest';
$btestPass = $mikrotikConfig['btest_password'] ?? '';
$btestDuration = $mikrotikConfig['btest_duration'] ?? 30;

switch ($action) {
    case 'check':
        handleCheck($radioIp, $radioType);
        break;
    case 'setup':
        handleSetup($radioIp, $radioType);
        break;
    case 'btest':
        handleBtest($radioIp);
        break;
    default:
        echo json_encode(['error' => 'Invalid action. Use: check, setup, btest']);
        exit;
}

/**
 * Check for Mikrotik presence and port forward status.
 */
function handleCheck(string $radioIp, string $radioType): void {
    if ($radioType === 'airos') {
        handleCheckAiros($radioIp);
    } else {
        handleCheckEpmp($radioIp);
    }
}

/**
 * Check for Mikrotik on AirOS radio.
 */
function handleCheckAiros(string $radioIp): void {
    global $mikrotikDefaultIp, $mikrotikWinboxPort, $mikrotikSshWanPort, $mikrotikSshLanPort,
           $airosLogin, $airosPass1, $airosPass2, $airosDefaultPort;

    $port = $airosDefaultPort ?? 8022;

    $result = [
        'mikrotik_found' => false,
        'mikrotik_ip' => null,
        'mikrotik_mac' => null,
        'detection_method' => null,
        'port_forwards' => [],
        'has_winbox_forward' => false,
        'has_ssh_forward' => false,
        'has_static_lease' => false,
        'radio_ip' => $radioIp,
        'radio_type' => 'airos',
    ];

    // Read config and ARP from AirOS radio
    $configOutput = airos_read_config($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '');
    if ($configOutput === null) {
        echo json_encode(['error' => 'Cannot SSH to AirOS radio', 'radio_type' => 'airos']);
        return;
    }

    $arpOutput = airos_ssh_exec($radioIp, $port, 'cat /proc/net/arp', $airosLogin, $airosPass1, $airosPass2 ?? '');
    $arpEntries = [];
    if ($arpOutput) {
        foreach (explode("\n", trim($arpOutput)) as $line) {
            if (strpos($line, 'IP address') !== false) continue;
            $fields = preg_split('/\s+/', trim($line));
            if (count($fields) >= 4 && filter_var($fields[0], FILTER_VALIDATE_IP)) {
                $ip = $fields[0];
                // Only consider private LAN IPs
                if (!preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $ip)) continue;
                $arpEntries[] = ['ip' => $ip, 'mac' => strtoupper($fields[3])];
            }
        }
    }

    // Check ARP for default Mikrotik IP
    foreach ($arpEntries as $entry) {
        if ($entry['ip'] === $mikrotikDefaultIp) {
            $result['mikrotik_found'] = true;
            $result['mikrotik_ip'] = $entry['ip'];
            $result['mikrotik_mac'] = $entry['mac'];
            $result['detection_method'] = 'arp';
            break;
        }
    }

    // Check by vendor
    if (!$result['mikrotik_found']) {
        foreach ($arpEntries as $entry) {
            $vendor = oui_lookup($entry['mac']);
            if ($vendor && (stripos($vendor, 'routerboard') !== false || stripos($vendor, 'mikrotik') !== false)) {
                $result['mikrotik_found'] = true;
                $result['mikrotik_ip'] = $entry['ip'];
                $result['mikrotik_mac'] = $entry['mac'];
                $result['detection_method'] = 'arp_vendor';
                break;
            }
        }
    }

    if (!$result['mikrotik_found']) {
        echo json_encode($result);
        return;
    }

    // Check port forwards in system.cfg
    $portForwards = airos_parse_port_forwards($configOutput);
    foreach ($portForwards as $pf) {
        if (strtolower($pf['status'] ?? '') !== 'enabled') continue;
        $host = $pf['host'] ?? '';
        $dport = (int)($pf['dport'] ?? 0);
        $lanPort = (int)($pf['port'] ?? 0);

        if ($host === $result['mikrotik_ip']) {
            // Winbox: any forward to LAN port 8291
            if ($lanPort == 8291) {
                $result['has_winbox_forward'] = true;
                $result['winbox_wan_port'] = $dport;
            }
            // SSH: any forward to LAN port 22
            if ($lanPort == $mikrotikSshLanPort) {
                $result['has_ssh_forward'] = true;
                $result['ssh_wan_port'] = $dport;
            }
        }
    }

    // Check static DHCP lease
    if ($result['mikrotik_ip'] === $mikrotikDefaultIp) {
        $result['has_static_lease'] = true;  // Static IP on Mikrotik, no DHCP needed
    } elseif ($result['mikrotik_mac']) {
        $result['has_static_lease'] = airos_has_static_lease($configOutput, $result['mikrotik_mac']);
    }

    echo json_encode($result);
}

/**
 * Check for Mikrotik on ePMP radio.
 */
function handleCheckEpmp(string $radioIp): void {
    global $mikrotikDefaultIp, $cambiumSshPort, $airosLogin, $airosPass1, $airosPass2;

    $result = [
        'mikrotik_found' => false,
        'mikrotik_ip' => null,
        'mikrotik_mac' => null,
        'detection_method' => null,
        'port_forwards' => [],
        'has_winbox_forward' => false,
        'has_ssh_forward' => false,
        'has_static_lease' => false,
        'radio_ip' => $radioIp,
    ];

    // Step 1: Get ARP table from the radio (LAN-side only)
    $port = $cambiumSshPort ?? EPMP_SSH_PORT;
    $arpEntries = epmp_get_arp($radioIp, $port, $airosLogin, $airosPass1, $airosPass2);

    // Filter to LAN-side entries only (private IPs: 192.168.x.x, 10.x.x.x, 172.16-31.x.x)
    // The WAN-side ARP entries (public IPs, AP gateway) should not be considered
    $lanArpEntries = array_filter($arpEntries, function($entry) {
        $ip = $entry['ip'] ?? '';
        return preg_match('/^192\.168\./', $ip) || preg_match('/^10\./', $ip) || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip);
    });

    // Check ARP for default Mikrotik IP
    $mikrotikEntry = null;
    foreach ($lanArpEntries as $entry) {
        if ($entry['ip'] === $mikrotikDefaultIp) {
            $mikrotikEntry = $entry;
            $result['mikrotik_found'] = true;
            $result['mikrotik_ip'] = $entry['ip'];
            $result['mikrotik_mac'] = $entry['mac'];
            $result['detection_method'] = 'arp';
            break;
        }
    }

    // Also check by OUI for Routerboard vendor
    if (!$mikrotikEntry) {
        foreach ($lanArpEntries as $entry) {
            $vendor = oui_lookup($entry['mac']);
            if ($vendor && (stripos($vendor, 'routerboard') !== false || stripos($vendor, 'mikrotik') !== false)) {
                $mikrotikEntry = $entry;
                $result['mikrotik_found'] = true;
                $result['mikrotik_ip'] = $entry['ip'];
                $result['mikrotik_mac'] = $entry['mac'];
                $result['detection_method'] = 'arp_vendor';
                break;
            }
        }
    }

    // Step 2: If not in ARP, check DHCP leases (static hosts and active leases)
    if (!$mikrotikEntry) {
        $dhcpOutput = epmp_ssh_exec($radioIp, $port, 'config show | dhcpLanHost', $airosLogin, $airosPass1, $airosPass2);
        $dhcpHosts = $dhcpOutput ? epmp_parse_dhcp_hosts($dhcpOutput) : [];

        foreach ($dhcpHosts as $host) {
            $vendor = oui_lookup($host['mac'] ?? '');
            $name = strtolower($host['name'] ?? '');
            if ($vendor && (stripos($vendor, 'routerboard') !== false || stripos($vendor, 'mikrotik') !== false)
                || strpos($name, 'routerboard') !== false || strpos($name, 'mikrotik') !== false) {
                $result['mikrotik_found'] = true;
                $result['mikrotik_ip'] = $host['ip'] ?? $mikrotikDefaultIp;
                $result['mikrotik_mac'] = $host['mac'] ?? null;
                $result['detection_method'] = 'dhcp_static';
                $result['has_static_lease'] = true;
                $mikrotikEntry = $host;
                break;
            }
        }
    }

    // Step 3: If still not found, check DHCP active leases via dnsmasq
    if (!$mikrotikEntry) {
        $leaseOutput = epmp_ssh_exec($radioIp, $port, 'cat /tmp/dnsmasq.leases /tmp/dhcp.leases /var/tmp/dnsmasq.leases 2>/dev/null', $airosLogin, $airosPass1, $airosPass2);
        if ($leaseOutput) {
            $leaseLines = explode("\n", trim($leaseOutput));
            foreach ($leaseLines as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (count($fields) >= 4 && filter_var($fields[2], FILTER_VALIDATE_IP)) {
                    $mac = strtoupper($fields[1]);
                    $ip = $fields[2];
                    $hostname = $fields[3] ?? '';
                    // Only consider private LAN IPs
                    if (!preg_match('/^192\.168\./', $ip) && !preg_match('/^10\./', $ip) && !preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip)) continue;
                    $vendor = oui_lookup($mac);
                    if ($vendor && (stripos($vendor, 'routerboard') !== false || stripos($vendor, 'mikrotik') !== false)
                        || stripos($hostname, 'mikrotik') !== false || stripos($hostname, 'routerboard') !== false) {
                        $result['mikrotik_found'] = true;
                        $result['mikrotik_ip'] = $ip;
                        $result['mikrotik_mac'] = $mac;
                        $result['detection_method'] = 'dhcp_lease';
                        $mikrotikEntry = ['ip' => $ip, 'mac' => $mac];
                        break;
                    }
                }
            }
        }
    }

    if (!$result['mikrotik_found']) {
        echo json_encode($result);
        return;
    }

    // Step 3: Check port forwards on the radio
    $pfOutput = epmp_ssh_exec($radioIp, $port, 'config show | portForwarding', $airosLogin, $airosPass1, $airosPass2);
    $portForwards = $pfOutput ? epmp_parse_port_forwards($pfOutput) : [];
    $result['port_forwards'] = $portForwards;
    $result['port_forward_raw'] = $pfOutput;  // Debug: raw output

    // Check for Winbox forward (WAN 8291 -> LAN 8291)
    global $mikrotikWinboxPort, $mikrotikSshWanPort, $mikrotikSshLanPort;
    foreach ($portForwards as $fwd) {
        $lanIp = $fwd['lan_ip'] ?? '';
        $wanPort = $fwd['wan_port_begin'] ?? 0;
        $lanPort = $fwd['lan_port'] ?? 0;

        if ($lanIp === $result['mikrotik_ip']) {
            // Winbox: any WAN port forwarding to LAN port 8291
            if ($lanPort == 8291) {
                $result['has_winbox_forward'] = true;
                $result['winbox_wan_port'] = $wanPort;
            }
            // SSH: any WAN port forwarding to LAN port 22 (or matching configured ssh_lan_port)
            if ($lanPort == $mikrotikSshLanPort || ($wanPort == $mikrotikSshWanPort && $lanPort == $mikrotikSshLanPort)) {
                $result['has_ssh_forward'] = true;
                $result['ssh_wan_port'] = $wanPort;
            }
        }
    }

    // Step 4: Check static DHCP lease
    // Only relevant if the Mikrotik was found at a non-default IP (i.e. via DHCP, not static config)
    if (!$result['has_static_lease'] && $result['mikrotik_mac'] && $result['mikrotik_ip'] !== $mikrotikDefaultIp) {
        $dhcpOutput = epmp_ssh_exec($radioIp, $port, 'config show | dhcpLanHost', $airosLogin, $airosPass1, $airosPass2);
        $dhcpHosts = $dhcpOutput ? epmp_parse_dhcp_hosts($dhcpOutput) : [];
        $result['has_static_lease'] = epmp_has_static_host($dhcpHosts, $result['mikrotik_mac']);
    } else if ($result['mikrotik_ip'] === $mikrotikDefaultIp) {
        // Default IP means it's statically configured on the Mikrotik — no DHCP lease needed
        $result['has_static_lease'] = true;
    }

    echo json_encode($result);
}

/**
 * Set up port forwards and static DHCP lease for the Mikrotik.
 */
function handleSetup(string $radioIp, string $radioType): void {
    if ($radioType === 'airos') {
        handleSetupAiros($radioIp);
    } else {
        handleSetupEpmp($radioIp);
    }
}

/**
 * Set up Mikrotik port forwards on AirOS radio.
 */
function handleSetupAiros(string $radioIp): void {
    global $mikrotikDefaultIp, $mikrotikWinboxPort, $mikrotikSshWanPort, $mikrotikSshLanPort,
           $mikrotikMgmtSrcIp, $airosLogin, $airosPass1, $airosPass2, $airosDefaultPort;

    $body = json_decode(file_get_contents('php://input'), true);
    $mikrotikIp = $body['mikrotik_ip'] ?? $mikrotikDefaultIp;
    $mikrotikMac = $body['mikrotik_mac'] ?? null;

    $port = $airosDefaultPort ?? 8022;
    $actions = [];

    $configOutput = airos_read_config($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '');
    if ($configOutput === null) {
        echo json_encode(['error' => 'Cannot SSH to AirOS radio']);
        return;
    }

    // Check/add static DHCP lease (only if not at default static IP)
    if ($mikrotikMac && $mikrotikIp !== $mikrotikDefaultIp && !airos_has_static_lease($configOutput, $mikrotikMac)) {
        airos_add_static_lease($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '', $mikrotikMac, $mikrotikIp, $configOutput);
        $actions[] = "Added static DHCP lease for {$mikrotikMac} -> {$mikrotikIp}";
        // Re-read config after modification
        $configOutput = airos_read_config($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '');
    }

    // Check/add Winbox port forward (WAN 8291 -> LAN 8291)
    $portForwards = airos_parse_port_forwards($configOutput);
    $hasWinbox = false;
    $hasSsh = false;
    foreach ($portForwards as $pf) {
        if (strtolower($pf['status'] ?? '') !== 'enabled') continue;
        if (($pf['host'] ?? '') === $mikrotikIp) {
            if ((int)($pf['port'] ?? 0) == 8291) $hasWinbox = true;
            if ((int)($pf['port'] ?? 0) == $mikrotikSshLanPort) $hasSsh = true;
        }
    }

    if (!$hasWinbox) {
        airos_add_port_forward($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '', $configOutput, $mikrotikWinboxPort, $mikrotikIp, 8291, 'TCP', '0.0.0.0', 'Mikrotik Winbox');
        $actions[] = "Added port forward WAN:{$mikrotikWinboxPort} -> {$mikrotikIp}:8291 (Winbox)";
        $configOutput = airos_read_config($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '');
    }

    if (!$hasSsh) {
        airos_add_port_forward($radioIp, $port, $airosLogin, $airosPass1, $airosPass2 ?? '', $configOutput, $mikrotikSshWanPort, $mikrotikIp, $mikrotikSshLanPort, 'TCP', $mikrotikMgmtSrcIp, 'Mikrotik SSH');
        $actions[] = "Added port forward WAN:{$mikrotikSshWanPort} -> {$mikrotikIp}:{$mikrotikSshLanPort} (SSH, src: {$mikrotikMgmtSrcIp})";
    }

    echo json_encode([
        'success' => true,
        'actions' => $actions,
        'mikrotik_ip' => $mikrotikIp,
        'mikrotik_mac' => $mikrotikMac,
    ]);
}

/**
 * Set up Mikrotik port forwards on ePMP radio.
 */
function handleSetupEpmp(string $radioIp): void {
    global $mikrotikDefaultIp, $mikrotikWinboxPort, $mikrotikSshWanPort, $mikrotikSshLanPort,
           $mikrotikMgmtSrcIp, $cambiumSshPort, $airosLogin, $airosPass1, $airosPass2;

    $body = json_decode(file_get_contents('php://input'), true);
    $mikrotikIp = $body['mikrotik_ip'] ?? $mikrotikDefaultIp;
    $mikrotikMac = $body['mikrotik_mac'] ?? null;

    $port = $cambiumSshPort ?? EPMP_SSH_PORT;
    $actions = [];

    // Get current state
    $pfOutput = epmp_ssh_exec($radioIp, $port, 'config show | portForwarding', $airosLogin, $airosPass1, $airosPass2);
    $portForwards = $pfOutput ? epmp_parse_port_forwards($pfOutput) : [];

    $dhcpOutput = epmp_ssh_exec($radioIp, $port, 'config show | dhcpLanHost', $airosLogin, $airosPass1, $airosPass2);
    $dhcpHosts = $dhcpOutput ? epmp_parse_dhcp_hosts($dhcpOutput) : [];

    // Add static DHCP lease if needed (only if not at the default static IP)
    if ($mikrotikMac && $mikrotikIp !== $mikrotikDefaultIp && !epmp_has_static_host($dhcpHosts, $mikrotikMac)) {
        epmp_add_static_host($radioIp, $port, $airosLogin, $airosPass1, $airosPass2, $mikrotikMac, $mikrotikIp, 'mikrotik');
        $actions[] = "Added static DHCP lease for {$mikrotikMac} -> {$mikrotikIp}";
    }

    // Add Winbox port forward (WAN 8291 -> LAN 8291) if not exists
    $hasWinbox = false;
    foreach ($portForwards as $fwd) {
        if (($fwd['lan_ip'] ?? '') === $mikrotikIp && ($fwd['lan_port'] ?? 0) == 8291) {
            $hasWinbox = true;
            break;
        }
    }
    if (!$hasWinbox) {
        epmp_add_port_forward($radioIp, $port, $airosLogin, $airosPass1, $airosPass2, $mikrotikWinboxPort, $mikrotikIp, 8291, 2);
        $actions[] = "Added port forward WAN:{$mikrotikWinboxPort} -> {$mikrotikIp}:8291 (Winbox)";
    }

    // Add SSH port forward (WAN 2022 -> LAN 22, src restricted) if not exists
    // Note: ePMP port forwards don't support src-address restriction natively,
    // so we add it without restriction on the radio and rely on Mikrotik firewall for src filtering
    $hasSsh = false;
    foreach ($portForwards as $fwd) {
        if (($fwd['lan_ip'] ?? '') === $mikrotikIp && ($fwd['lan_port'] ?? 0) == $mikrotikSshLanPort) {
            $hasSsh = true;
            break;
        }
    }
    if (!$hasSsh) {
        epmp_add_port_forward($radioIp, $port, $airosLogin, $airosPass1, $airosPass2, $mikrotikSshWanPort, $mikrotikIp, $mikrotikSshLanPort, 2);
        $actions[] = "Added port forward WAN:{$mikrotikSshWanPort} -> {$mikrotikIp}:{$mikrotikSshLanPort} (SSH)";
    }

    // Now configure the Mikrotik itself to restrict SSH access by source IP
    // SSH into the Mikrotik and add a firewall filter if not already present
    global $mikrotikLogin, $mikrotikPass, $mikrotikSshPort;
    $mkOutput = mikrotik_ssh_exec($mikrotikIp, $mikrotikSshPort, '/ip firewall filter print detail without-paging where dst-port=22', $mikrotikLogin, $mikrotikPass);
    if ($mkOutput !== null && strpos($mkOutput, $mikrotikMgmtSrcIp) === false) {
        // Add firewall rule to restrict SSH to management IP only
        $fwCmd = "/ip firewall filter add chain=input protocol=tcp dst-port=22 src-address={$mikrotikMgmtSrcIp} action=accept comment=\"Allow SSH from mgmt\" place-before=0";
        mikrotik_ssh_exec($mikrotikIp, $mikrotikSshPort, $fwCmd, $mikrotikLogin, $mikrotikPass);

        $fwDropCmd = "/ip firewall filter add chain=input protocol=tcp dst-port=22 action=drop comment=\"Drop SSH from others\"";
        mikrotik_ssh_exec($mikrotikIp, $mikrotikSshPort, $fwDropCmd, $mikrotikLogin, $mikrotikPass);

        $actions[] = "Added Mikrotik firewall rules to restrict SSH to {$mikrotikMgmtSrcIp}";
    }

    echo json_encode([
        'success' => true,
        'actions' => $actions,
        'mikrotik_ip' => $mikrotikIp,
        'mikrotik_mac' => $mikrotikMac,
    ]);
}

/**
 * Run bandwidth test via the Mikrotik.
 * Uses Server-Sent Events (SSE) to stream progress updates to the browser.
 * 
 * Strategy: The btest saturates the link, which kills the SSH session running
 * through that link. So we use a RouterOS script to run the btest in the background
 * and write results to a file, then fetch the file after the test completes.
 */
function handleBtest(string $radioIp): void {
    global $mikrotikDefaultIp, $mikrotikSshPort, $mikrotikLogin, $mikrotikPass,
           $btestServer, $btestUser, $btestPass, $btestDuration, $mikrotikSshWanPort;

    $body = json_decode(file_get_contents('php://input'), true);
    $mikrotikIp = $body['mikrotik_ip'] ?? $mikrotikDefaultIp;
    $duration = (int)($body['duration'] ?? $btestDuration);

    // Clamp duration to reasonable range
    if ($duration < 5) $duration = 5;
    if ($duration > 60) $duration = 60;

    // Switch to SSE streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');  // Disable nginx buffering

    // Disable output buffering
    while (ob_get_level()) ob_end_flush();

    $sseId = 0;
    $sendEvent = function(string $event, $data) use (&$sseId) {
        $sseId++;
        echo "id: {$sseId}\n";
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    };

    // SSH to the Mikrotik via the radio's port forward (radioIp:sshWanPort -> mikrotikIp:22)
    $sshHost = $radioIp;
    $sshPort = $mikrotikSshWanPort;

    $sendEvent('progress', ['stage' => 'connecting', 'message' => "Connecting to Mikrotik at {$sshHost}:{$sshPort}..."]);

    // Test SSH connectivity first
    $testOutput = mikrotik_ssh_exec($sshHost, $sshPort, '/system identity print', $mikrotikLogin, $mikrotikPass, 30);
    if ($testOutput === null) {
        $sendEvent('error', ['message' => "SSH connection failed to {$sshHost}:{$sshPort}"]);
        $sendEvent('done', ['success' => false]);
        return;
    }

    $sendEvent('progress', ['stage' => 'connected', 'message' => "SSH connected. Running download test ({$duration}s)..."]);

    // Run download test directly — timeout = duration + 60s for overhead
    $dlCmd = "/tool bandwidth-test address={$btestServer} user={$btestUser} password={$btestPass}"
        . " protocol=tcp direction=receive duration={$duration}";

    $dlOutput = mikrotik_ssh_exec($sshHost, $sshPort, $dlCmd, $mikrotikLogin, $mikrotikPass, $duration + 60);
    if ($dlOutput === null) {
        $sendEvent('error', ['message' => 'SSH connection lost during download test']);
        $sendEvent('done', ['success' => false]);
        return;
    }

    $dlResult = mikrotik_parse_btest_output($dlOutput);
    $sendEvent('progress', [
        'stage' => 'download_complete',
        'message' => "Download: {$dlResult['avg_mbps']} Mbps avg. Running upload test ({$duration}s)...",
        'download' => $dlResult,
    ]);

    // Run upload test
    $ulCmd = "/tool bandwidth-test address={$btestServer} user={$btestUser} password={$btestPass}"
        . " protocol=tcp direction=transmit duration={$duration}";

    $ulOutput = mikrotik_ssh_exec($sshHost, $sshPort, $ulCmd, $mikrotikLogin, $mikrotikPass, $duration + 60);
    if ($ulOutput === null) {
        $sendEvent('error', ['message' => 'SSH connection lost during upload test']);
        $sendEvent('done', ['success' => false, 'download' => $dlResult]);
        return;
    }

    $ulResult = mikrotik_parse_btest_output($ulOutput);

    // Final result
    $sendEvent('done', [
        'success' => true,
        'download' => $dlResult,
        'upload' => $ulResult,
        'mikrotik_ip' => $mikrotikIp,
        'btest_server' => $btestServer,
        'duration' => $duration,
    ]);
}
