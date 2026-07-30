<?php
/**
 * Users List Page
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();

// Get filter parameters
$selectedDepartment = $_GET['department_id'] ?? '';

// Get all departments for filter dropdown
$allDepartments = $db->fetchAll("SELECT id, department_name FROM departments ORDER BY department_name");

// Build query for users with their latest compliance data
$query = "SELECT u.id, u.user_id, u.created_at, u.target, u.target_started, d.department_name, u.department_id,
            (SELECT overall_compliance FROM compliance_results cr
             WHERE cr.user_id = u.user_id ORDER BY date DESC LIMIT 1) as latest_compliance,
            (SELECT status FROM compliance_results cr
             WHERE cr.user_id = u.user_id ORDER BY date DESC LIMIT 1) as latest_status
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE 1=1";

$params = [];

if ($selectedDepartment) {
    $query .= " AND u.department_id = ?";
    $params[] = $selectedDepartment;
}

$query .= " ORDER BY u.created_at DESC";

$users = $db->fetchAll($query, $params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Call Recording Compliance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
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
                <a href="users.php" class="active">Users</a>
                <a href="call-report.php">Call Report</a>
                <a href="compliant.php">Compliant Calls</a>
                <a href="non-compliant.php">Non-Compliant Calls</a>
                <a href="process-data.php">Process Data</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>Users</h1>
                <div class="header-actions">
                    <span>Total Users: <?php echo count($users); ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="form-group">
                        <label for="department_id">Department</label>
                        <select id="department_id" name="department_id" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($allDepartments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"
                                        <?php echo $selectedDepartment == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </form>
            </div>

            <div class="table-container">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <h3>No Users Found</h3>
                        <p>No users have submitted call data yet.</p>
                    </div>
                <?php else: ?>
                    <table id="usersTable" class="display report-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Latest Compliance</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($user['user_id'], 0, 1)); ?>
                                            </div>
                                            <strong><?php echo htmlspecialchars($user['user_id']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($user['latest_compliance'] !== null): ?>
                                            <span class="duration-badge total"><?php echo number_format($user['latest_compliance'], 1); ?>%</span>
                                        <?php else: ?>
                                            <span class="no-target">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="target-badge"><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($user['latest_status']): ?>
                                            <span class="status-badge status-<?php echo $user['latest_status']; ?>">
                                                <?php echo ucfirst($user['latest_status']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="no-target">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="date-badge"><?php echo date('d M Y', strtotime($user['created_at'])); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#usersTable').DataTable({
                pageLength: 25,
                order: [[4, 'desc']], // Sort by Registered date desc
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries"
                }
            });
        });
    </script>
</body>
</html>
