<?php
/**
 * Database Configuration
 * Update these values according to your MySQL server settings
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'maheshKumar_call_recording_db');
define('DB_USER', 'maheshKumar_crdb');
define('DB_PASS', 'Mahesh@123#');
define('DB_CHARSET', 'utf8mb4');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Compliance thresholds
define('COMPLIANCE_GREEN', 95);
define('COMPLIANCE_YELLOW', 85);

// Recording matching tolerances
define('RECORDING_START_TOLERANCE_BEFORE', 5);  // seconds before call start
define('RECORDING_START_TOLERANCE_AFTER', 60);  // seconds after call start
define('RECORDING_DURATION_MIN_PERCENT', 65);   // minimum percentage of call duration

// Firebase Cloud Messaging (FCM) configuration
define('FCM_SERVICE_ACCOUNT_JSON', __DIR__ . '/../callrecordingsupload-firebase-adminsdk-fbsvc-c12840a955.json');

// Microsoft Graph API / OneDrive configuration
define('ONEDRIVE_TENANT_ID', '32e8140c-1040-4a0c-94b0-9da2205bed94');
define('ONEDRIVE_CLIENT_ID', 'cdc65538-5859-46af-9dbf-2e840f717bbe');
define('ONEDRIVE_CLIENT_SECRET', 'o608Q~vzzo31mwbm4LKjdXFw9DBj1UBXUg5GPbin');
define('ONEDRIVE_DRIVE_OWNER', 'recordings@maloogroup.net');
define('ONEDRIVE_BASE_PATH', '/Recordings');
define('ONEDRIVE_SHARE_URL', 'https://malooblr-my.sharepoint.com/:f:/g/personal/recordings_maloogroup_net/IgAo3eUE7V5sTI7Dvl0bV40uAYRO1-Xw2SkDdoIMPRzun_k?e=SlbxSq');

