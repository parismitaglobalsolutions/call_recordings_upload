<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/compliance.php';

requireLogin();

$db = getDB();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$selectedUser = $_GET['user_id'] ?? '';

// Recalculate compliance for filtered date range if needed
if (isset($_GET['refresh'])) {
    $compliance = new ComplianceCalculator();
    $users = $db->fetchAll("SELECT DISTINCT user_id FROM calls WHERE date BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    foreach ($users as $user) {
        $dates = $db->fetchAll(
            "SELECT DISTINCT date FROM calls WHERE user_id = ? AND date BETWEEN ? AND ?",
            [$user['user_id'], $dateFrom, $dateTo]
        );
        foreach ($dates as $dateRow) {
            $compliance->calculateCompliance($user['user_id'], $dateRow['date']);
            $compliance->calculateTalkTime($user['user_id'], $dateRow['date']);
        }
    }
}

// Get all users for filter dropdown
$allUsers = $db->fetchAll("SELECT DISTINCT user_id FROM users ORDER BY user_id");

// Build compliance query
$complianceQuery = "SELECT * FROM compliance_results WHERE date BETWEEN ? AND ?";
$complianceParams = [$dateFrom, $dateTo];

if ($selectedUser) {
    $complianceQuery .= " AND user_id = ?";
    $complianceParams[] = $selectedUser;
}

$complianceQuery .= " ORDER BY date DESC, user_id";
$complianceResults = $db->fetchAll($complianceQuery, $complianceParams);

// Calculate aggregate stats
$totalCalls = 0;
$totalRecorded = 0;
$greenCount = 0;
$yellowCount = 0;
$redCount = 0;

foreach ($complianceResults as $result) {
    $totalCalls += $result['total_calls'];
    $totalRecorded += $result['recorded_calls'];
    if ($result['status'] === 'green') $greenCount++;
    elseif ($result['status'] === 'yellow') $yellowCount++;
    else $redCount++;
}

$overallCompliance = $totalCalls > 0 ? ($totalRecorded / $totalCalls) * 100 : 0;

// Get talk time stats
$talkTimeQuery = "SELECT
    direction,
    SUM(total_duration) as total_duration,
    SUM(bucket_0_2) as bucket_0_2,
    SUM(bucket_2_5) as bucket_2_5,
    SUM(bucket_5_10) as bucket_5_10,
    SUM(bucket_10_plus) as bucket_10_plus
    FROM talk_time_stats
    WHERE date BETWEEN ? AND ?";
$talkTimeParams = [$dateFrom, $dateTo];

if ($selectedUser) {
    $talkTimeQuery .= " AND user_id = ?";
    $talkTimeParams[] = $selectedUser;
}

$talkTimeQuery .= " GROUP BY direction";
$talkTimeStats = $db->fetchAll($talkTimeQuery, $talkTimeParams);

$talkTime = [
    'incoming' => ['total' => 0, 'buckets' => [0, 0, 0, 0]],
    'outgoing' => ['total' => 0, 'buckets' => [0, 0, 0, 0]]
];

foreach ($talkTimeStats as $stat) {
    $dir = $stat['direction'];
    $talkTime[$dir] = [
        'total' => $stat['total_duration'],
        'buckets' => [
            $stat['bucket_0_2'],
            $stat['bucket_2_5'],
            $stat['bucket_5_10'],
            $stat['bucket_10_plus']
        ]
    ];
}

// Get data for charts
$chartData = $db->fetchAll(
    "SELECT date, SUM(total_calls) as calls, SUM(recorded_calls) as recorded,
            AVG(overall_compliance) as avg_compliance
     FROM compliance_results
     WHERE date BETWEEN ? AND ?
     " . ($selectedUser ? "AND user_id = ?" : "") . "
     GROUP BY date
     ORDER BY date",
    $selectedUser ? [$dateFrom, $dateTo, $selectedUser] : [$dateFrom, $dateTo]
);

// Format duration helper
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    if ($hours > 0) {
        return sprintf("%dh %dm", $hours, $minutes);
    }
    return sprintf("%dm", $minutes);
}

