<?php
/**
 * API Endpoint: Send FCM Push Notification (DATA-ONLY, silent)
 * POST /api/send-notification.php
 *
 * Sends a SILENT, high-priority FCM **data** message to a device. This is the
 * primary trigger that makes the app upload recordings + report a health check,
 * even when the app is in the background or has been killed by the OS.
 *
 * IMPORTANT — why this is data-only (no "notification" block):
 *   - A "notification" message is handed to the system tray when the app is in
 *     the background/killed, and onMessageReceived is NEVER called → the upload
 *     would not run. A "data" message always reaches onMessageReceived.
 *   - The app must stay completely silent (no visible notification in any state).
 *   - android.priority = "high" wakes the device from Doze and reaches killed apps.
 *     (It cannot reach an app the user manually Force-Stopped in Settings — Android
 *      blocks delivery until the app is opened once.)
 *
 * Required POST JSON body:
 *   - fcm_token   (string) : the device FCM registration token
 *
 * Optional POST JSON body:
 *   - request_id  (string) : controls app behavior (see below).
 *                            Default: "hc_<unix_time>".
 *
 * request_id prefix → app behavior:
 *   - "hc_..."   : upload recordings, THEN send health check   (use for the daily trigger)
 *   - "adm_..."  : upload recordings, THEN send health check   (use from the admin panel)
 *   - anything else : send health check only, NO upload
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// 1. Read & validate input
// ---------------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['fcm_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: fcm_token']);
    exit;
}

$fcmToken = trim($input['fcm_token']);

// request_id prefix decides app behavior (see header docblock).
// Default to a daily-upload trigger ("hc_") if the caller didn't supply one.
$requestId = trim($input['request_id'] ?? ('hc_' . time()));

// ---------------------------------------------------------------------------
// 2. Load Firebase service account JSON
// ---------------------------------------------------------------------------
if (!file_exists(FCM_SERVICE_ACCOUNT_JSON)) {
    http_response_code(500);
    echo json_encode(['error' => 'Firebase service account JSON file not found at: ' . FCM_SERVICE_ACCOUNT_JSON]);
    exit;
}

$serviceAccount = json_decode(file_get_contents(FCM_SERVICE_ACCOUNT_JSON), true);

if (!$serviceAccount || empty($serviceAccount['project_id']) || empty($serviceAccount['private_key']) || empty($serviceAccount['client_email'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid or incomplete Firebase service account JSON']);
    exit;
}

$projectId   = $serviceAccount['project_id'];
$privateKey  = $serviceAccount['private_key'];
$clientEmail = $serviceAccount['client_email'];

// ---------------------------------------------------------------------------
// 3. Generate a Google OAuth2 access token via JWT (service account flow)
// ---------------------------------------------------------------------------
$now = time();
$jwtHeader  = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$jwtPayload = base64UrlEncode(json_encode([
    'iss'   => $clientEmail,
    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
]));

$unsignedJwt = $jwtHeader . '.' . $jwtPayload;

$signature = '';
if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to sign JWT: ' . openssl_error_string()]);
    exit;
}

$jwt = $unsignedJwt . '.' . base64UrlEncode($signature);

// Exchange JWT for access token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 10,
]);

$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tokenCurlErr  = curl_error($ch);
curl_close($ch);

if ($tokenCurlErr) {
    http_response_code(500);
    echo json_encode(['error' => 'OAuth token request failed: ' . $tokenCurlErr]);
    exit;
}

$tokenData = json_decode($tokenResponse, true);

if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
    http_response_code(500);
    echo json_encode([
        'error'             => 'Failed to obtain OAuth access token',
        'firebase_response' => $tokenData,
    ]);
    exit;
}

$accessToken = $tokenData['access_token'];

// ---------------------------------------------------------------------------
// 4. Send SILENT, high-priority DATA message via FCM HTTP v1 API
// ---------------------------------------------------------------------------
$fcmUrl = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

// NOTE: data values MUST be strings. NO "notification" block (keeps it silent
// and guarantees onMessageReceived runs in background/killed states).
$message = [
    'message' => [
        'token' => $fcmToken,
        'data'  => [
            'type'       => 'health_check',
            'request_id' => $requestId,
        ],
        'android' => [
            'priority' => 'high',
        ],
    ],
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $fcmUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($message),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$fcmResponse = curl_exec($ch);
$fcmHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$fcmCurlErr  = curl_error($ch);
curl_close($ch);

// ---------------------------------------------------------------------------
// 5. Return raw Firebase response
// ---------------------------------------------------------------------------
if ($fcmCurlErr) {
    http_response_code(500);
    echo json_encode(['error' => 'FCM request failed: ' . $fcmCurlErr]);
    exit;
}

// Forward Firebase's HTTP status code and raw response body as-is
http_response_code($fcmHttpCode);
echo $fcmResponse;

// ---------------------------------------------------------------------------
// Helper: base64url encoding (no padding, URL-safe)
// ---------------------------------------------------------------------------
function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

