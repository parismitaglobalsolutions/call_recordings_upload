<?php
/**
 * View/Download Summary PDF - Fetches PDF from OneDrive via Microsoft Graph API
 * Supports two modes:
 *   1. Daily summary: ?username=X&date=YYYY-MM-DD (path auto-constructed)
 *   2. Generic path:  ?path=/Recordings/... (direct OneDrive path)
 * Add &download=1 to force download instead of inline view
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$download = isset($_GET['download']) && $_GET['download'] == '1';
$path = $_GET['path'] ?? '';

if (!empty($path)) {
    // Generic path mode - used by monthly summaries modal
    $filePath = $path;
    $fileName = basename($filePath);
} else {
    // Daily summary mode - construct path from username + date
    $username = $_GET['username'] ?? '';
    $username = trim($username);
    $date = $_GET['date'] ?? '';

    if (empty($username) || empty($date)) {
        http_response_code(400);
        echo 'Missing required parameters: provide either "path" or "username" and "date"';
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo 'Invalid date format. Expected YYYY-MM-DD';
        exit;
    }

    // Filename is lowercase with spaces preserved
    // Folder uses original casing and spaces from username (matches OneDrive structure)
    // e.g. username="Pradhan Singh"
    //   -> folder: /Pradhan Singh/
    //   -> file:   pradhan singh_summary.pdf
    $fileName = strtolower($username) . '_summary.pdf';
    $filePath = ONEDRIVE_BASE_PATH . '/' . $username . '/archive/' . $date . '/summary/' . $fileName;
}

// Encode each path segment individually to handle spaces and special characters.
// Splits on '/' to preserve path structure, encodes only the individual segments.
// e.g. "Pradhan Singh" => "Pradhan%20Singh", "pradhan singh_summary.pdf" => "pradhan%20singh_summary.pdf"
$encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));

$graphUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode(ONEDRIVE_DRIVE_OWNER)
          . '/drive/root:' . $encodedPath . ':/content';

try {
    // Use token from session
    $accessToken = getGraphAccessToken();
    $result = fetchPdfFromGraph($graphUrl, $accessToken);

    // If 401, regenerate token and retry once
    if ($result['httpCode'] === 401) {
        $accessToken = regenerateGraphAccessToken();
        $result = fetchPdfFromGraph($graphUrl, $accessToken);
    }

    if ($result['error']) {
        http_response_code(502);
        echo 'Failed to fetch file from OneDrive: ' . htmlspecialchars($result['error']);
        exit;
    }

    if ($result['httpCode'] === 404) {
        http_response_code(404);
        echo 'Summary PDF not found: ' . htmlspecialchars($fileName);
        exit;
    }

    if ($result['httpCode'] !== 200) {
        http_response_code(502);
        echo 'OneDrive returned error (HTTP ' . $result['httpCode'] . ')';
        exit;
    }

    // Serve the PDF
    $disposition = $download ? 'attachment' : 'inline';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($result['body']));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $result['body'];

} catch (Exception $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}

/**
 * Fetch PDF content from Graph API
 */
function fetchPdfFromGraph($url, $accessToken) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'body'     => $body,
        'httpCode' => $httpCode,
        'error'    => $error ?: null
    ];
}

