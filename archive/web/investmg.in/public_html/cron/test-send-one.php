<?php
/**
 * One-off: send a silent health-check-style FCM message to a hardcoded token.
 * Usage: php cron/test-send-one.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../includes/fcm.php';

$deviceToken = 'dc1dYoH-TbKZY8igRZVUUO:APA91bF6s-mYsPEyMQVvNG7H2NbmxShkULkflBdxhlhJthaDmc6JXHNJzmwPIuRjBP_J9lE-y3RkrtXbxtwrwKHnT_2SFCIL0u_gim3WzzOaDDuLyg2cVoc';

$t = fcm_get_access_token();
if (isset($t['error'])) {
    echo "AUTH FAIL: " . $t['error'] . PHP_EOL;
    exit(1);
}

$requestId = sprintf('hc_%s_%04x', date('Ymd_His'), random_int(0, 0xffff));

$result = fcm_send_message(
    $t['access_token'],
    $t['project_id'],
    $deviceToken,
    ['type' => 'health_check', 'request_id' => $requestId],
    ['title' => 'Test Notification', 'body' => 'Health check test from server']
);

$classified = fcm_classify_response($result);

echo "request_id:  {$requestId}" . PHP_EOL;
echo "http_status: {$result['http_status']}" . PHP_EOL;
echo "fcm_status:  {$classified['status']}" . PHP_EOL;
echo "action:      {$classified['action']}" . PHP_EOL;
if ($classified['error_message']) echo "error:       {$classified['error_message']}" . PHP_EOL;
echo "raw:         " . $result['raw_response'] . PHP_EOL;
