<?php
/**
 * List Monthly Summary PDFs from OneDrive via Microsoft Graph API
 * Returns JSON array of PDF files from Recordings/{UserName}/Monthly_Summary/
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

requireLogin();

$username = $_GET['username'] ?? '';
$username = trim($username);

if (empty($username)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: username']);
    exit;
}

// Path: /Recordings/{UserName}/Monthly_Summary
// Use original casing and spaces for folder path - matches actual OneDrive structure
// e.g. "Pradhan Singh" => /Recordings/Pradhan Singh/Monthly_Summary
$folderPath = ONEDRIVE_BASE_PATH . '/' . $username . '/Monthly_Summary';

// Encode each path segment individually to handle spaces and special characters
$encodedFolderPath = implode('/', array_map('rawurlencode', explode('/', $folderPath)));

$graphBaseUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode(ONEDRIVE_DRIVE_OWNER) . '/drive';

try {
    $accessToken = getGraphAccessToken();
    $result = listFolderChildren($graphBaseUrl, $encodedFolderPath, $accessToken);

    // If 401, regenerate token and retry once
    if ($result['httpCode'] === 401) {
        $accessToken = regenerateGraphAccessToken();
        $result = listFolderChildren($graphBaseUrl, $encodedFolderPath, $accessToken);
    }

    if ($result['error']) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to connect to OneDrive: ' . $result['error']]);
        exit;
    }

    if ($result['httpCode'] === 404) {
        // Folder doesn't exist - return empty list
        echo json_encode(['files' => []]);
        exit;
    }

    if ($result['httpCode'] !== 200) {
        http_response_code(502);
        echo json_encode(['error' => 'OneDrive returned error (HTTP ' . $result['httpCode'] . ')']);
        exit;
    }

    $data = json_decode($result['body'], true);
    $subfolders = $data['value'] ?? [];

    // For each subfolder (e.g., 01_26), list PDF files inside
    $files = [];
    foreach ($subfolders as $item) {
        if (!isset($item['folder'])) {
            continue; // Skip non-folder items
        }

        $subfolderName = $item['name']; // e.g., "01_26"
        $subfolderPath = $folderPath . '/' . $subfolderName;

        // Encode subfolder path for each iteration
        $encodedSubfolderPath = implode('/', array_map('rawurlencode', explode('/', $subfolderPath)));

        $subResult = listFolderChildren($graphBaseUrl, $encodedSubfolderPath, $accessToken);

        if ($subResult['httpCode'] === 401) {
            $accessToken = regenerateGraphAccessToken();
            $subResult = listFolderChildren($graphBaseUrl, $encodedSubfolderPath, $accessToken);
        }

        if ($subResult['httpCode'] !== 200) {
            continue; // Skip folders we can't read
        }

        $subData = json_decode($subResult['body'], true);
        $children = $subData['value'] ?? [];

        foreach ($children as $child) {
            if (isset($child['file']) && strtolower(pathinfo($child['name'], PATHINFO_EXTENSION)) === 'pdf') {
                $files[] = [
                    'name' => $child['name'],
                    'path' => $subfolderPath . '/' . $child['name'], // raw path (unencoded) for passing to view-summary.php
                    'size' => $child['size'] ?? 0,
                    'folder' => $subfolderName
                ];
            }
        }
    }

    echo json_encode(['files' => $files]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * List children of a OneDrive folder
 * Expects $folderPath to already be percent-encoded
 */
function listFolderChildren($graphBaseUrl, $folderPath, $accessToken) {
    $url = $graphBaseUrl . '/root:' . $folderPath . ':/children';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT => 15
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

