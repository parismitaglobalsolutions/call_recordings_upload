<?php
/**
 * Call Report Data API - Server-side processing for DataTables
 * Supports pagination, search, sorting, and full export
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Check login
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDB();

    // Get parameters
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('-1 day'));
    $selectedUser = $_GET['user_id'] ?? '';
    $selectedDepartment = $_GET['department_id'] ?? '';
    $export = isset($_GET['export']) && $_GET['export'] === 'true';

    // DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 25;
    $searchValue = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $orderColumn = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $orderDir = (isset($_GET['order'][0]['dir']) && $_GET['order'][0]['dir'] === 'asc') ? 'ASC' : 'DESC';

    // Column mapping for sorting (with table prefix)
    $columnMapping = [
        0 => 'c.date',
        1 => 'u.user_id',
        2 => 'd.department_name',
        3 => 'c.date', // total_incoming_calls - can't sort by aggregate, use date
        4 => 'c.date', // total_incoming_duration - can't sort by aggregate, use date
        5 => 'c.date', // total_outgoing_calls - can't sort by aggregate, use date
        6 => 'c.date', // target - computed field
        7 => 'c.date', // shortfall - computed field
        8 => 'c.date', // total_outgoing_duration - can't sort by aggregate, use date
        9 => 'c.date', // total_call_time - can't sort by aggregate, use date
    ];
    $orderColumnName = isset($columnMapping[$orderColumn]) ? $columnMapping[$orderColumn] : 'c.date';

    // Get all target history for lookup
    $targetHistory = $db->fetchAll(
        "SELECT user_id, target, DATE(target_started) as target_date
         FROM target_history
         ORDER BY user_id, target_started ASC"
    );

    // Build target lookup array
    $targetLookup = [];
    foreach ($targetHistory as $th) {
        if (!isset($targetLookup[$th['user_id']])) {
            $targetLookup[$th['user_id']] = [];
        }
        $targetLookup[$th['user_id']][] = [
            'date' => $th['target_date'],
            'target' => $th['target']
        ];
    }

    // Function to get applicable target for a user on a specific date
    function getTargetForDate($userId, $date, $targetLookup) {
        if (!isset($targetLookup[$userId]) || empty($targetLookup[$userId])) {
            return 0;
        }

        $targets = $targetLookup[$userId];
        $applicableTarget = 0;

        foreach ($targets as $t) {
            if ($t['date'] <= $date) {
                $applicableTarget = $t['target'];
            } else {
                break;
            }
        }

        return $applicableTarget;
    }

    // Format duration
    function formatDuration($seconds) {
        if ($seconds <= 0) return '0m 0s';
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm ' . $secs . 's';
        }
        return $minutes . 'm ' . $secs . 's';
    }

    // Base query
    $baseQuery = "FROM calls c
                  INNER JOIN users u ON c.user_id = u.user_id
                  LEFT JOIN departments d ON u.department_id = d.id
                  WHERE c.date BETWEEN ? AND ?";

    $params = [$dateFrom, $dateTo];

    if ($selectedUser) {
        $baseQuery .= " AND u.user_id = ?";
        $params[] = $selectedUser;
    }

    if ($selectedDepartment) {
        $baseQuery .= " AND u.department_id = ?";
        $params[] = $selectedDepartment;
    }

    // Add search filter
    if ($searchValue) {
        $baseQuery .= " AND (u.user_id LIKE ? OR d.department_name LIKE ?)";
        $params[] = "%$searchValue%";
        $params[] = "%$searchValue%";
    }

    // Get total count (without search filter for recordsTotal)
    $countParams = [$dateFrom, $dateTo];
    $countQuery = "SELECT COUNT(DISTINCT CONCAT(c.date, '-', u.id)) as total
                   FROM calls c
                   INNER JOIN users u ON c.user_id = u.user_id
                   LEFT JOIN departments d ON u.department_id = d.id
                   WHERE c.date BETWEEN ? AND ?";

    if ($selectedUser) {
        $countQuery .= " AND u.user_id = ?";
        $countParams[] = $selectedUser;
    }

    if ($selectedDepartment) {
        $countQuery .= " AND u.department_id = ?";
        $countParams[] = $selectedDepartment;
    }

    $totalResult = $db->fetch($countQuery, $countParams);
    $recordsTotal = $totalResult ? ($totalResult['total'] ?? 0) : 0;

    // Get filtered count
    $filteredCountQuery = "SELECT COUNT(DISTINCT CONCAT(c.date, '-', u.id)) as total " . $baseQuery;
    $filteredResult = $db->fetch($filteredCountQuery, $params);
    $recordsFiltered = $filteredResult ? ($filteredResult['total'] ?? 0) : 0;

    // Main data query
    $dataQuery = "SELECT
                    c.date,
                    u.user_id,
                    u.id as user_table_id,
                    u.target as user_target,
                    u.target_started as user_target_started,
                    d.department_name,
                    COUNT(CASE WHEN c.direction = 'incoming' THEN 1 END) as total_incoming_calls,
                    COALESCE(SUM(CASE WHEN c.direction = 'incoming' THEN c.call_duration END), 0) as total_incoming_duration,
                    COUNT(CASE WHEN c.direction = 'outgoing' THEN 1 END) as total_outgoing_calls,
                    COALESCE(SUM(CASE WHEN c.direction = 'outgoing' THEN c.call_duration END), 0) as total_outgoing_duration,
                    COALESCE(SUM(c.call_duration), 0) as total_call_time
                  " . $baseQuery . "
                  GROUP BY c.date, u.id, u.user_id, d.department_name, u.target, u.target_started
                  ORDER BY $orderColumnName $orderDir";

    // Add pagination only if not exporting
    if (!$export) {
        $dataQuery .= " LIMIT " . intval($length) . " OFFSET " . intval($start);
    }

    $reportData = $db->fetchAll($dataQuery, $params);

    // Format data for response
    $data = [];
    foreach ($reportData as $row) {
        $target = getTargetForDate($row['user_table_id'], $row['date'], $targetLookup);
        if ($target == 0) {
            $target = getTargetForDate($row['user_id'], $row['date'], $targetLookup);
        }
        $outgoingCalls = $row['total_outgoing_calls'];
        $shortfall = $target - $outgoingCalls;

        $data[] = [
            'date' => date('d M Y', strtotime($row['date'])),
            'date_raw' => $row['date'],
            'user_id' => $row['user_id'],
            'user_table_id' => $row['user_table_id'],
            'user_target' => $row['user_target'],
            'user_target_started' => $row['user_target_started'] ? date('Y-m-d', strtotime($row['user_target_started'])) : '',
            'department_name' => $row['department_name'] ?? '-',
            'total_incoming_calls' => (int)$row['total_incoming_calls'],
            'total_incoming_duration' => formatDuration($row['total_incoming_duration']),
            'total_outgoing_calls' => (int)$row['total_outgoing_calls'],
            'target' => $target > 0 ? $target : '-',
            'target_raw' => $target,
            'shortfall' => $target > 0 ? ($shortfall > 0 ? $shortfall : 0) : '-',
            'shortfall_raw' => $shortfall,
            'shortfall_class' => $target > 0 ? ($shortfall > 0 ? 'shortfall-negative' : 'shortfall-positive') : 'no-target',
            'total_outgoing_duration' => formatDuration($row['total_outgoing_duration']),
            'total_call_time' => formatDuration($row['total_call_time'])
        ];
    }

    // Return response
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => (int)$recordsTotal,
        'recordsFiltered' => (int)$recordsFiltered,
        'data' => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'draw' => isset($draw) ? $draw : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage()
    ]);
}
