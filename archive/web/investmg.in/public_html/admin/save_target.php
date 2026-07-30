<?php
/**
 * Save Target API Endpoint
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$id = $input['id'] ?? null;
$targetStarted = $input['target_started'] ?? null;
$target = $input['target'] ?? null;

if (!$id || !$targetStarted || $target === null) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate target value
$target = floatval($target);
if ($target < 0 || $target > 100) {
    echo json_encode(['success' => false, 'message' => 'Target must be between 0 and 100']);
    exit;
}

$db = getDB();

try {
    // Check for duplicate (same date and target)
    $existing = $db->fetch(
        "SELECT id FROM target_history WHERE user_id = ? AND target = ? AND DATE(target_started) = ?",
        [$id, $target, $targetStarted]
    );

    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'This target with the same date already exists']);
        exit;
    }

    // Insert into target_history
    $db->insert(
        "INSERT INTO target_history (user_id, target, target_started) VALUES (?, ?, ?)",
        [$id, $target, $targetStarted]
    );

    // Update users table with latest target
    $db->update(
        "UPDATE users SET target = ?, target_started = ? WHERE id = ?",
        [$target, $targetStarted, $id]
    );

    echo json_encode(['success' => true, 'message' => 'Target saved successfully']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