// Format date for display
$displayDateFrom = date('M d, Y', strtotime($dateFrom));
$displayDateTo = date('M d, Y', strtotime($dateTo));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Call Recording Compliance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- Moment.js -->
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <!-- Daterangepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>Call Compliance</h2>
                <p>Admin Dashboard</p>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="active">Dashboard</a>
                <a href="users.php">Users</a>
                <a href="call-report.php">Call Report</a>
                <a href="compliant.php">Compliant Calls</a>
                <a href="non-compliant.php">Non-Compliant Calls</a>
                <a href="fcm-logs.php">App Logs</a> 
                <a href="process-data.php">Process Data</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>Compliance Dashboard</h1>
                <div class="header-actions">
                    <span>Welcome, <?php echo htmlspecialchars(getCurrentAdmin()); ?></span>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['refresh' => 1])); ?>" class="btn btn-primary btn-sm">Refresh Data</a>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="filter-form" id="filterForm">
                    <input type="hidden" name="date_from" id="date_from" value="<?php echo $dateFrom; ?>">
                    <input type="hidden" name="date_to" id="date_to" value="<?php echo $dateTo; ?>">

                    <div class="form-group">
                        <label for="daterange">Date Range</label>
                        <input type="text" id="daterange" class="daterange-picker"
                               value="<?php echo $displayDateFrom . ' - ' . $displayDateTo; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="user_id">User</label>
                        <select id="user_id" name="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($allUsers as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['user_id']); ?>"
                                        <?php echo $selectedUser === $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['user_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </form>
            </div>

            <!-- Stats Cards -->
            <div class="cards-grid">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Total Calls</span>
                    </div>
                    <div class="card-value"><?php echo number_format($totalCalls); ?></div>
                    <div class="card-subtitle"><?php echo number_format($totalRecorded); ?> recorded</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Overall Compliance</span>
                    </div>
                    <div class="card-value"><?php echo number_format($overallCompliance, 1); ?>%</div>
                    <div class="progress-bar">
                        <div class="progress-fill progress-<?php echo $overallCompliance >= 95 ? 'green' : ($overallCompliance >= 85 ? 'yellow' : 'red'); ?>"
                             style="width: <?php echo min(100, $overallCompliance); ?>%"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Status Distribution</span>
                    </div>
                    <div style="display: flex; gap: 15px; margin-top: 10px;">
                        <div>
                            <span class="status-badge status-green"><?php echo $greenCount; ?></span>
                            <span style="font-size: 0.85rem; color: var(--gray-text);">Green</span>
                        </div>
                        <div>
                            <span class="status-badge status-yellow"><?php echo $yellowCount; ?></span>
                            <span style="font-size: 0.85rem; color: var(--gray-text);">Yellow</span>
                        </div>
                        <div>
                            <span class="status-badge status-red"><?php echo $redCount; ?></span>
                            <span style="font-size: 0.85rem; color: var(--gray-text);">Red</span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Total Talk Time</span>
                    </div>
                    <div class="card-value"><?php echo formatDuration($talkTime['incoming']['total'] + $talkTime['outgoing']['total']); ?></div>
                    <div class="card-subtitle">
                        In: <?php echo formatDuration($talkTime['incoming']['total']); ?> |
                        Out: <?php echo formatDuration($talkTime['outgoing']['total']); ?>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>Compliance Trend</h3>
                    <div class="chart-container">
                        <canvas id="complianceChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h3>Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Talk Time Stats -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Talk Time Distribution</h3>
                </div>
                <div style="padding: 20px;">
                    <div class="talk-time-grid">
                        <div class="talk-time-card">
                            <h4>Incoming Calls</h4>
                            <ul class="bucket-list">
                                <li><span>0-2 min</span><strong><?php echo $talkTime['incoming']['buckets'][0]; ?> calls</strong></li>
                                <li><span>2-5 min</span><strong><?php echo $talkTime['incoming']['buckets'][1]; ?> calls</strong></li>
                                <li><span>5-10 min</span><strong><?php echo $talkTime['incoming']['buckets'][2]; ?> calls</strong></li>
                                <li><span>10+ min</span><strong><?php echo $talkTime['incoming']['buckets'][3]; ?> calls</strong></li>
                            </ul>
                        </div>
                        <div class="talk-time-card">
                            <h4>Outgoing Calls</h4>
                            <ul class="bucket-list">
                                <li><span>0-2 min</span><strong><?php echo $talkTime['outgoing']['buckets'][0]; ?> calls</strong></li>
                                <li><span>2-5 min</span><strong><?php echo $talkTime['outgoing']['buckets'][1]; ?> calls</strong></li>
                                <li><span>5-10 min</span><strong><?php echo $talkTime['outgoing']['buckets'][2]; ?> calls</strong></li>
                                <li><span>10+ min</span><strong><?php echo $talkTime['outgoing']['buckets'][3]; ?> calls</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compliance Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>User Compliance Details</h3>
                </div>
                <?php if (empty($complianceResults)): ?>
                    <div class="empty-state">
                        <h3>No Data Available</h3>
                        <p>No compliance data found for the selected filters.</p>
                    </div>
                <?php else: ?>
                    <table id="dashboardTable" class="display report-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Date</th>
                                <th>Total Calls</th>
                                <th>Recorded</th>
                                <th>Incoming</th>
                                <th>Outgoing</th>
                                <th>Overall</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($complianceResults as $result): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($result['user_id'], 0, 1)); ?>
                                            </div>
                                            <strong><?php echo htmlspecialchars($result['user_id']); ?></strong>
                                        </div>
                                    </td>
                                    <td><span class="date-badge"><?php echo date('d M Y', strtotime($result['date'])); ?></span></td>
                                    <td><span class="call-count incoming"><?php echo $result['total_calls']; ?></span></td>
                                    <td><span class="call-count outgoing"><?php echo $result['recorded_calls']; ?></span></td>
                                    <td><span class="duration-badge"><?php echo number_format($result['incoming_compliance'], 1); ?>%</span></td>
                                    <td><span class="duration-badge"><?php echo number_format($result['outgoing_compliance'], 1); ?>%</span></td>
                                    <td><span class="duration-badge total"><?php echo number_format($result['overall_compliance'], 1); ?>%</span></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $result['status']; ?>">
                                            <?php echo ucfirst($result['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Initialize daterangepicker and DataTable
        $(function() {
            // Initialize DataTable
            $('#dashboardTable').DataTable({
                pageLength: 25,
                order: [[1, 'desc']], // Sort by Date desc
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });

            $('#daterange').daterangepicker({
                startDate: moment('<?php echo $dateFrom; ?>'),
                endDate: moment('<?php echo $dateTo; ?>'),
                opens: 'right',
                autoUpdateInput: true,
                locale: {
                    format: 'MMM DD, YYYY',
                    separator: ' - ',
                    applyLabel: 'Apply',
                    cancelLabel: 'Cancel',
                    customRangeLabel: 'Custom Range'
                },
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            }, function(start, end, label) {
                // Update hidden inputs
                $('#date_from').val(start.format('YYYY-MM-DD'));
                $('#date_to').val(end.format('YYYY-MM-DD'));
            });
        });

        // Chart data from PHP
        const chartData = <?php echo json_encode($chartData); ?>;

        // Compliance Trend Chart
        const complianceCtx = document.getElementById('complianceChart').getContext('2d');
        new Chart(complianceCtx, {
            type: 'line',
            data: {
                labels: chartData.map(d => d.date),
                datasets: [{
                    label: 'Compliance %',
                    data: chartData.map(d => parseFloat(d.avg_compliance)),
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        min: 0,
                        max: 100,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Green (>=95%)', 'Yellow (85-94%)', 'Red (<85%)'],
                datasets: [{
                    data: [<?php echo "$greenCount, $yellowCount, $redCount"; ?>],
                    backgroundColor: ['#27ae60', '#f39c12', '#e74c3c']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>
