<?php
/**
 * MAC OUI vendor lookup.
 * Downloads and caches the IEEE OUI database locally.
 * 
 * Usage: require this file, then call oui_lookup('AA:BB:CC:DD:EE:FF')
 */

define('OUI_CACHE_PATH', '/dev/shm/oui_cache.json');
define('OUI_CACHE_MAX_AGE', 86400 * 30); // Refresh monthly

function buildOuiCache(): array
{
    // Download the IEEE OUI list (compact format)
    $url = 'https://standards-oui.ieee.org/oui/oui.csv';
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $csv = @file_get_contents($url, false, $ctx);

    if (!$csv) {
        // Fallback: try the txt format
        $url = 'https://standards-oui.ieee.org/oui/oui.txt';
        $txt = @file_get_contents($url, false, $ctx);
        if (!$txt) return [];

        $cache = [];
        foreach (explode("\n", $txt) as $line) {
            // Format: "AA-BB-CC   (hex)		Vendor Name"
            if (preg_match('/^([0-9A-F]{2}-[0-9A-F]{2}-[0-9A-F]{2})\s+\(hex\)\s+(.+)$/i', trim($line), $m)) {
                $oui = strtoupper(str_replace('-', ':', $m[1]));
                $cache[$oui] = trim($m[2]);
            }
        }
        file_put_contents(OUI_CACHE_PATH, json_encode($cache));
        return $cache;
    }

    // Parse CSV: "Registry,Assignment,Organization Name,Organization Address"
    $cache = [];
    $lines = explode("\n", $csv);
    foreach ($lines as $i => $line) {
        if ($i === 0) continue; // skip header
        $fields = str_getcsv($line);
        if (count($fields) >= 3 && strlen($fields[1] ?? '') === 6) {
            $hex = strtoupper($fields[1]);
            $oui = substr($hex, 0, 2) . ':' . substr($hex, 2, 2) . ':' . substr($hex, 4, 2);
            $cache[$oui] = trim($fields[2]);
        }
    }

    file_put_contents(OUI_CACHE_PATH, json_encode($cache));
    return $cache;
}

function getOuiCache(): array
{
    if (file_exists(OUI_CACHE_PATH) && (time() - filemtime(OUI_CACHE_PATH)) < OUI_CACHE_MAX_AGE) {
        return json_decode(file_get_contents(OUI_CACHE_PATH), true) ?: [];
    }
    return buildOuiCache();
}

function oui_lookup(string $mac): string
{
    $mac = strtoupper(trim($mac));
    // Normalise to colon format
    $mac = str_replace(['-', '.'], ':', $mac);
    // Extract first 3 octets
    $parts = explode(':', $mac);
    if (count($parts) < 3) return '';
    $oui = $parts[0] . ':' . $parts[1] . ':' . $parts[2];

    $cache = getOuiCache();
    return $cache[$oui] ?? '';
}
