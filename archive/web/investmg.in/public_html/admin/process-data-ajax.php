<?php
/**
 * AJAX Endpoint for Processing Raw Data
 * Handles individual record processing with transaction/rollback support
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/compliance.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'get_pending_ids':
        getPendingIds();
        break;

    case 'process_record':
        $id = (int)($_GET['id'] ?? 0);
        processRecord($id);
        break;

    case 'get_stats':
        getStats();
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Get all pending record IDs
 */
function getPendingIds() {
    global $db;

    $records = $db->fetchAll("SELECT id FROM raw_data WHERE is_processed = 0 ORDER BY created_at ASC");
    $ids = array_column($records, 'id');

    echo json_encode(['success' => true, 'ids' => $ids]);
}

/**
 * Process a single record with transaction support
 */
function processRecord($id) {
    global $db;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid record ID']);
        return;
    }

    // Fetch the raw record
    $record = $db->fetch("SELECT * FROM raw_data WHERE id = ? AND is_processed = 0", [$id]);

    if (!$record) {
        echo json_encode(['success' => false, 'error' => 'Record not found or already processed']);
        return;
    }

    // Parse JSON data
    $data = json_decode($record['json_data'], true);

    if (!$data) {
        markAsFailed($id, 'Invalid JSON data');
        echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
        return;
    }

    // Start transaction
    $db->beginTransaction();

    try {
        $userId = $data['user_id'];
        $date = $data['date'];
        $uploadTime = $data['upload_time'];
        $departmentId = isset($data['department_id']) ? (int)$data['department_id'] : null;

        // Create or get user
        $existingUser = $db->fetch("SELECT id, department_id FROM users WHERE user_id = ?", [$userId]);
        if (!$existingUser) {
            // New user - insert with department_id
            $db->insert("INSERT INTO users (user_id, department_id) VALUES (?, ?)", [$userId, $departmentId]);
        } elseif ($departmentId !== null && $existingUser['department_id'] === null) {
            // Existing user with no department - update department_id
            $db->update("UPDATE users SET department_id = ? WHERE user_id = ?", [$departmentId, $userId]);
        }
        // If user exists and department_id is the same, no update needed

        $callsProcessed = 0;
        $recordingsProcessed = 0;
        $errors = [];

        // Process each call
        foreach ($data['calls'] as $index => $call) {
            // Validate call data
            if (!isset($call['call_id'], $call['start_time'], $call['duration_sec'], $call['direction'])) {
                $errors[] = "Call at index $index missing required fields";
                continue;
            }

            // Check if call already exists
            $existingCall = $db->fetch(
                "SELECT id FROM calls WHERE user_id = ? AND call_id = ? AND date = ?",
                [$userId, $call['call_id'], $date]
            );

            if (!$existingCall) {
                $db->insert(
                    "INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $userId,
                        $call['call_id'],
                        $call['start_time'],
                        $call['duration_sec'],
                        $call['direction'],
                        $call['sim_slot'] ?? 1,
                        $date
                    ]
                );
                $callsProcessed++;
            }

            // Process recording if present
            if (isset($call['recording']) && is_array($call['recording'])) {
                $recording = $call['recording'];

                if (isset($recording['file_name'], $recording['start_time'], $recording['duration_sec'])) {
                    $existingRecording = $db->fetch(
                        "SELECT id FROM recordings WHERE call_id = ? AND user_id = ? AND date = ?",
                        [$call['call_id'], $userId, $date]
                    );

                    if (!$existingRecording) {
                        $db->insert(
                            "INSERT INTO recordings (call_id, user_id, file_name, recording_start_time, recording_duration, date)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [
                                $call['call_id'],
                                $userId,
                                $recording['file_name'],
                                $recording['start_time'],
                                $recording['duration_sec'],
                                $date
                            ]
                        );
                        $recordingsProcessed++;
                    }
                }
            }
        }

        // Log the upload
        $db->insert(
            "INSERT INTO upload_logs (user_id, date, upload_time, calls_count, recordings_count)
             VALUES (?, ?, ?, ?, ?)",
            [$userId, $date, $uploadTime, $callsProcessed, $recordingsProcessed]
        );

        // Calculate compliance and talk time
        $compliance = new ComplianceCalculator();
        $complianceResult = $compliance->calculateCompliance($userId, $date);
        $compliance->calculateTalkTime($userId, $date);

        // Mark record as processed
        $db->update(
            "UPDATE raw_data SET is_processed = 1, processed_at = NOW(), process_error = NULL WHERE id = ?",
            [$id]
        );

        // Commit transaction
        $db->commit();

        $message = "User: {$userId}, Date: {$date}, Calls: {$callsProcessed}, Recordings: {$recordingsProcessed}";
        if (!empty($errors)) {
            $message .= " (with " . count($errors) . " warnings)";
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'user_id' => $userId,
                'date' => $date,
                'calls_processed' => $callsProcessed,
                'recordings_processed' => $recordingsProcessed,
                'compliance' => $complianceResult
            ]
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollback();
        }

        // Mark record as failed
        markAsFailed($id, $e->getMessage());

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Mark a record as failed (is_processed = 2)
 */
function markAsFailed($id, $error) {
    global $db;

    try {
        $db->update(
            "UPDATE raw_data SET is_processed = 2, process_error = ?, processed_at = NOW() WHERE id = ?",
            [$error, $id]
        );
    } catch (Exception $e) {
        // Ignore errors when marking as failed
    }
}

/**
 * Get processing statistics
 */
function getStats() {
    global $db;

    $pending = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 0");
    $processed = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 1");
    $failed = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 2");

    echo json_encode([
        'success' => true,
        'stats' => [
            'pending' => (int)$pending['count'],
            'processed' => (int)$processed['count'],
            'failed' => (int)$failed['count']
        ]
    ]);
}
