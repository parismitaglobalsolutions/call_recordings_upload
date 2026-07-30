<?php
/**
 * API Endpoint: Fetch Departments
 * GET /api/departments.php
 *
 * Returns list of all departments
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

try {
    $db = getDB();

    // Fetch all departments
    $departments = $db->fetchAll("SELECT id, department_name, created_at FROM departments ORDER BY department_name ASC");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $departments,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
