<?php
/**
 * API Endpoint: FCM Token Registration
 * POST /api/fcm-token.php
 *
 * Registers or updates a device's FCM token for a user. Upserts the row in
 * the `users` table. If the submitted token is unchanged from what is
 * already stored, fcm_token_updated_at is not touched.
 *
 * Required POST JSON body:
 *   - user_id          (string)
 *   - fcm_token        (string)
 *
 * Optional POST JSON body:
 *   - department_id    (int)
 *   - app_version      (string)
 *   - device_model     (string)
 *   - android_version  (string)
 *   - timestamp        (string, ISO8601 — informational only)
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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['user_id']) || empty($input['fcm_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: user_id, fcm_token']);
    exit;
}

$userId         = trim($input['user_id']);
$fcmToken       = trim($input['fcm_token']);
$departmentId   = isset($input['department_id']) && $input['department_id'] !== '' ? (int)$input['department_id'] : null;
$appVersion     = isset($input['app_version']) ? trim($input['app_version']) : null;
$deviceModel    = isset($input['device_model']) ? trim($input['device_model']) : null;
$androidVersion = isset($input['android_version']) ? trim($input['android_version']) : null;

try {
    $db = getDB();

    $existing = $db->fetch(
        "SELECT fcm_token FROM users WHERE user_id = ? LIMIT 1",
        [$userId]
    );

    $tokenChanged = !$existing || $existing['fcm_token'] !== $fcmToken;

    if (!$existing) {
        $db->insert(
            "INSERT INTO users
                (user_id, department_id, fcm_token, app_version, device_model, android_version, fcm_token_updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $departmentId, $fcmToken, $appVersion, $deviceModel, $androidVersion]
        );
    } elseif ($tokenChanged) {
        $db->update(
            "UPDATE users SET
                department_id        = COALESCE(?, department_id),
                fcm_token            = ?,
                app_version          = ?,
                device_model         = ?,
                android_version      = ?,
                fcm_token_updated_at = NOW(),
                device_status        = 'active'
             WHERE user_id = ?",
            [$departmentId, $fcmToken, $appVersion, $deviceModel, $androidVersion, $userId]
        );
    } else {
        $db->update(
            "UPDATE users SET
                department_id   = COALESCE(?, department_id),
                app_version     = ?,
                device_model    = ?,
                android_version = ?
             WHERE user_id = ?",
            [$departmentId, $appVersion, $deviceModel, $androidVersion, $userId]
        );
    }

    http_response_code(200);
    echo json_encode([
        'success'      => true,
        'token_updated' => $tokenChanged,
        'user_created' => !$existing,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
