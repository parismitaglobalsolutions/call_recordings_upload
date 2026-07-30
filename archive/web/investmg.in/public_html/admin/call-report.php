<?php

/**
 * Call Report Page - Daily breakdown with target history
 * Uses server-side DataTable processing for performance
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();

// Get filter parameters - default to yesterday
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('-1 day'));
$selectedUser = $_GET['user_id'] ?? '';
$selectedDepartment = $_GET['department_id'] ?? '';

// Get all users for filter dropdown
$allUsers = $db->fetchAll("SELECT DISTINCT user_id FROM users ORDER BY user_id");

// Get all departments for filter dropdown
$allDepartments = $db->fetchAll("SELECT id, department_name FROM departments ORDER BY department_name");

// Format date for display
$displayDateFrom = date('M d, Y', strtotime($dateFrom));
$displayDateTo = date('M d, Y', strtotime($dateTo));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Report - Call Recording Compliance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- Moment.js -->
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <!-- Daterangepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <!-- SheetJS for Excel export -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
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
                <a href="call-report.php" class="active">Call Report</a>
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
                <h1>Call Report</h1>
                <div class="header-actions">
                    <span id="totalRecords">Total Records: 0</span>
                    <button type="button" class="btn btn-success" id="exportBtn" onclick="exportToExcel()">
                        <span id="exportBtnText">Export to Excel</span>
                        <span id="exportBtnLoading" style="display: none;">Downloading...</span>
                    </button>
                </div>
            </div>

            <!-- Toast Notification -->
            <div id="toast" class="toast">
                <span id="toastMessage"></span>
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
                    <div class="form-group">
                        <label for="department_id">Department</label>
                        <select id="department_id" name="department_id">
                            <option value="">All Departments</option>
                            <?php foreach ($allDepartments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"
                                    <?php echo $selectedDepartment == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <button type="button" class="btn btn-primary" onclick="openSummariesModal()">View Summaries</button>
                </form>
            </div>

            <!-- Report Table -->
            <div class="table-container">
                <table id="reportTable" class="display report-table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User Name</th>
                            <th>Department</th>
                            <th>Incoming Calls</th>
                            <th>Incoming Duration</th>
                            <th>Outgoing Calls</th>
                            <th>Target</th>
                            <th>Short Fall</th>
                            <th>Outgoing Duration</th>
                            <th>Total Call Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- View Summaries Modal -->
    <div id="summariesModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Monthly Summaries - <span id="summariesUserName"></span></h3>
                <button class="modal-close" onclick="closeSummariesModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0; max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                <div id="summariesLoading" style="text-align: center; padding: 40px; display: none;">
                    <div class="spinner"></div>
                    <p style="margin-top: 10px; color: #6c757d;">Loading summaries...</p>
                </div>
                <div id="summariesEmpty" style="text-align: center; padding: 40px; display: none;">
                    <p style="color: #6c757d;">No monthly summaries found for this user.</p>
                </div>
                <table id="summariesTable" class="report-table" style="width: 100%; min-width: unset !important; display: none;">
                    <thead>
                        <tr>
                            <th style="padding: 12px 15px; width: 10%;">No</th>
                            <th style="padding: 12px 15px; width: 50%;">File Name</th>
                            <th style="padding: 12px 15px; width: 40%; white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="summariesTableBody">
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeSummariesModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Set Target Modal -->
    <div id="targetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Set Target</h3>
                <button class="modal-close" onclick="closeTargetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="targetUserId">
                <div class="form-group">
                    <label for="targetDate">Target Start Date</label>
                    <input type="date" id="targetDate" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="targetValue">Target</label>
                    <input type="number" id="targetValue" class="form-control" min="0" max="100" step="0.01" placeholder="e.g. 90" required>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTargetModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveTarget()">Save</button>
            </div>
        </div>
    </div>

    <script>
        // Current filter values
        const filters = {
            dateFrom: '<?php echo $dateFrom; ?>',
            dateTo: '<?php echo $dateTo; ?>',
            userId: '<?php echo $selectedUser; ?>',
            departmentId: '<?php echo $selectedDepartment; ?>'
        };

        // Initialize daterangepicker
        $(function() {
            $('#daterange').daterangepicker({
                startDate: moment(filters.dateFrom),
                endDate: moment(filters.dateTo),
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
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Today': [moment(), moment()],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'This Month': [moment().startOf('month'), moment().endOf('month')],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                }
            }, function(start, end, label) {
                $('#date_from').val(start.format('YYYY-MM-DD'));
                $('#date_to').val(end.format('YYYY-MM-DD'));
            });

            // Initialize DataTable with server-side processing
            window.dataTable = $('#reportTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '../api/call-report-data.php',
                    data: function(d) {
                        d.date_from = filters.dateFrom;
                        d.date_to = filters.dateTo;
                        d.user_id = filters.userId;
                        d.department_id = filters.departmentId;
                    },
                    dataSrc: function(json) {
                        // Update total records display
                        $('#totalRecords').text('Total Records: ' + json.recordsTotal);
                        return json.data;
                    }
                },
                columns: [{
                        data: 'date',
                        render: function(data) {
                            return '<span class="date-badge">' + data + '</span>';
                        }
                    },
                    {
                        data: 'user_id',
                        render: function(data) {
                            return '<strong>' + data + '</strong>';
                        }
                    },
                    {
                        data: 'department_name',
                        render: function(data) {
                            return '<span class="target-badge">' + data + '</span>';
                        }
                    },
                    {
                        data: 'total_incoming_calls',
                        render: function(data) {
                            return '<span class="call-count incoming">' + data + '</span>';
                        }
                    },
                    {
                        data: 'total_incoming_duration',
                        render: function(data) {
                            return '<span class="duration-badge">' + data + '</span>';
                        }
                    },
                    {
                        data: 'total_outgoing_calls',
                        render: function(data) {
                            return '<span class="call-count outgoing">' + data + '</span>';
                        }
                    },
                    {
                        data: 'target',
                        render: function(data) {
                            return '<span class="target-badge">' + data + '</span>';
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            return '<span class="' + data.shortfall_class + '">' + data.shortfall + '</span>';
                        }
                    },
                    {
                        data: 'total_outgoing_duration',
                        render: function(data) {
                            return '<span class="duration-badge">' + data + '</span>';
                        }
                    },
                    {
                        data: 'total_call_time',
                        render: function(data) {
                            return '<span class="duration-badge total">' + data + '</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: function(data) {
                            return `
                                <div class="action-menu">
                                    <button class="action-menu-btn" onclick="toggleDropdown(event, this)">
                                        <span class="dots">&#8942;</span>
                                    </button>
                                    <div class="action-dropdown">
                                        <a href="dashboard.php?user_id=${encodeURIComponent(data.user_id)}&date_from=${filters.dateFrom}&date_to=${filters.dateTo}">See Dashboard</a>
                                        <a href="#" onclick="openTargetModal(${data.user_table_id}, '${data.user_target || ''}', '${data.user_target_started || ''}'); return false;">Set Target</a>
                                        <a href="compliant.php?user_id=${encodeURIComponent(data.user_id)}&date_from=${filters.dateFrom}&date_to=${filters.dateTo}">See Compliance</a>
                                        <a href="non-compliant.php?user_id=${encodeURIComponent(data.user_id)}&date_from=${filters.dateFrom}&date_to=${filters.dateTo}">See Non-Compliance</a>
                                        <a href="view-summary.php?username=${encodeURIComponent(data.user_id)}&date=${data.date_raw}" target="_blank">View Summary</a>
                                        <a href="view-summary.php?username=${encodeURIComponent(data.user_id)}&date=${data.date_raw}&download=1" target="_blank">Download Summary</a>
                                    </div>
                                </div>
                            `;
                        }
                    }
                ],
                pageLength: 25,
                order: [
                    [0, 'desc'],
                    [1, 'asc']
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    processing: '<div class="loading"><div class="spinner"></div></div>',
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                }
            });
        });

        // Toggle dropdown
        function toggleDropdown(event, btn) {
            event.stopPropagation();

            const actionMenu = btn.parentElement;
            const dropdown = btn.nextElementSibling;
            const parentRow = btn.closest('tr');
            const isShowing = dropdown.classList.contains('show');

            // Close all dropdowns first
            document.querySelectorAll('.action-dropdown').forEach(function(d) {
                d.classList.remove('show');
                d.classList.remove('show-above');
            });
            document.querySelectorAll('.action-menu').forEach(function(m) {
                m.classList.remove('open');
            });
            document.querySelectorAll('#reportTable tbody tr').forEach(function(tr) {
                tr.classList.remove('dropdown-open');
            });

            if (isShowing) {
                return;
            }

            // Check if dropdown should show above or below
            const btnRect = btn.getBoundingClientRect();
            const windowHeight = window.innerHeight;
            const spaceBelow = windowHeight - btnRect.bottom;

            if (spaceBelow < 180) {
                dropdown.classList.add('show-above');
            }

            actionMenu.classList.add('open');
            dropdown.classList.add('show');
            if (parentRow) {
                parentRow.classList.add('dropdown-open');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.action-menu')) {
                document.querySelectorAll('.action-dropdown').forEach(function(dropdown) {
                    dropdown.classList.remove('show');
                    dropdown.classList.remove('show-above');
                });
                document.querySelectorAll('.action-menu').forEach(function(m) {
                    m.classList.remove('open');
                });
                document.querySelectorAll('#reportTable tbody tr').forEach(function(tr) {
                    tr.classList.remove('dropdown-open');
                });
            }
        });

        // Modal functions
        function openTargetModal(userId, currentTarget, currentDate) {
            document.getElementById('targetUserId').value = userId;
            document.getElementById('targetDate').value = currentDate || new Date().toISOString().split('T')[0];
            document.getElementById('targetValue').value = currentTarget || '';
            document.getElementById('targetModal').classList.add('show');
            // Close any open dropdowns
            document.querySelectorAll('.action-dropdown').forEach(function(dropdown) {
                dropdown.classList.remove('show');
            });
        }

        function closeTargetModal() {
            document.getElementById('targetModal').classList.remove('show');
        }

        function saveTarget() {
            const userId = document.getElementById('targetUserId').value;
            const targetDate = document.getElementById('targetDate').value;
            const targetValue = document.getElementById('targetValue').value;

            if (!targetDate || !targetValue) {
                showToast('Please fill in all fields', true);
                return;
            }

            fetch('save_target.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: userId,
                        target_started: targetDate,
                        target: targetValue
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Target saved successfully');
                        closeTargetModal();
                        // Reload DataTable to reflect new target
                        window.dataTable.ajax.reload();
                    } else {
                        showToast('Error: ' + data.message, true);
                    }
                })
                .catch(error => {
                    showToast('Error saving target', true);
                    console.error(error);
                });
        }

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTargetModal();
                closeSummariesModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('targetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTargetModal();
            }
        });

        // Toast notification
        function showToast(message, isError = false) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            toastMessage.textContent = message;
            toast.classList.remove('error');
            if (isError) {
                toast.classList.add('error');
            }
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // View Summaries Modal functions
        function openSummariesModal() {
            const selectedUser = document.getElementById('user_id').value;
            if (!selectedUser) {
                showToast('Please select a specific user first', true);
                return;
            }

            // Show modal with loading state
            document.getElementById('summariesUserName').textContent = selectedUser;
            document.getElementById('summariesLoading').style.display = 'block';
            document.getElementById('summariesEmpty').style.display = 'none';
            document.getElementById('summariesTable').style.display = 'none';
            document.getElementById('summariesTableBody').innerHTML = '';
            document.getElementById('summariesModal').classList.add('show');

            // Fetch list of monthly summaries
            fetch('list-summaries.php?username=' + encodeURIComponent(selectedUser))
                .then(response => response.json())
                .then(data => {
                    document.getElementById('summariesLoading').style.display = 'none';

                    if (data.error) {
                        showToast('Error: ' + data.error, true);
                        closeSummariesModal();
                        return;
                    }

                    if (!data.files || data.files.length === 0) {
                        document.getElementById('summariesEmpty').style.display = 'block';
                        return;
                    }

                    // Populate table
                    const tbody = document.getElementById('summariesTableBody');
                    data.files.forEach(function(file, index) {
                        const viewUrl = 'view-summary.php?path=' + encodeURIComponent(file.path);
                        const downloadUrl = viewUrl + '&download=1';
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="padding: 10px 15px;">${index + 1}</td>
                            <td style="padding: 10px 15px;">${escapeHtml(file.name)}</td>
                            <td style="padding: 10px 15px;">
                                <a href="${viewUrl}" target="_blank" class="btn btn-primary" style="padding: 4px 12px; font-size: 0.85rem; margin-right: 5px;">View</a>
                                <a href="${downloadUrl}" target="_blank" class="btn btn-success" style="padding: 4px 12px; font-size: 0.85rem;">Download</a>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    document.getElementById('summariesTable').style.display = 'table';
                })
                .catch(error => {
                    document.getElementById('summariesLoading').style.display = 'none';
                    showToast('Error loading summaries', true);
                    closeSummariesModal();
                    console.error(error);
                });
        }

        function closeSummariesModal() {
            document.getElementById('summariesModal').classList.remove('show');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // Close summaries modal on escape key and click outside
        document.getElementById('summariesModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSummariesModal();
            }
        });

        // Export to Excel - fetches ALL data from API
        function exportToExcel() {
            const btn = document.getElementById('exportBtn');
            const btnText = document.getElementById('exportBtnText');
            const btnLoading = document.getElementById('exportBtnLoading');

            // Show loading state
            btn.disabled = true;
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline';

            // Fetch ALL data for export
            const exportUrl = `../api/call-report-data.php?date_from=${filters.dateFrom}&date_to=${filters.dateTo}&user_id=${filters.userId}&department_id=${filters.departmentId}&export=true`;

            fetch(exportUrl)
                .then(response => response.json())
                .then(json => {
                    if (!json.data || json.data.length === 0) {
                        showToast('No data to export', true);
                        btn.disabled = false;
                        btnText.style.display = 'inline';
                        btnLoading.style.display = 'none';
                        return;
                    }

                    const data = [];

                    // Add headers
                    data.push(['Date', 'User Name', 'Department', 'Incoming Calls', 'Incoming Duration',
                        'Outgoing Calls', 'Target', 'Short Fall', 'Outgoing Duration', 'Total Call Time'
                    ]);

                    // Add data rows
                    json.data.forEach(row => {
                        data.push([
                            row.date,
                            row.user_id,
                            row.department_name,
                            row.total_incoming_calls,
                            row.total_incoming_duration,
                            row.total_outgoing_calls,
                            row.target,
                            row.shortfall,
                            row.total_outgoing_duration,
                            row.total_call_time
                        ]);
                    });

                    // Create workbook and worksheet
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(data);

                    // Set column widths
                    ws['!cols'] = [{
                            wch: 12
                        }, // Date
                        {
                            wch: 15
                        }, // User Name
                        {
                            wch: 15
                        }, // Department
                        {
                            wch: 15
                        }, // Incoming Calls
                        {
                            wch: 18
                        }, // Incoming Duration
                        {
                            wch: 15
                        }, // Outgoing Calls
                        {
                            wch: 10
                        }, // Target
                        {
                            wch: 12
                        }, // Short Fall
                        {
                            wch: 18
                        }, // Outgoing Duration
                        {
                            wch: 15
                        }, // Total Call Time
                    ];

                    // Add worksheet to workbook
                    XLSX.utils.book_append_sheet(wb, ws, 'Call Report');

                    // Generate filename with date
                    const now = new Date();
                    const filename = 'Call_Report_' + now.getFullYear() + '-' +
                        String(now.getMonth() + 1).padStart(2, '0') + '-' +
                        String(now.getDate()).padStart(2, '0') + '_' +
                        String(now.getHours()).padStart(2, '0') +
                        String(now.getMinutes()).padStart(2, '0') + '.xlsx';

                    // Download file
                    XLSX.writeFile(wb, filename);

                    showToast('Excel sheet exported successfully!');

                    // Reset button state
                    btn.disabled = false;
                    btnText.style.display = 'inline';
                    btnLoading.style.display = 'none';
                })
                .catch(error => {
                    console.error('Export error:', error);
                    showToast('Error exporting data', true);
                    btn.disabled = false;
                    btnText.style.display = 'inline';
                    btnLoading.style.display = 'none';
                });
        }
    </script>
</body>

</html>