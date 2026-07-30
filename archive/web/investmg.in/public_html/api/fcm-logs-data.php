<?php
/**
 * FCM Logs Data API - Server-side DataTables endpoint.
 * Reads from fcm_notifications joined with health_check_reports (by request_id).
 * Strips fcm_token from the app_status payload before returning.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDB();

    $dateFrom     = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
    $dateTo       = $_GET['date_to']   ?? date('Y-m-d', strtotime('-1 day'));
    $selectedUser = trim($_GET['user_id'] ?? '');
    $selectedType = trim($_GET['type']    ?? '');

    $draw   = isset($_GET['draw'])   ? (int)$_GET['draw']   : 1;
    $start  = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 25;
    if ($length <= 0) $length = 25;
    $searchValue = $_GET['search']['value'] ?? '';

    $orderColumn = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 0;
    $orderDir    = (isset($_GET['order'][0]['dir']) && $_GET['order'][0]['dir'] === 'asc') ? 'ASC' : 'DESC';
    $columnMap = [
        0 => 'fn.sent_at',
        1 => 'fn.user_id',
        2 => 'fn.type',
        3 => 'fn.http_status',
        4 => 'fn.fcm_status',
    ];
    $orderCol = $columnMap[$orderColumn] ?? 'fn.sent_at';

    $dateFromTs = $dateFrom . ' 00:00:00';
    $dateToTs   = $dateTo   . ' 23:59:59';

    $where  = 'fn.sent_at BETWEEN ? AND ?';
    $params = [$dateFromTs, $dateToTs];

    if ($selectedUser !== '') {
        $where .= ' AND fn.user_id = ?';
        $params[] = $selectedUser;
    }
    if ($selectedType !== '') {
        $where .= ' AND fn.type = ?';
        $params[] = $selectedType;
    }
    if ($searchValue !== '') {
        $where .= ' AND (fn.user_id LIKE ? OR fn.fcm_status LIKE ? OR fn.request_id LIKE ? OR fn.error_message LIKE ?)';
        $like = '%' . $searchValue . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $totalRow = $db->fetch(
        'SELECT COUNT(*) AS c FROM fcm_notifications fn WHERE fn.sent_at BETWEEN ? AND ?',
        [$dateFromTs, $dateToTs]
    );
    $filteredRow = $db->fetch(
        "SELECT COUNT(*) AS c FROM fcm_notifications fn WHERE {$where}",
        $params
    );

    $rows = $db->fetchAll(
        "SELECT fn.id, fn.sent_at, fn.user_id, fn.type, fn.http_status, fn.fcm_status,
                fn.error_message, fn.raw_response, fn.request_id,
                hr.raw_payload AS health_payload
           FROM fcm_notifications fn
      LEFT JOIN health_check_reports hr ON hr.request_id = fn.request_id
          WHERE {$where}
       ORDER BY {$orderCol} {$orderDir}
          LIMIT {$start}, {$length}",
        $params
    );

    $data = [];
    foreach ($rows as $r) {
        $hasError    = !empty($r['error_message']) || (int)$r['http_status'] >= 400;
        $appStatus   = null;
        $lastRun     = null;
        $failureLog  = null;
        $pingAt      = null;
        $appVer      = null;
        $deviceModel = null;
        $androidVer  = null;

        if (!empty($r['health_payload'])) {
            $p = json_decode($r['health_payload'], true);
            if (is_array($p)) {
                if (!empty($p['app_status']) && is_array($p['app_status'])) {
                    $as = $p['app_status'];
                    unset($as['fcm_token']);  // never leak token to client
                    $appStatus = $as;
                }
                $lastRun     = $p['last_run']    ?? null;
                $failureLog  = $p['failure_log'] ?? null;
                $pingAt      = $p['ping_received_at'] ?? null;
                $appVer      = $p['app_version']      ?? null;
                $deviceModel = $p['device_model']     ?? null;
                $androidVer  = $p['android_version'] ?? null;
            }
        }

        $rawResponse = $r['raw_response'];
        $rawResponsePretty = null;
        if ($rawResponse) {
            $decoded = json_decode($rawResponse, true);
            $rawResponsePretty = $decoded !== null
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $rawResponse;
        }

        $data[] = [
            'sent_at'        => $r['sent_at'],
            'user_id'        => $r['user_id'],
            'type'           => $r['type'],
            'http_status'    => (int)$r['http_status'],
            'fcm_status'     => $r['fcm_status'] ?? '',
            'request_id'     => $r['request_id'] ?? '',
            'has_error'      => $hasError,
            'error_message'  => $r['error_message'],
            'raw_response'   => $rawResponsePretty,
            'has_app_status' => $appStatus !== null,
            'app_status'     => $appStatus,
            'last_run'       => $lastRun,
            'failure_log'    => $failureLog,
            'ping_received_at' => $pingAt,
            'app_version'    => $appVer,
            'device_model'   => $deviceModel,
            'android_version' => $androidVer,
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => (int)($totalRow['c'] ?? 0),
        'recordsFiltered' => (int)($filteredRow['c'] ?? 0),
        'data'            => $data,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
