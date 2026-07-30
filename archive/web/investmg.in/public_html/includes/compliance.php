<?php
/**
 * Compliance Calculation Logic
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/database.php';

class ComplianceCalculator {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Match recordings to calls for a user on a specific date
     * Uses direct call_id matching (recording is always a child of a call)
     */
    public function matchRecordingsToCall($userId, $date) {
        // Get all non-compliant calls for this user and date
        $calls = $this->db->fetchAll(
            "SELECT * FROM calls WHERE user_id = ? AND date = ? AND is_compliant = 0",
            [$userId, $date]
        );

        foreach ($calls as $call) {
            // Find the recording that belongs to THIS call using call_id
            $recording = $this->db->fetch(
                "SELECT * FROM recordings WHERE call_id = ? AND user_id = ? AND date = ?",
                [$call['call_id'], $userId, $date]
            );

            if (!$recording) {
                // No recording exists for this call
                $this->db->update(
                    "UPDATE calls SET reason = ? WHERE id = ?",
                    ["No recording found for this call", $call['id']]
                );
                continue;
            }

            // Always link recording to its call (relationship)
            if ($recording['matched_call_id'] === null) {
                $this->db->update(
                    "UPDATE recordings SET matched_call_id = ? WHERE id = ?",
                    [$call['id'], $recording['id']]
                );
            }

            // Now check compliance using the call's OWN recording
            $callStart = strtotime($call['call_start_time']);
            $callDuration = $call['call_duration'];
            $recordingStart = strtotime($recording['recording_start_time']);
            $recordingDuration = $recording['recording_duration'];

            $timeDiff = $recordingStart - $callStart;
            $timeDiffAbs = abs($timeDiff);

            $minStart = $callStart - RECORDING_START_TOLERANCE_BEFORE;
            $maxStart = $callStart + RECORDING_START_TOLERANCE_AFTER;
            $minDuration = $callDuration * (RECORDING_DURATION_MIN_PERCENT / 100);
            $durationPercent = $callDuration > 0 ? round(($recordingDuration / $callDuration) * 100) : 0;

            $timeOk = ($recordingStart >= $minStart && $recordingStart <= $maxStart);
            $durationOk = ($recordingDuration >= $minDuration);

            if ($timeOk && $durationOk) {
                // Compliant
                $timeText = $timeDiff >= 0 ? "{$timeDiffAbs}s after call" : "{$timeDiffAbs}s before call";
                $reason = "Recording OK - started {$timeText}, captured {$durationPercent}%";

                $this->db->update(
                    "UPDATE calls SET is_compliant = 1, reason = ? WHERE id = ?",
                    [$reason, $call['id']]
                );
            } else {
                // Non-compliant — generate accurate reason from its OWN recording
                $reason = $this->determineNonCompliantReason($call, $recording);
                $this->db->update(
                    "UPDATE calls SET reason = ? WHERE id = ?",
                    [$reason, $call['id']]
                );
            }
        }
    }

    /**
     * Determine reason why a call is non-compliant based on its own recording
     */
    private function determineNonCompliantReason($call, $recording) {
        $callStart = strtotime($call['call_start_time']);
        $callDuration = $call['call_duration'];
        $recordingStart = strtotime($recording['recording_start_time']);
        $recordingDuration = $recording['recording_duration'];

        $timeDiff = $recordingStart - $callStart;
        $timeDiffAbs = abs($timeDiff);

        $minStart = $callStart - RECORDING_START_TOLERANCE_BEFORE;
        $maxStart = $callStart + RECORDING_START_TOLERANCE_AFTER;
        $minDuration = $callDuration * (RECORDING_DURATION_MIN_PERCENT / 100);
        $durationPercent = $callDuration > 0 ? round(($recordingDuration / $callDuration) * 100) : 0;

        $timeOk = ($recordingStart >= $minStart && $recordingStart <= $maxStart);
        $durationOk = ($recordingDuration >= $minDuration);

        if (!$timeOk && !$durationOk) {
            if ($timeDiff > 0) {
                return "Recording started too late ({$timeDiffAbs}s after call, limit: " . RECORDING_START_TOLERANCE_AFTER . "s) and too short ({$durationPercent}%, need: " . RECORDING_DURATION_MIN_PERCENT . "%)";
            } else {
                return "Recording started too early ({$timeDiffAbs}s before call, limit: " . RECORDING_START_TOLERANCE_BEFORE . "s) and too short ({$durationPercent}%, need: " . RECORDING_DURATION_MIN_PERCENT . "%)";
            }
        } elseif (!$timeOk) {
            if ($timeDiff > 0) {
                return "Recording started too late ({$timeDiffAbs}s after call, limit: " . RECORDING_START_TOLERANCE_AFTER . "s)";
            } else {
                return "Recording started too early ({$timeDiffAbs}s before call, limit: " . RECORDING_START_TOLERANCE_BEFORE . "s)";
            }
        } elseif (!$durationOk) {
            return "Recording too short ({$durationPercent}% of call, need: " . RECORDING_DURATION_MIN_PERCENT . "%)";
        }

        return "Recording does not meet compliance criteria";
    }

    /**
     * Calculate compliance for a user on a specific date
     */
    public function calculateCompliance($userId, $date) {
        // First match recordings to calls
        $this->matchRecordingsToCall($userId, $date);

        // Calculate incoming compliance
        $incoming = $this->db->fetch(
            "SELECT
                COUNT(*) as total,
                SUM(is_compliant) as recorded
             FROM calls
             WHERE user_id = ? AND date = ? AND direction = 'incoming'",
            [$userId, $date]
        );

        // Calculate outgoing compliance
        $outgoing = $this->db->fetch(
            "SELECT
                COUNT(*) as total,
                SUM(is_compliant) as recorded
             FROM calls
             WHERE user_id = ? AND date = ? AND direction = 'outgoing'",
            [$userId, $date]
        );

        $incomingTotal = (int)$incoming['total'];
        $incomingRecorded = (int)$incoming['recorded'];
        $outgoingTotal = (int)$outgoing['total'];
        $outgoingRecorded = (int)$outgoing['recorded'];

        $totalCalls = $incomingTotal + $outgoingTotal;
        $totalRecorded = $incomingRecorded + $outgoingRecorded;

        // Calculate percentages
        $incomingCompliance = $incomingTotal > 0 ? ($incomingRecorded / $incomingTotal) * 100 : 100;
        $outgoingCompliance = $outgoingTotal > 0 ? ($outgoingRecorded / $outgoingTotal) * 100 : 100;
        $overallCompliance = $totalCalls > 0 ? ($totalRecorded / $totalCalls) * 100 : 100;

        // Determine status
        $status = 'red';
        if ($overallCompliance >= COMPLIANCE_GREEN) {
            $status = 'green';
        } elseif ($overallCompliance >= COMPLIANCE_YELLOW) {
            $status = 'yellow';
        }

        // Insert or update compliance result
        $existing = $this->db->fetch(
            "SELECT id FROM compliance_results WHERE user_id = ? AND date = ?",
            [$userId, $date]
        );

        if ($existing) {
            $this->db->update(
                "UPDATE compliance_results SET
                    total_calls = ?, recorded_calls = ?,
                    incoming_total = ?, incoming_recorded = ?, incoming_compliance = ?,
                    outgoing_total = ?, outgoing_recorded = ?, outgoing_compliance = ?,
                    overall_compliance = ?, status = ?, updated_at = NOW()
                 WHERE user_id = ? AND date = ?",
                [
                    $totalCalls, $totalRecorded,
                    $incomingTotal, $incomingRecorded, $incomingCompliance,
                    $outgoingTotal, $outgoingRecorded, $outgoingCompliance,
                    $overallCompliance, $status,
                    $userId, $date
                ]
            );
        } else {
            $this->db->insert(
                "INSERT INTO compliance_results
                    (user_id, date, total_calls, recorded_calls,
                     incoming_total, incoming_recorded, incoming_compliance,
                     outgoing_total, outgoing_recorded, outgoing_compliance,
                     overall_compliance, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId, $date, $totalCalls, $totalRecorded,
                    $incomingTotal, $incomingRecorded, $incomingCompliance,
                    $outgoingTotal, $outgoingRecorded, $outgoingCompliance,
                    $overallCompliance, $status
                ]
            );
        }

        return [
            'user_id' => $userId,
            'date' => $date,
            'total_calls' => $totalCalls,
            'recorded_calls' => $totalRecorded,
            'incoming' => [
                'total' => $incomingTotal,
                'recorded' => $incomingRecorded,
                'compliance' => round($incomingCompliance, 2)
            ],
            'outgoing' => [
                'total' => $outgoingTotal,
                'recorded' => $outgoingRecorded,
                'compliance' => round($outgoingCompliance, 2)
            ],
            'overall_compliance' => round($overallCompliance, 2),
            'status' => $status
        ];
    }

    /**
     * Calculate talk time statistics
     */
    public function calculateTalkTime($userId, $date) {
        $directions = ['incoming', 'outgoing'];
        $results = [];

        foreach ($directions as $direction) {
            $calls = $this->db->fetchAll(
                "SELECT call_duration FROM calls WHERE user_id = ? AND date = ? AND direction = ?",
                [$userId, $date, $direction]
            );

            $totalDuration = 0;
            $buckets = [
                '0_2' => 0,
                '2_5' => 0,
                '5_10' => 0,
                '10_plus' => 0
            ];

            foreach ($calls as $call) {
                $duration = $call['call_duration'];
                $totalDuration += $duration;
                $minutes = $duration / 60;

                if ($minutes < 2) {
                    $buckets['0_2']++;
                } elseif ($minutes < 5) {
                    $buckets['2_5']++;
                } elseif ($minutes < 10) {
                    $buckets['5_10']++;
                } else {
                    $buckets['10_plus']++;
                }
            }

            // Insert or update talk time stats
            $existing = $this->db->fetch(
                "SELECT id FROM talk_time_stats WHERE user_id = ? AND date = ? AND direction = ?",
                [$userId, $date, $direction]
            );

            if ($existing) {
                $this->db->update(
                    "UPDATE talk_time_stats SET
                        total_duration = ?, bucket_0_2 = ?, bucket_2_5 = ?,
                        bucket_5_10 = ?, bucket_10_plus = ?, updated_at = NOW()
                     WHERE user_id = ? AND date = ? AND direction = ?",
                    [
                        $totalDuration, $buckets['0_2'], $buckets['2_5'],
                        $buckets['5_10'], $buckets['10_plus'],
                        $userId, $date, $direction
                    ]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO talk_time_stats
                        (user_id, date, direction, total_duration, bucket_0_2, bucket_2_5, bucket_5_10, bucket_10_plus)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $userId, $date, $direction, $totalDuration,
                        $buckets['0_2'], $buckets['2_5'], $buckets['5_10'], $buckets['10_plus']
                    ]
                );
            }

            $results[$direction] = [
                'total_duration' => $totalDuration,
                'total_duration_formatted' => $this->formatDuration($totalDuration),
                'buckets' => $buckets
            ];
        }

        return $results;
    }

    /**
     * Format duration in seconds to human readable
     */
    private function formatDuration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf("%dh %dm %ds", $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf("%dm %ds", $minutes, $secs);
        } else {
            return sprintf("%ds", $secs);
        }
    }

    /**
     * Get non-compliant calls for a user on a date
     */
    public function getNonCompliantCalls($userId, $date) {
        return $this->db->fetchAll(
            "SELECT * FROM calls
             WHERE user_id = ? AND date = ? AND is_compliant = 0
             ORDER BY call_start_time",
            [$userId, $date]
        );
    }
}

