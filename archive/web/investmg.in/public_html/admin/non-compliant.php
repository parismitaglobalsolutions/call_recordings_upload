<?php
/**
 * Non-Compliant Calls Page
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-6 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$selectedUser = $_GET['user_id'] ?? '';

// Get all users for filter dropdown
$allUsers = $db->fetchAll("SELECT DISTINCT user_id FROM users ORDER BY user_id");

// Build query
$query = "SELECT * FROM calls WHERE is_compliant = 0 AND date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($selectedUser) {
    $query .= " AND user_id = ?";
    $params[] = $selectedUser;
}

$query .= " ORDER BY date DESC, call_start_time DESC";
$nonCompliantCalls = $db->fetchAll($query, $params);

// Get statistics by user
$userStats = $db->fetchAll(
    "SELECT user_id, COUNT(*) as count
     FROM calls
     WHERE is_compliant = 0 AND date BETWEEN ? AND ?
     " . ($selectedUser ? "AND user_id = ?" : "") . "
     GROUP BY user_id
     ORDER BY count DESC",
    $selectedUser ? [$dateFrom, $dateTo, $selectedUser] : [$dateFrom, $dateTo]
);

// Format date for display
$displayDateFrom = date('M d, Y', strtotime($dateFrom));
$displayDateTo = date('M d, Y', strtotime($dateTo));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Compliant Calls - Call Recording Compliance</title>
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
                <a href="dashboard.php">Dashboard</a>
                <a href="users.php">Users</a>
                <a href="call-report.php">Call Report</a>
                <a href="compliant.php">Compliant Calls</a>
                <a href="non-compliant.php" class="active">Non-Compliant Calls</a>
                <a href="fcm-logs.php">App Logs</a> 
                <a href="process-data.php">Process Data</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>Non-Compliant Calls</h1>
                <div class="header-actions">
                    <span>Total: <?php echo count($nonCompliantCalls); ?> calls</span>
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

            <!-- User Statistics -->
            <?php if (!empty($userStats)): ?>
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>Non-Compliant Calls by User</h3>
                    <div class="chart-container">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>User Breakdown</h3>
                    <div style="padding: 20px;">
                        <?php foreach ($userStats as $stat): ?>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 15px; align-items: center;">
                                <span><?php echo htmlspecialchars($stat['user_id']); ?></span>
                                <span class="status-badge status-red"><?php echo $stat['count']; ?> calls</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Calls Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3>Non-Compliant Call Details</h3>
                    <p style="color: #7f8c8d; font-size: 0.9rem; margin-top: 5px;">Calls without valid recording - see reason for each</p>
                </div>
                <?php if (empty($nonCompliantCalls)): ?>
                    <div class="empty-state">
                        <h3>No Non-Compliant Calls</h3>
                        <p>All calls in the selected period are compliant!</p>
                    </div>
                <?php else: ?>
                    <table id="nonCompliantTable" class="display report-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Call ID</th>
                                <th>Date</th>
                                <th>Start Time</th>
                                <th>Duration</th>
                                <th>Direction</th>
                                <th>SIM</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nonCompliantCalls as $call): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($call['user_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($call['call_id']); ?></td>
                                    <td><span class="date-badge"><?php echo date('d M Y', strtotime($call['date'])); ?></span></td>
                                    <td><span class="duration-badge"><?php echo date('H:i:s', strtotime($call['call_start_time'])); ?></span></td>
                                    <td><span class="duration-badge"><?php echo floor($call['call_duration'] / 60); ?>m <?php echo $call['call_duration'] % 60; ?>s</span></td>
                                    <td>
                                        <span class="call-count <?php echo $call['direction'] === 'incoming' ? 'incoming' : 'outgoing'; ?>">
                                            <?php echo ucfirst($call['direction']); ?>
                                        </span>
                                    </td>
                                    <td><span class="target-badge">SIM <?php echo $call['sim_slot']; ?></span></td>
                                    <td>
                                        <span class="shortfall-negative">
                                            <?php echo htmlspecialchars($call['reason'] ?? 'No recording found'); ?>
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
        // Initialize daterangepicker
        $(function() {
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

            // Initialize DataTable
            $('#nonCompliantTable').DataTable({
                pageLength: 25,
                order: [[2, 'desc'], [3, 'desc']], // Sort by date desc, then time desc
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
    </script>

    <?php if (!empty($userStats)): ?>
    <script>
        const userData = <?php echo json_encode($userStats); ?>;

        const ctx = document.getElementById('userChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: userData.map(u => u.user_id),
                datasets: [{
                    label: 'Non-Compliant Calls',
                    data: userData.map(u => u.count),
                    backgroundColor: '#e74c3c'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
