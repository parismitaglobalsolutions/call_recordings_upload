<?php
/**
 * Firebase Cloud Messaging (FCM) helpers.
 * Shared between cron jobs and API endpoints.
 */

require_once __DIR__ . '/../config/database.php';

function fcm_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Obtain a Google OAuth2 access token via the Firebase service-account JWT flow.
 * Returns ['access_token' => ..., 'project_id' => ...] or ['error' => ...].
 */
function fcm_get_access_token(): array {
    if (!file_exists(FCM_SERVICE_ACCOUNT_JSON)) {
        return ['error' => 'Service account file not found: ' . FCM_SERVICE_ACCOUNT_JSON];
    }
    $sa = json_decode(file_get_contents(FCM_SERVICE_ACCOUNT_JSON), true);
    if (!$sa || empty($sa['project_id']) || empty($sa['private_key']) || empty($sa['client_email'])) {
        return ['error' => 'Invalid or incomplete service account JSON'];
    }

    $now = time();
    $header  = fcm_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = fcm_base64url_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $unsigned  = $header . '.' . $payload;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        return ['error' => 'JWT signing failed: ' . openssl_error_string()];
    }
    $jwt = $unsigned . '.' . fcm_base64url_encode($signature);

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
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'OAuth curl error: ' . $err];
    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) {
        return ['error' => 'OAuth token request failed', 'http_status' => $code, 'response' => $data];
    }
    return [
        'access_token' => $data['access_token'],
        'project_id'   => $sa['project_id'],
    ];
}

/**
 * Send one FCM HTTP v1 message.
 * @param array $dataPayload      key/value strings for the data block
 * @param array|null $notification optional ['title', 'body']
 * @return array {http_status, raw_response, response_json, curl_error}
 */
function fcm_send_message(
    string $accessToken,
    string $projectId,
    string $deviceToken,
    array $dataPayload,
    ?array $notification = null
): array {
    $stringData = [];
    foreach ($dataPayload as $k => $v) {
        $stringData[$k] = is_string($v) ? $v : (string)$v;
    }

    $message = ['message' => [
        'token'   => $deviceToken,
        'data'    => $stringData,
        'android' => ['priority' => 'high'],
    ]];
    if ($notification) {
        $message['message']['notification'] = $notification;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($message),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'http_status'   => $code,
        'raw_response'  => $raw,
        'response_json' => $raw ? json_decode($raw, true) : null,
        'curl_error'    => $err ?: null,
    ];
}

/**
 * Classify an FCM response into an action bucket.
 * Returns ['status' => FCM-status-string, 'action' => one-of-{success, invalid_token,
 *   transient, config_error, unknown}, 'error_message' => string|null]
 */
function fcm_classify_response(array $result): array {
    if ($result['curl_error']) {
        return [
            'status'        => 'NETWORK_ERROR',
            'action'        => 'transient',
            'error_message' => $result['curl_error'],
        ];
    }
    $code = (int)$result['http_status'];
    $json = $result['response_json'];

    if ($code === 200) {
        return ['status' => 'SUCCESS', 'action' => 'success', 'error_message' => null];
    }

    $fcmStatus = 'UNKNOWN';
    $message   = null;
    if (is_array($json) && isset($json['error'])) {
        $fcmStatus = $json['error']['status']  ?? 'UNKNOWN';
        $message   = $json['error']['message'] ?? null;
        if (!empty($json['error']['details'])) {
            foreach ($json['error']['details'] as $d) {
                if (!empty($d['errorCode'])) {
                    $fcmStatus = $d['errorCode'];
                    break;
                }
            }
        }
    }

    $tokenLevel = ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH', 'NOT_FOUND'];
    $transient  = ['UNAVAILABLE', 'INTERNAL', 'DEADLINE_EXCEEDED', 'QUOTA_EXCEEDED', 'RESOURCE_EXHAUSTED', 'ABORTED'];
    $configErr  = ['UNAUTHENTICATED', 'PERMISSION_DENIED'];

    if (in_array($fcmStatus, $tokenLevel, true)) {
        return ['status' => $fcmStatus, 'action' => 'invalid_token', 'error_message' => $message];
    }
    if (in_array($fcmStatus, $transient, true)) {
        return ['status' => $fcmStatus, 'action' => 'transient', 'error_message' => $message];
    }
    if (in_array($fcmStatus, $configErr, true)) {
        return ['status' => $fcmStatus, 'action' => 'config_error', 'error_message' => $message];
    }

    if ($code === 404 || $code === 400) {
        return ['status' => $fcmStatus, 'action' => 'invalid_token', 'error_message' => $message];
    }
    if ($code >= 500 || $code === 429) {
        return ['status' => $fcmStatus, 'action' => 'transient', 'error_message' => $message];
    }
    return ['status' => $fcmStatus, 'action' => 'unknown', 'error_message' => $message];
}
