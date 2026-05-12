<?php
/**
 * Debug: raw SNMP walk of IF-MIB on a Cambium radio.
 * GET ?ip=x.x.x.x&community=public
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
set_time_limit(15);

$ip = trim($_GET['ip'] ?? '');
$community = trim($_GET['community'] ?? 'public');
if (!$ip) die("Provide ?ip=x.x.x.x\n");

echo "=== ifDescr (1.3.6.1.2.1.2.2.1.2) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.2', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ifSpeed (1.3.6.1.2.1.2.2.1.5) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.5', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ifHighSpeed (1.3.6.1.2.1.31.1.1.1.15) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.31.1.1.1.15', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ifOperStatus (1.3.6.1.2.1.2.2.1.8) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.2.2.1.8', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== Cambium LAN Speed (1.3.6.1.4.1.17713.21.1.4.11.0) ===\n";
$r = @snmpget($ip, $community, '1.3.6.1.4.1.17713.21.1.4.11.0', 1000000, 1);
echo "  " . ($r ?: "FAILED") . "\n";

echo "\n=== Cambium LAN Status (1.3.6.1.4.1.17713.21.1.4.10.0) ===\n";
$r = @snmpget($ip, $community, '1.3.6.1.4.1.17713.21.1.4.10.0', 1000000, 1);
echo "  " . ($r ?: "FAILED") . "\n";

echo "\n=== ARP ipNetToMedia (1.3.6.1.2.1.4.22.1.2) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.4.22.1.2', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ARP atPhysAddress (1.3.6.1.2.1.3.1.1.2) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.3.1.1.2', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ipNetToMediaPhysAddress full walk (1.3.6.1.2.1.4.22) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.4.22', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== ipAddrTable (1.3.6.1.2.1.4.20) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.2.1.4.20', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== Cambium NAT/DHCP tree (1.3.6.1.4.1.17713.21.1.4) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.1.4', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== Cambium full device tree sample (1.3.6.1.4.1.17713.21.3) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.3', 1000000, 1);
if ($r) { $c=0; foreach ($r as $k => $v) { echo "  {$k} = {$v}\n"; if(++$c>30) { echo "  ...(truncated)\n"; break; } } }
else echo "  EMPTY or FAILED\n";

echo "\n=== Cambium DHCP Server area (1.3.6.1.4.1.17713.21.3.2) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.3.2', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";

echo "\n=== Cambium DHCP Leases area (1.3.6.1.4.1.17713.21.3.2.5) ===\n";
$r = @snmpwalkoid($ip, $community, '1.3.6.1.4.1.17713.21.3.2.5', 1000000, 1);
if ($r) foreach ($r as $k => $v) echo "  {$k} = {$v}\n";
else echo "  EMPTY or FAILED\n";
