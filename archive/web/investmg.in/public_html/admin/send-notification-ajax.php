<?php
/**
 * AJAX Endpoint: Send FCM notification to a single user from admin panel.
 * POST params:
 *   - user_id (string)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fcm.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$userId = trim($_POST['user_id'] ?? '');
if ($userId === '') {
    echo json_encode(['success' => false, 'error' => 'Missing user_id']);
    exit;
}

$db = getDB();

$user = $db->fetch(
    "SELECT user_id, fcm_token, device_status FROM users WHERE user_id = ?",
    [$userId]
);

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

if (empty($user['fcm_token'])) {
    echo json_encode(['success' => false, 'error' => 'User has no FCM token registered']);
    exit;
}

if ($user['device_status'] === 'invalid_token') {
    echo json_encode(['success' => false, 'error' => 'Device marked as invalid_token - user must re-register']);
    exit;
}

$tokenResult = fcm_get_access_token();
if (isset($tokenResult['error'])) {
    echo json_encode(['success' => false, 'error' => 'FCM auth failed: ' . $tokenResult['error']]);
    exit;
}

$requestId = sprintf('adm_%s_%04x', date('Ymd_His'), random_int(0, 0xffff));

$sendResult = fcm_send_message(
    $tokenResult['access_token'],
    $tokenResult['project_id'],
    $user['fcm_token'],
    ['type' => 'health_check', 'request_id' => $requestId]
);

$classified = fcm_classify_response($sendResult);

$db->insert(
    "INSERT INTO fcm_notifications
        (user_id, fcm_token_used, request_id, type, http_status, fcm_status,
         error_message, raw_response, sent_at)
     VALUES (?, ?, ?, 'admin_manual', ?, ?, ?, ?, NOW())",
    [
        $user['user_id'],
        $user['fcm_token'],
        $requestId,
        $sendResult['http_status'],
        $classified['status'],
        $classified['error_message'],
        $sendResult['raw_response'],
    ]
);

if ($classified['action'] === 'invalid_token') {
    $db->update(
        "UPDATE users
            SET device_status = 'invalid_token',
                fcm_token = NULL,
                last_notification_sent_at = NOW(),
                last_notification_status = ?
          WHERE user_id = ?",
        [$classified['status'], $user['user_id']]
    );
} else {
    $db->update(
        "UPDATE users
            SET last_notification_sent_at = NOW(),
                last_notification_status = ?
          WHERE user_id = ?",
        [$classified['status'], $user['user_id']]
    );
}

if ($classified['action'] === 'success') {
    echo json_encode([
        'success'    => true,
        'message'    => 'Notification sent',
        'request_id' => $requestId,
    ]);
} else {
    $err = $classified['status'];
    if ($classified['error_message']) {
        $err .= ': ' . $classified['error_message'];
    }
    echo json_encode([
        'success'    => false,
        'error'      => $err,
        'http_status' => $sendResult['http_status'],
        'fcm_status' => $classified['status'],
    ]);
}
