<?php
/**
 * Admin - Process Raw Data
 * Processes unprocessed JSON data with progress tracking
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();

// Get count of pending records
$pendingCount = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 0");
$processedCount = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 1");
$failedCount = $db->fetch("SELECT COUNT(*) as count FROM raw_data WHERE is_processed = 2");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Data - Call Recording Compliance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .process-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .warning-box {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            display: none;
        }

        .warning-box.show {
            display: block;
        }

        .warning-box h4 {
            color: #856404;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .warning-box p {
            color: #856404;
            margin: 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-card .number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-text);
        }

        .stat-card .label {
            color: var(--gray-text);
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .stat-card.pending .number {
            color: var(--warning-color);
        }

        .stat-card.success .number {
            color: var(--success-color);
        }

        .stat-card.failed .number {
            color: var(--danger-color);
        }

        .process-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .process-card h3 {
            margin-bottom: 20px;
        }

        .btn-process {
            padding: 15px 40px;
            font-size: 1.1rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-process:hover:not(:disabled) {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-process:disabled {
            background-color: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }

        .progress-section {
            margin-top: 30px;
            display: none;
        }

        .progress-section.show {
            display: block;
        }

        .progress-bar-container {
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            height: 30px;
            margin: 20px 0;
            position: relative;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--success-color));
            border-radius: 10px;
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-weight: 600;
            color: var(--dark-text);
        }

        .status-message {
            color: var(--gray-text);
            font-size: 0.95rem;
            margin-top: 15px;
        }

        .results-section {
            margin-top: 30px;
            display: none;
        }

        .results-section.show {
            display: block;
        }

        .results-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: left;
        }

        .results-box h4 {
            margin-bottom: 15px;
        }

        .results-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 300px;
            overflow-y: auto;
        }

        .results-list li {
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .results-list li:last-child {
            border-bottom: none;
        }

        .results-list .icon-success {
            color: var(--success-color);
        }

        .results-list .icon-error {
            color: var(--danger-color);
        }

        .completion-message {
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .completion-message.success {
            background-color: #d4edda;
            color: #155724;
        }

        .completion-message.partial {
            background-color: #fff3cd;
            color: #856404;
        }
    </style>
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
                <a href="non-compliant.php">Non-Compliant Calls</a>
                <a href="fcm-logs.php">App Logs</a> 
                <a href="process-data.php" class="active">Process Data</a>
                <a href="logout.php">Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>Process Raw Data</h1>
                <div class="header-actions">
                    <span>Welcome, <?php echo htmlspecialchars(getCurrentAdmin()); ?></span>
                </div>
            </div>

            <div class="process-container">
                <!-- Warning Box -->
                <div class="warning-box" id="warningBox">
                    <h4>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        Warning: Process in Progress
                    </h4>
                    <p><strong>Do not refresh, go back, or close this page</strong> until the process completes. Doing so may result in incomplete data processing.</p>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card pending">
                        <div class="number" id="pendingCount"><?php echo $pendingCount['count']; ?></div>
                        <div class="label">Pending Records</div>
                    </div>
                    <div class="stat-card success">
                        <div class="number" id="processedCount"><?php echo $processedCount['count']; ?></div>
                        <div class="label">Processed</div>
                    </div>
                    <div class="stat-card failed">
                        <div class="number" id="failedCount"><?php echo $failedCount['count']; ?></div>
                        <div class="label">Failed</div>
                    </div>
                </div>

                <!-- Process Card -->
                <div class="process-card">
                    <h3>Process Unprocessed Data</h3>
                    <p style="color: var(--gray-text); margin-bottom: 25px;">
                        Click the button below to process all pending raw data records.<br>
                        Each record will be processed with compliance calculations.
                    </p>

                    <button type="button" class="btn-process" id="processBtn" <?php echo $pendingCount['count'] == 0 ? 'disabled' : ''; ?>>
                        <?php echo $pendingCount['count'] == 0 ? 'No Pending Records' : 'Process Data'; ?>
                    </button>

                    <!-- Progress Section -->
                    <div class="progress-section" id="progressSection">
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" id="progressBar"></div>
                            <span class="progress-text" id="progressText">0%</span>
                        </div>
                        <div class="status-message" id="statusMessage">Initializing...</div>
                    </div>

                    <!-- Results Section -->
                    <div class="results-section" id="resultsSection">
                        <div class="results-box">
                            <h4>Processing Results</h4>
                            <ul class="results-list" id="resultsList"></ul>
                        </div>
                        <div class="completion-message" id="completionMessage"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const processBtn = document.getElementById('processBtn');
        const warningBox = document.getElementById('warningBox');
        const progressSection = document.getElementById('progressSection');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const statusMessage = document.getElementById('statusMessage');
        const resultsSection = document.getElementById('resultsSection');
        const resultsList = document.getElementById('resultsList');
        const completionMessage = document.getElementById('completionMessage');

        let totalRecords = 0;
        let processedRecords = 0;
        let successCount = 0;
        let failCount = 0;

        processBtn.addEventListener('click', startProcessing);

        async function startProcessing() {
            // Disable button and show warning
            processBtn.disabled = true;
            processBtn.textContent = 'Processing...';
            warningBox.classList.add('show');
            progressSection.classList.add('show');
            resultsSection.classList.remove('show');
            resultsList.innerHTML = '';

            // Prevent page close
            window.onbeforeunload = function() {
                return 'Processing is in progress. Are you sure you want to leave?';
            };

            try {
                // Get pending record IDs
                statusMessage.textContent = 'Fetching pending records...';
                const idsResponse = await fetch('process-data-ajax.php?action=get_pending_ids');
                const idsData = await idsResponse.json();

                if (!idsData.success || idsData.ids.length === 0) {
                    statusMessage.textContent = 'No pending records to process.';
                    finishProcessing();
                    return;
                }

                totalRecords = idsData.ids.length;
                processedRecords = 0;
                successCount = 0;
                failCount = 0;

                // Process each record one by one
                for (const id of idsData.ids) {
                    statusMessage.textContent = `Processing record ${processedRecords + 1} of ${totalRecords}...`;

                    try {
                        const response = await fetch('process-data-ajax.php?action=process_record&id=' + id);
                        const result = await response.json();

                        processedRecords++;

                        if (result.success) {
                            successCount++;
                            addResultItem(true, `Record #${id}: ${result.message}`);
                        } else {
                            failCount++;
                            addResultItem(false, `Record #${id}: ${result.error}`);
                        }
                    } catch (err) {
                        processedRecords++;
                        failCount++;
                        addResultItem(false, `Record #${id}: Network error`);
                    }

                    updateProgress();
                }

                finishProcessing();

            } catch (error) {
                statusMessage.textContent = 'Error: ' + error.message;
                finishProcessing();
            }
        }

        function updateProgress() {
            const percentage = Math.round((processedRecords / totalRecords) * 100);
            progressBar.style.width = percentage + '%';
            progressText.textContent = percentage + '%';
        }

        function addResultItem(success, message) {
            const li = document.createElement('li');
            li.innerHTML = `
                <span class="${success ? 'icon-success' : 'icon-error'}">
                    ${success ? '✓' : '✗'}
                </span>
                <span>${message}</span>
            `;
            resultsList.appendChild(li);
            resultsList.scrollTop = resultsList.scrollHeight;
        }

        function finishProcessing() {
            // Remove page close prevention
            window.onbeforeunload = null;

            // Update button
            processBtn.textContent = 'Process Data';

            // Show results
            resultsSection.classList.add('show');

            // Update status
            statusMessage.textContent = 'Processing complete!';

            // Show completion message
            if (failCount === 0 && successCount > 0) {
                completionMessage.className = 'completion-message success';
                completionMessage.innerHTML = `<strong>Success!</strong> All ${successCount} records processed successfully.`;
            } else if (successCount > 0 && failCount > 0) {
                completionMessage.className = 'completion-message partial';
                completionMessage.innerHTML = `<strong>Partial Success:</strong> ${successCount} records processed, ${failCount} failed.`;
            } else if (successCount === 0 && failCount > 0) {
                completionMessage.className = 'completion-message partial';
                completionMessage.innerHTML = `<strong>Error:</strong> All ${failCount} records failed to process.`;
            } else {
                completionMessage.className = 'completion-message success';
                completionMessage.innerHTML = `<strong>Complete!</strong> No records to process.`;
            }

            // Update stats display
            updateStatsDisplay();

            // Hide warning after 2 seconds
            setTimeout(() => {
                warningBox.classList.remove('show');
                // Re-enable button if there are more pending records
                checkPendingRecords();
            }, 2000);
        }

        async function updateStatsDisplay() {
            try {
                const response = await fetch('process-data-ajax.php?action=get_stats');
                const data = await response.json();
                if (data.success) {
                    document.getElementById('pendingCount').textContent = data.stats.pending;
                    document.getElementById('processedCount').textContent = data.stats.processed;
                    document.getElementById('failedCount').textContent = data.stats.failed;
                }
            } catch (e) {
                console.error('Failed to update stats:', e);
            }
        }

        async function checkPendingRecords() {
            try {
                const response = await fetch('process-data-ajax.php?action=get_stats');
                const data = await response.json();
                if (data.success && data.stats.pending > 0) {
                    processBtn.disabled = false;
                    processBtn.textContent = 'Process Data';
                } else {
                    processBtn.disabled = true;
                    processBtn.textContent = 'No Pending Records';
                }
            } catch (e) {
                processBtn.disabled = false;
            }
        }
    </script>
</body>
</html>
