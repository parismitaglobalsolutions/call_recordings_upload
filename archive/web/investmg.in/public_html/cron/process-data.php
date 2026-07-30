<?php
//Cron Job: Auto-process pending raw data

// Prevent browser access - CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

// Include required files (no auth.php needed - no session required for CLI)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/compliance.php';

$db = getDB();

// Log helper
function logMessage($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
}

// Get pending records
$records = $db->fetchAll("SELECT id FROM raw_data WHERE is_processed = 0 ORDER BY created_at ASC");

if (empty($records)) {
    logMessage("No pending records. Exiting.");
    exit(0);
}

logMessage("Found " . count($records) . " pending record(s). Starting processing...");

$success = 0;
$failed = 0;

foreach ($records as $record) {
    $id = $record['id'];

    // Fetch raw record
    $raw = $db->fetch("SELECT * FROM raw_data WHERE id = ? AND is_processed = 0", [$id]);

    if (!$raw) {
        logMessage("Record #{$id}: Skipped (not found or already processed)");
        continue;
    }

    // Parse JSON
    $data = json_decode($raw['json_data'], true);

    if (!$data) {
        markAsFailed($db, $id, 'Invalid JSON data');
        logMessage("Record #{$id}: FAILED - Invalid JSON data");
        $failed++;
        continue;
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
            $db->insert("INSERT INTO users (user_id, department_id) VALUES (?, ?)", [$userId, $departmentId]);
        } elseif ($departmentId !== null && $existingUser['department_id'] === null) {
            $db->update("UPDATE users SET department_id = ? WHERE user_id = ?", [$departmentId, $userId]);
        }

        $callsProcessed = 0;
        $recordingsProcessed = 0;

        // Process each call
        foreach ($data['calls'] as $index => $call) {
            if (!isset($call['call_id'], $call['start_time'], $call['duration_sec'], $call['direction'])) {
                continue;
            }

            // Check duplicate
            $existingCall = $db->fetch(
                "SELECT id FROM calls WHERE user_id = ? AND call_id = ? AND date = ?",
                [$userId, $call['call_id'], $date]
            );

            if (!$existingCall) {
                $db->insert(
                    "INSERT INTO calls (user_id, call_id, call_start_time, call_duration, direction, sim_slot, date)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$userId, $call['call_id'], $call['start_time'], $call['duration_sec'],
                     $call['direction'], $call['sim_slot'] ?? 1, $date]
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
                            [$call['call_id'], $userId, $recording['file_name'],
                             $recording['start_time'], $recording['duration_sec'], $date]
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
        $compliance->calculateCompliance($userId, $date);
        $compliance->calculateTalkTime($userId, $date);

        // Mark as processed
        $db->update(
            "UPDATE raw_data SET is_processed = 1, processed_at = NOW(), process_error = NULL WHERE id = ?",
            [$id]
        );

        $db->commit();
        $success++;
        logMessage("Record #{$id}: OK - User: {$userId}, Date: {$date}, Calls: {$callsProcessed}, Recordings: {$recordingsProcessed}");

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        markAsFailed($db, $id, $e->getMessage());
        $failed++;
        logMessage("Record #{$id}: FAILED - " . $e->getMessage());
    }
}

logMessage("Done. Success: {$success}, Failed: {$failed}");

function markAsFailed($db, $id, $error) {
    try {
        $db->update(
            "UPDATE raw_data SET is_processed = 2, process_error = ?, processed_at = NOW() WHERE id = ?",
            [$error, $id]
        );
    } catch (Exception $e) {
        // Ignore
    }
}
