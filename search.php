<?php
/**
 * Search Splynx data store by IP, customer name, or address.
 * GET ?q=search_term
 * Returns matching services.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

if (!file_exists(DATA_STORE_PATH)) { echo json_encode([]); exit; }
$all = json_decode(file_get_contents(DATA_STORE_PATH), true) ?? [];

// If it looks like an IP, do exact match
if (filter_var($q, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    if (isset($all[$q])) {
        $s = $all[$q];
        $s['service_ipv4'] = $q;
        echo json_encode([$s]);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Normalise phone numbers for matching
// Strips spaces, dashes, brackets, and generates NZ variants:
// "027" -> also matches "+6427", "+64(0)27", "6427"
function phoneVariants(string $q): array {
    $stripped = preg_replace('/[\s\-\(\)]+/', '', $q);
    $variants = [$stripped];
    
    // If starts with 0 (NZ local), add international variants
    if (preg_match('/^0(\d+)$/', $stripped, $m)) {
        $withoutZero = $m[1];
        $variants[] = '+64' . $withoutZero;
        $variants[] = '64' . $withoutZero;
        $variants[] = '+64(0)' . $withoutZero;
    }
    // If starts with +64 or 64, add local variant
    if (preg_match('/^\+?64(\d+)$/', $stripped, $m)) {
        $variants[] = '0' . $m[1];
        $variants[] = '+64' . $m[1];
        $variants[] = '64' . $m[1];
    }
    return array_unique($variants);
}

function phoneMatches(string $haystack, array $variants): bool {
    $h = preg_replace('/[\s\-\(\)]+/', '', strtolower($haystack));
    foreach ($variants as $v) {
        if (strpos($h, strtolower($v)) !== false) return true;
    }
    return false;
}

// Otherwise fuzzy search by name, address, phone
$qLower = strtolower($q);
$isPhoneSearch = preg_match('/^[\d\+\(\)\-\s]{3,}$/', $q);
$phoneVars = $isPhoneSearch ? phoneVariants($q) : [];

$matches = [];
foreach ($all as $ip => $s) {
    $name = strtolower($s['customer_name'] ?? '');
    $addr = strtolower($s['service_address'] ?? $s['customer_address_fallback'] ?? '');
    $desc = strtolower($s['service_description'] ?? '');
    $contact2 = strtolower($s['contact_2_name'] ?? '');
    $phone = $s['customer_phone'] ?? '';
    $phone2 = $s['contact_2_phone'] ?? '';

    $matched = strpos($name, $qLower) !== false || strpos($addr, $qLower) !== false || 
               strpos($desc, $qLower) !== false || strpos($contact2, $qLower) !== false;

    if (!$matched && $isPhoneSearch) {
        $matched = phoneMatches($phone, $phoneVars) || phoneMatches($phone2, $phoneVars);
    } elseif (!$matched) {
        $matched = strpos(strtolower($phone), $qLower) !== false || strpos(strtolower($phone2), $qLower) !== false;
    }

    if ($matched) {
        $s['service_ipv4'] = $ip;
        $matches[] = $s;
        if (count($matches) >= 20) break;
    }
}
echo json_encode($matches);
