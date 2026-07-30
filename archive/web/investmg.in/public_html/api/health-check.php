<?php
/**
 * API Endpoint: Health Check Report
 * POST /api/health-check.php
 *
 * Receives the mobile app's response to a silent FCM health-check ping.
 * Stores the full JSON payload plus denormalized fields for quick admin queries,
 * and correlates with the originating fcm_notifications row by request_id.
 *
 * Required POST JSON body:
 *   - user_id  (string)
 *
 * The rest of the payload schema is defined in android-backend-payloads.html
 * (API 2: Health Check).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);

if (!$input || empty($input['user_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required field: user_id']);
    exit;
}

$userId           = trim($input['user_id']);
$requestId        = !empty($input['request_id']) ? trim($input['request_id']) : null;
$appVersion       = $input['app_version']     ?? null;
$deviceModel      = $input['device_model']    ?? null;
$androidVersion   = $input['android_version'] ?? null;
$pingReceivedAt   = $input['ping_received_at'] ?? null;
$workManagerState = $input['app_status']['work_manager_state'] ?? null;
$tokenInPayload   = $input['app_status']['fcm_token'] ?? null;

$pingDt = null;
if ($pingReceivedAt) {
    $t = strtotime($pingReceivedAt);
    if ($t !== false) $pingDt = date('Y-m-d H:i:s', $t);
}

try {
    $db = getDB();

    $db->insert(
        "INSERT INTO health_check_reports
            (user_id, request_id, received_at, ping_received_at, raw_payload,
             app_version, device_model, android_version, work_manager_state)
         VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)",
        [
            $userId, $requestId, $pingDt, $rawBody,
            $appVersion, $deviceModel, $androidVersion, $workManagerState,
        ]
    );

    $db->update(
        "UPDATE users SET last_health_check_at = NOW() WHERE user_id = ?",
        [$userId]
    );

    if ($requestId) {
        $db->update(
            "UPDATE fcm_notifications
                SET health_check_received_at = NOW()
              WHERE request_id = ?
                AND health_check_received_at IS NULL",
            [$requestId]
        );
    }

    if ($tokenInPayload) {
        $current = $db->fetch("SELECT fcm_token FROM users WHERE user_id = ?", [$userId]);
        if ($current && $current['fcm_token'] !== $tokenInPayload) {
            $db->update(
                "UPDATE users
                    SET fcm_token = ?,
                        fcm_token_updated_at = NOW(),
                        device_status = 'active'
                  WHERE user_id = ?",
                [$tokenInPayload, $userId]
            );
        }
    }

    http_response_code(200);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
