<?php
/**
 * Notification Logs Page
 * Shows FCM notification send log with app-status diagnostics from matching
 * health_check_reports (joined by request_id).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();

$dateFrom     = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo       = $_GET['date_to']   ?? date('Y-m-d', strtotime('-1 day'));
$selectedUser = $_GET['user_id'] ?? '';
$selectedType = $_GET['type']    ?? '';

$allUsers = $db->fetchAll("SELECT DISTINCT user_id FROM users ORDER BY user_id");

$displayDateFrom = date('M d, Y', strtotime($dateFrom));
$displayDateTo   = date('M d, Y', strtotime($dateTo));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Logs - Call Recording Compliance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <style>
        .kv-table { width: 100%; border-collapse: collapse; }
        .kv-table th, .kv-table td {
            padding: 8px 12px; border-bottom: 1px solid #e9ecef; text-align: left; font-size: 0.9rem;
        }
        .kv-table th { background: #f8f9fa; width: 45%; font-weight: 600; color: #495057; }
        .kv-table td code { background: #f1f3f5; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; }
        .flag-yes { color: #22a06b; font-weight: 600; }
        .flag-no  { color: #d9534f; font-weight: 600; }
        .flag-warn { color: #e67e22; font-weight: 600; }
        .modal-section-title {
            margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #e9ecef;
            font-size: 1rem; font-weight: 600; color: #343a40;
        }
        .modal-section-title:first-child { margin-top: 0; }
        .failure-item {
            background: #fff5f5; border-left: 3px solid #d9534f; padding: 10px 12px;
            margin-bottom: 8px; border-radius: 4px; font-size: 0.85rem;
        }
        .failure-item .fail-head {
            display: flex; justify-content: space-between; margin-bottom: 4px;
            font-weight: 600; color: #842029;
        }
        .failure-item .fail-file { font-family: monospace; color: #495057; word-break: break-all; }
        .failure-item .fail-error { color: #842029; margin-top: 4px; word-break: break-word; }
        .pre-raw {
            background: #0a0f1f; color: #eef2ff; padding: 12px; border-radius: 8px;
            max-height: 300px; overflow: auto; white-space: pre-wrap; word-break: break-word;
            font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.8rem;
        }
        .status-success { background: #d1f3e0 !important; color: #14532d !important; }
        .status-invalid { background: #fde2e2 !important; color: #842029 !important; }
        .status-transient { background: #fff4cc !important; color: #8a6d00 !important; }
        #appStatusModal .modal-body,
        #errorModal .modal-body {
            max-height: 65vh;
            overflow-y: auto;
        }
    </style>
</head>

<body>
    <div class="dashboard">
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
                <a href="non-compliant.php">Non-Compliant Calls</a>
                <a href="fcm-logs.php" class="active">Notification Logs</a>
                <a href="process-data.php">Process Data</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <main class="main-content">
            <div class="header">
                <h1>Notification Logs</h1>
                <div class="header-actions">
                    <span id="totalRecords">Total Records: 0</span>
                    <a href="log-info.php" target="_blank" rel="noopener" class="btn btn-primary" style="padding:6px 14px;font-size:0.85rem;text-decoration:none;">Logs Info</a>
                </div>
            </div>

            <div id="toast" class="toast"><span id="toastMessage"></span></div>

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
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value=""              <?php echo $selectedType === ''              ? 'selected' : ''; ?>>All</option>
                            <option value="health_check"  <?php echo $selectedType === 'health_check'  ? 'selected' : ''; ?>>health_check</option>
                            <option value="admin_manual"  <?php echo $selectedType === 'admin_manual'  ? 'selected' : ''; ?>>admin_manual</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </form>
            </div>

            <div class="table-container">
                <table id="logsTable" class="display report-table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Sent At</th>
                            <th>User ID</th>
                            <th>Type</th>
                            <th>HTTP</th>
                            <th>FCM Status</th>
                            <th>Error</th>
                            <th>App Status</th>
                            <th>Request ID</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="modal">
        <div class="modal-content" style="max-width: 680px;">
            <div class="modal-header">
                <h3>FCM Error Detail</h3>
                <button class="modal-close" onclick="closeErrorModal()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="kv-table">
                    <tr><th>User ID</th>     <td id="errUser"></td></tr>
                    <tr><th>Request ID</th>  <td><code id="errReq"></code></td></tr>
                    <tr><th>HTTP Status</th> <td id="errHttp"></td></tr>
                    <tr><th>FCM Status</th>  <td id="errStatus"></td></tr>
                    <tr><th>Message</th>     <td id="errMsg"></td></tr>
                </table>
                <div class="modal-section-title">Raw Firebase Response</div>
                <pre class="pre-raw" id="errRaw"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeErrorModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- App Status Modal -->
    <div id="appStatusModal" class="modal">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h3>App Status — <span id="asUser"></span></h3>
                <button class="modal-close" onclick="closeAppStatusModal()">&times;</button>
            </div>
            <div class="modal-body" id="asBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeAppStatusModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        const filters = {
            dateFrom: '<?php echo $dateFrom; ?>',
            dateTo:   '<?php echo $dateTo; ?>',
            userId:   '<?php echo $selectedUser; ?>',
            type:     '<?php echo $selectedType; ?>'
        };

        const rowsById = {};

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/[&<>"']/g, function(c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        }

        function fcmStatusClass(status) {
            if (!status) return '';
            if (status === 'SUCCESS') return 'status-success';
            var invalid = ['UNREGISTERED','INVALID_ARGUMENT','SENDER_ID_MISMATCH','NOT_FOUND'];
            var transient = ['UNAVAILABLE','INTERNAL','DEADLINE_EXCEEDED','QUOTA_EXCEEDED','RESOURCE_EXHAUSTED','ABORTED','NETWORK_ERROR'];
            if (invalid.indexOf(status) !== -1)   return 'status-invalid';
            if (transient.indexOf(status) !== -1) return 'status-transient';
            return '';
        }

        function boolFlag(val, goodIsTrue) {
            if (val === null || val === undefined) return '<span class="no-target">-</span>';
            var isGood = goodIsTrue ? (val === true) : (val === false);
            var cls = isGood ? 'flag-yes' : 'flag-no';
            return '<span class="' + cls + '">' + (val ? 'Yes' : 'No') + '</span>';
        }

        function formatDateTime(v) {
            if (!v) return '-';
            var d = new Date(v);
            if (isNaN(d.getTime())) return v;
            return d.toLocaleString();
        }

        $(function() {
            $('#daterange').daterangepicker({
                startDate: moment(filters.dateFrom),
                endDate: moment(filters.dateTo),
                opens: 'right',
                autoUpdateInput: true,
                locale: { format: 'MMM DD, YYYY', separator: ' - ', applyLabel: 'Apply', cancelLabel: 'Cancel', customRangeLabel: 'Custom Range' },
                ranges: {
                    'Yesterday':    [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Today':        [moment(), moment()],
                    'Last 7 Days':  [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month':   [moment().startOf('month'), moment().endOf('month')],
                    'Last Month':   [moment().subtract(1,'month').startOf('month'), moment().subtract(1,'month').endOf('month')]
                }
            }, function(start, end) {
                $('#date_from').val(start.format('YYYY-MM-DD'));
                $('#date_to').val(end.format('YYYY-MM-DD'));
            });

            window.dataTable = $('#logsTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '../api/fcm-logs-data.php',
                    data: function(d) {
                        d.date_from = filters.dateFrom;
                        d.date_to = filters.dateTo;
                        d.user_id = filters.userId;
                        d.type = filters.type;
                    },
                    dataSrc: function(json) {
                        $('#totalRecords').text('Total Records: ' + json.recordsTotal);
                        // Cache rows by index so modal openers can look up payloads
                        (json.data || []).forEach(function(row, idx) {
                            row.__idx = idx;
                            rowsById[idx] = row;
                        });
                        return json.data || [];
                    }
                },
                columns: [
                    { data: 'sent_at', render: function(d) { return '<span class="date-badge">' + escapeHtml(d) + '</span>'; } },
                    { data: 'user_id', render: function(d) { return '<strong>' + escapeHtml(d) + '</strong>'; } },
                    { data: 'type',    render: function(d) { return '<span class="target-badge">' + escapeHtml(d) + '</span>'; } },
                    { data: 'http_status' },
                    { data: 'fcm_status', render: function(d) {
                        return '<span class="status-badge ' + fcmStatusClass(d) + '">' + escapeHtml(d || '-') + '</span>';
                    } },
                    { data: null, orderable: false, render: function(row) {
                        if (!row.has_error) return '<span class="no-target">-</span>';
                        return '<button class="btn btn-primary" style="padding:4px 10px;font-size:0.82rem" onclick="openErrorModal(' + row.__idx + ')">View Error</button>';
                    } },
                    { data: null, orderable: false, render: function(row) {
                        if (!row.has_app_status) return '<span class="no-target">-</span>';
                        return '<button class="btn btn-primary" style="padding:4px 10px;font-size:0.82rem" onclick="openAppStatusModal(' + row.__idx + ')">Show Status</button>';
                    } },
                    { data: 'request_id', orderable: false, render: function(d) {
                        return d ? '<code style="font-size:0.75rem">' + escapeHtml(d) + '</code>' : '-';
                    } }
                ],
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    processing: '<div class="loading"><div class="spinner"></div></div>',
                    paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
                }
            });
        });

        // --- Error modal ---
        function openErrorModal(idx) {
            var row = rowsById[idx];
            if (!row) return;
            document.getElementById('errUser').textContent   = row.user_id || '-';
            document.getElementById('errReq').textContent    = row.request_id || '-';
            document.getElementById('errHttp').textContent   = row.http_status;
            document.getElementById('errStatus').textContent = row.fcm_status || '-';
            document.getElementById('errMsg').textContent    = row.error_message || '(no message)';
            document.getElementById('errRaw').textContent    = row.raw_response || '(empty)';
            document.getElementById('errorModal').classList.add('show');
        }
        function closeErrorModal() {
            document.getElementById('errorModal').classList.remove('show');
        }

        // --- App status modal ---
        function openAppStatusModal(idx) {
            var row = rowsById[idx];
            if (!row) return;
            document.getElementById('asUser').textContent = row.user_id || '';

            var html = '';

            // App status flags — goodIsTrue flipped where appropriate
            if (row.app_status) {
                var as = row.app_status;
                html += '<div class="modal-section-title">App Status</div>';
                html += '<table class="kv-table">';
                html += '<tr><th>Battery Optimized</th>       <td>' + boolFlag(as.is_battery_optimized, false) + '</td></tr>';
                html += '<tr><th>Background Restricted</th>   <td>' + boolFlag(as.is_background_restricted, false) + '</td></tr>';
                html += '<tr><th>Call Log Permission</th>     <td>' + boolFlag(as.has_call_log_permission, true) + '</td></tr>';
                html += '<tr><th>Storage Permission</th>      <td>' + boolFlag(as.has_storage_permission, true) + '</td></tr>';
                html += '<tr><th>Notification Permission</th> <td>' + boolFlag(as.has_notification_permission, true) + '</td></tr>';
                html += '<tr><th>App Hibernated</th>          <td>' + boolFlag(as.is_app_hibernated, false) + '</td></tr>';
                html += '<tr><th>Auto Revoke Enabled</th>     <td>' + boolFlag(as.is_auto_revoke_enabled, false) + '</td></tr>';
                html += '<tr><th>Can Schedule Exact Alarm</th><td>' + boolFlag(as.can_schedule_exact_alarm, true) + '</td></tr>';
                html += '<tr><th>Alarm Scheduled</th>         <td>' + boolFlag(as.alarm_scheduled, true) + '</td></tr>';
                html += '<tr><th>Work Manager State</th>      <td><code>' + escapeHtml(as.work_manager_state || '-') + '</code></td></tr>';
                html += '</table>';
            } else {
                html += '<div class="modal-section-title">App Status</div><p style="color:#6c757d">No app status received.</p>';
            }

            // Last run
            if (row.last_run && Object.keys(row.last_run).length) {
                var lr = row.last_run;
                html += '<div class="modal-section-title">Last Run</div>';
                html += '<table class="kv-table">';
                html += '<tr><th>Last Run At</th>         <td>' + escapeHtml(formatDateTime(lr.last_run_at) || '-') + '</td></tr>';
                html += '<tr><th>Last Success At</th>     <td>' + escapeHtml(formatDateTime(lr.last_success_at) || '-') + '</td></tr>';
                html += '<tr><th>Last Error</th>          <td>' + (lr.last_error ? '<span class="flag-no">' + escapeHtml(lr.last_error) + '</span>' : '-') + '</td></tr>';
                html += '<tr><th>Last Uploaded File</th>  <td><code>' + escapeHtml(lr.last_uploaded_file_name || '-') + '</code></td></tr>';
                html += '<tr><th>Total Audio Count</th>   <td>' + escapeHtml(lr.last_run_total_audio_count != null ? lr.last_run_total_audio_count : '-') + '</td></tr>';
                html += '<tr><th>Uploaded Count</th>      <td>' + escapeHtml(lr.last_run_uploaded_count != null ? lr.last_run_uploaded_count : '-') + '</td></tr>';
                html += '</table>';
            }

            // Failure log
            html += '<div class="modal-section-title">Failure Log</div>';
            if (Array.isArray(row.failure_log) && row.failure_log.length > 0) {
                row.failure_log.forEach(function(f) {
                    html += '<div class="failure-item">';
                    html += '<div class="fail-head"><span>' + escapeHtml(f.date || '-') + '</span><span>' + escapeHtml(formatDateTime(f.failed_at)) + '</span></div>';
                    html += '<div class="fail-file">' + escapeHtml(f.failed_file || '-') + '</div>';
                    html += '<div class="fail-error">' + escapeHtml(f.error || '-') + '</div>';
                    html += '</div>';
                });
            } else {
                html += '<p style="color:#6c757d">No failures reported.</p>';
            }

            document.getElementById('asBody').innerHTML = html;
            document.getElementById('appStatusModal').classList.add('show');
        }
        function closeAppStatusModal() {
            document.getElementById('appStatusModal').classList.remove('show');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeErrorModal(); closeAppStatusModal(); }
        });
        document.getElementById('errorModal').addEventListener('click', function(e) {
            if (e.target === this) closeErrorModal();
        });
        document.getElementById('appStatusModal').addEventListener('click', function(e) {
            if (e.target === this) closeAppStatusModal();
        });

        function showToast(message, isError) {
            var toast = document.getElementById('toast');
            document.getElementById('toastMessage').textContent = message;
            toast.classList.remove('error');
            if (isError) toast.classList.add('error');
            toast.classList.add('show');
            setTimeout(function() { toast.classList.remove('show'); }, 3000);
        }
    </script>
</body>

</html>
