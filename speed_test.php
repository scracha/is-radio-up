<?php
/**
 * Speed Test endpoint for is-radio-up.
 * 
 * - latest & schedule: query/write WISP-graphing SQLite DB directly (no HTTP round-trip)
 * - run: proxies to WISP-graphing API (needs LinkTestController for SSH)
 *
 * Usage:
 *   GET  ?action=latest&ip=x.x.x.x          — fetch last 10 speed test results
 *   POST ?action=run        body: {ip}        — run immediate speed test (via API)
 *   POST ?action=schedule   body: {ip, scheduled_time} — schedule a speed test
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'latest':
        handleLatest();
        break;
    case 'run':
        handleRun();
        break;
    case 'schedule':
        handleSchedule();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: latest, run, or schedule']);
        exit;
}

// ============================================================
// Database helper — opens nms.db with WAL + retry on lock
// ============================================================

function getWispDb(): PDO {
    global $wispGraphingDbPath;
    if (!file_exists($wispGraphingDbPath)) {
        http_response_code(503);
        echo json_encode(['error' => 'WISP-graphing database not found']);
        exit;
    }
    $db = new PDO('sqlite:' . $wispGraphingDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 30000');
    $db->exec('PRAGMA journal_mode = WAL');
    return $db;
}

function dbRetry(callable $fn, int $maxRetries = 3) {
    $lastError = null;
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $lastError = $e;
            if (strpos($e->getMessage(), 'database is locked') === false) {
                throw $e;
            }
            usleep(500000 * ($i + 1)); // 500ms, 1s, 1.5s
        }
    }
    throw $lastError;
}

function resolveSubscriber(PDO $db, string $ip): ?array {
    $stmt = $db->prepare("SELECT id, access_point_id, mac_address, device_name FROM subscribers WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function validateIp(string $ip): void {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid IP address']);
        exit;
    }
}

/**
 * Convert a UTC timestamp from SQLite (datetime('now')) to server local time.
 * Returns ISO 8601 format with offset so JS can parse it correctly.
 */
function utcToLocal(?string $utcTimestamp): ?string {
    if (!$utcTimestamp) return null;
    try {
        $dt = new DateTime($utcTimestamp, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('c'); // ISO 8601 with offset, e.g. 2026-04-15T17:30:00+12:00
    } catch (Exception $e) {
        return $utcTimestamp;
    }
}

// ============================================================
// latest — direct DB read, last 10 results
// ============================================================

function handleLatest(): void {
    $ip = trim($_GET['ip'] ?? '');
    validateIp($ip);

    try {
        $db = getWispDb();

        $subscriber = dbRetry(fn() => resolveSubscriber($db, $ip));
        if (!$subscriber) {
            http_response_code(404);
            echo json_encode(['error' => "No subscriber found for IP {$ip}"]);
            exit;
        }

        $rows = dbRetry(function() use ($db, $subscriber) {
            $stmt = $db->prepare("
                SELECT downlink_kbps, uplink_kbps, status, source, created_at, device_name
                FROM link_test_results WHERE subscriber_id = ?
                ORDER BY created_at DESC LIMIT 10
            ");
            $stmt->execute([$subscriber['id']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });

        $results = array_map(fn($r) => [
            'download_mbps' => $r['downlink_kbps'] !== null ? round($r['downlink_kbps'] / 1000, 1) : null,
            'upload_mbps'   => $r['uplink_kbps'] !== null ? round($r['uplink_kbps'] / 1000, 1) : null,
            'latency_ms'    => null,
            'timestamp'     => utcToLocal($r['created_at']),
            'status'        => $r['status'],
            'source'        => $r['source'],
        ], $rows);

        echo json_encode([
            'latest_result'  => $results[0] ?? null,
            'results'        => $results,
            'subscriber_id'  => (int)$subscriber['id'],
            'device_name'    => ($rows[0]['device_name'] ?? null) ?: $subscriber['device_name'],
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'database is locked') !== false) {
            http_response_code(503);
            echo json_encode(['error' => 'Database busy, try again']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
}

// ============================================================
// run — must proxy to WISP-graphing API (triggers SSH link test)
// ============================================================

function handleRun(): void {
    global $wispGraphingApiBaseUrl, $wispGraphingApiKey;

    if (empty($wispGraphingApiKey) || $wispGraphingApiKey === 'your-api-key-here' || empty($wispGraphingApiBaseUrl)) {
        http_response_code(500);
        echo json_encode(['error' => 'Speed test API not configured']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $ip = trim($body['ip'] ?? '');
    validateIp($ip);

    $url = $wispGraphingApiBaseUrl . '/speed-test/run';
    echo proxyRequest('POST', $url, json_encode(['ip' => $ip]));
}

// ============================================================
// schedule — direct DB write (no SSH needed)
// ============================================================

function handleSchedule(): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $ip = trim($body['ip'] ?? '');
    validateIp($ip);

    $scheduledTime = trim($body['scheduled_time'] ?? '');
    if (empty($scheduledTime)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing scheduled_time']);
        exit;
    }

    $scheduledTs = strtotime($scheduledTime);
    if ($scheduledTs === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid scheduled_time format']);
        exit;
    }
    if ($scheduledTs <= time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Scheduled time must be in the future']);
        exit;
    }

    try {
        $db = getWispDb();

        $subscriber = dbRetry(fn() => resolveSubscriber($db, $ip));
        if (!$subscriber) {
            http_response_code(404);
            echo json_encode(['error' => "No subscriber found for IP {$ip}"]);
            exit;
        }

        $scheduleDate = date('Y-m-d', $scheduledTs);
        $scheduleTime = date('H:i', $scheduledTs);

        $scheduleId = dbRetry(function() use ($db, $subscriber, $body, $scheduleTime, $scheduleDate) {
            $stmt = $db->prepare("
                INSERT INTO scheduled_link_tests
                (subscriber_id, access_point_id, mac_address, device_name,
                 duration, packet_size, schedule_time, schedule_days,
                 schedule_type, schedule_date, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'daily', 'once', ?, 1)
            ");
            $stmt->execute([
                $subscriber['id'],
                $subscriber['access_point_id'],
                strtolower(trim($subscriber['mac_address'])),
                $subscriber['device_name'],
                $body['duration'] ?? 10,
                $body['packet_size'] ?? 1500,
                $scheduleTime,
                $scheduleDate,
            ]);
            return $db->lastInsertId();
        });

        echo json_encode([
            'scheduled'      => true,
            'schedule_id'    => (int)$scheduleId,
            'scheduled_time' => date('Y-m-d H:i', $scheduledTs),
            'subscriber_ip'  => $ip,
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'database is locked') !== false) {
            http_response_code(503);
            echo json_encode(['error' => 'Database busy, try again']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
}

// ============================================================
// HTTP proxy — only used for 'run' action
// ============================================================

function proxyRequest(string $method, string $url, ?string $body = null): string {
    global $wispGraphingApiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $wispGraphingApiKey,
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno !== 0) {
        http_response_code(502);
        return json_encode([
            'error' => 'WISP-graphing API unavailable',
            'detail' => $curlError,
        ]);
    }

    http_response_code($httpCode);
    return $response;
}
