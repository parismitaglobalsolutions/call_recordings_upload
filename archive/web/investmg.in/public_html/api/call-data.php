<?php
/**
 * API Endpoint: Receive Call Data
 * POST /api/call-data.php
 *
 * Stores raw JSON data for later processing via admin panel
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

// Required fields validation
$requiredFields = ['user_id', 'date', 'upload_time', 'calls'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

if (!is_array($data['calls'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Calls must be an array']);
    exit;
}

try {
    $db = getDB();

    // Store raw JSON data for later processing
    $db->insert(
        "INSERT INTO raw_data (json_data, is_processed) VALUES (?, 0)",
        [json_encode($data)]
    );

    // Return success immediately - processing will happen via admin panel
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Data received successfully. Processing will be done separately.',
        'data' => [
            'user_id' => $data['user_id'],
            'date' => $data['date'],
            'calls_count' => count($data['calls'])
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
