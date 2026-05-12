<?php
/**
 * Debug: SSH into a radio and list DHCP-related files.
 * GET ?ip=x.x.x.x&ssh_port=22
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
set_time_limit(30);

$ip = trim($_GET['ip'] ?? '');
$sshPort = (int)($_GET['ssh_port'] ?? $airosDefaultPort);

if (!$ip) die("Provide ?ip=x.x.x.x\n");

$sshOpts = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o LogLevel=ERROR"
    . " -o KexAlgorithms=+diffie-hellman-group1-sha1,diffie-hellman-group14-sha1"
    . " -o HostKeyAlgorithms=+ssh-rsa,ssh-dss"
    . " -o Ciphers=+aes128-cbc,aes256-cbc,3des-cbc";

$cmd = implode('; echo "---"; ', [
    'echo "=== /tmp/ dhcp/dns files ==="',
    'ls -la /tmp/*dhcp* /tmp/*dns* /tmp/*lease* 2>/dev/null || echo "none found in /tmp"',
    'echo "=== /var/run/ ==="',
    'ls -la /var/run/*dhcp* /var/run/*dns* /var/run/*lease* 2>/dev/null || echo "none found in /var/run"',
    'echo "=== /etc/ ==="',
    'ls -la /etc/*dhcp* /etc/dnsmasq* 2>/dev/null || echo "none found in /etc"',
    'echo "=== dnsmasq process ==="',
    'ps | grep -i dns 2>/dev/null || echo "no dnsmasq"',
    'echo "=== dhcp.leases content ==="',
    'cat /tmp/dhcp.leases 2>/dev/null || echo "file not found"',
    'echo "=== dnsmasq.leases content ==="',
    'cat /tmp/dnsmasq.leases 2>/dev/null || echo "file not found"',
    'echo "=== /var/run/dnsmasq.leases ==="',
    'cat /var/run/dnsmasq.leases 2>/dev/null || echo "file not found"',
    'echo "=== find any lease files ==="',
    'find / -name "*lease*" -type f 2>/dev/null | head -10 || echo "none"',
]);

$escapedIp = escapeshellarg($ip);
$escapedLogin = escapeshellarg($airosLogin);
$escapedPass = escapeshellarg($airosPass1);

$fullCmd = "sshpass -p {$escapedPass} ssh {$sshOpts} -p {$sshPort} {$escapedLogin}@{$escapedIp} " . escapeshellarg($cmd) . " 2>&1";
echo "Command: ssh -p {$sshPort} {$airosLogin}@{$ip}\n\n";
echo shell_exec($fullCmd) ?? 'No output';
