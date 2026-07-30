<?php
/**
 * Cron: Send silent FCM health-check ping to every active user.
 * Expects Android to respond by calling /api/health-check.php.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fcm.php';

function logMessage(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

$db = getDB();

$tokenResult = fcm_get_access_token();
if (isset($tokenResult['error'])) {
    logMessage('FATAL: ' . $tokenResult['error']);
    exit(1);
}
$accessToken = $tokenResult['access_token'];
$projectId   = $tokenResult['project_id'];

$users = $db->fetchAll(
    "SELECT user_id, fcm_token
       FROM users
      WHERE fcm_token IS NOT NULL
        AND fcm_token != ''
        AND (device_status IS NULL OR device_status != 'invalid_token')"
);

if (empty($users)) {
    logMessage('No users with valid FCM token. Exiting.');
    exit(0);
}

logMessage('Sending health-check to ' . count($users) . ' user(s)...');

$counts = ['success' => 0, 'invalid_token' => 0, 'transient' => 0, 'config_error' => 0, 'unknown' => 0];

foreach ($users as $user) {
    $requestId = sprintf('hc_%s_%04x', date('Ymd_His'), random_int(0, 0xffff));

    $sendResult = fcm_send_message(
        $accessToken,
        $projectId,
        $user['fcm_token'],
        ['type' => 'health_check', 'request_id' => $requestId]
    );

    $classified = fcm_classify_response($sendResult);
    $action     = $classified['action'];
    $counts[$action]++;

    $db->insert(
        "INSERT INTO fcm_notifications
            (user_id, fcm_token_used, request_id, type, http_status, fcm_status,
             error_message, raw_response, sent_at)
         VALUES (?, ?, ?, 'health_check', ?, ?, ?, ?, NOW())",
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

    if ($action === 'invalid_token') {
        $db->update(
            "UPDATE users
                SET device_status = 'invalid_token',
                    fcm_token = NULL,
                    last_notification_sent_at = NOW(),
                    last_notification_status = ?
              WHERE user_id = ?",
            [$classified['status'], $user['user_id']]
        );
        logMessage("User {$user['user_id']}: token invalidated ({$classified['status']})");
    } else {
        $db->update(
            "UPDATE users
                SET last_notification_sent_at = NOW(),
                    last_notification_status = ?
              WHERE user_id = ?",
            [$classified['status'], $user['user_id']]
        );
    }

    if ($action === 'config_error') {
        logMessage("FATAL: Config error ({$classified['status']}) - aborting loop");
        break;
    }
}

logMessage(sprintf(
    'Done. success=%d invalid_token=%d transient=%d config_error=%d unknown=%d',
    $counts['success'],
    $counts['invalid_token'],
    $counts['transient'],
    $counts['config_error'],
    $counts['unknown']
));
