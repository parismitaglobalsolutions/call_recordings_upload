<?php
/**
 * Notification Logs — Field & Status Reference.
 * Standalone info page (no sidebar). Opens in a new tab from fcm-logs.php.
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs Info — Field & Status Reference</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f3f5f9; }
        .info-wrap { max-width: 1000px; margin: 0 auto; padding: 36px 22px 80px; }
        .info-header { margin-bottom: 32px; }
        .info-header h1 {
            font-size: 1.85rem; color: #2c3e50; margin-bottom: 6px;
        }
        .info-header .subtitle { color: #7f8c8d; font-size: 0.95rem; }
        .info-header .top-actions { float: right; margin-top: 4px; }
        .info-header::after { content: ""; display: block; clear: both; }

        .section-title {
            font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.08em;
            color: #2980b9; margin: 40px 0 14px; padding-bottom: 8px;
            border-bottom: 1px solid #dfe3e8; font-weight: 700;
        }
        .section-title:first-of-type { margin-top: 10px; }

        .field-card {
            background: #fff; border: 1px solid #e2e6ec; border-radius: 12px;
            padding: 18px 22px; margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .field-header {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 8px; flex-wrap: wrap;
        }
        .field-key {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.95rem; color: #2980b9; font-weight: 600;
        }
        .type-tag {
            font-size: 0.72rem; color: #7f8c8d; font-family: ui-monospace, Menlo, Consolas, monospace;
        }
        .info-badge {
            font-size: 0.68rem; font-weight: 700; padding: 3px 9px;
            border-radius: 999px; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .bg-ok      { background: #d4f4e2; color: #14532d; }
        .bg-warn    { background: #fff0c2; color: #8a6d00; }
        .bg-crit    { background: #fde2e2; color: #842029; }
        .bg-info    { background: #dbeafe; color: #1e3a8a; }

        .field-desc { color: #4a5568; font-size: 0.92rem; margin-bottom: 10px; }

        .info-table {
            width: 100%; border-collapse: collapse; font-size: 0.88rem;
            background: #f8fafc; border-radius: 8px; overflow: hidden;
        }
        .info-table th {
            text-align: left; padding: 8px 12px; background: #e9eef5;
            color: #2c3e50; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .info-table td {
            padding: 9px 12px; border-top: 1px solid #e2e6ec; vertical-align: top;
        }
        .info-table tr:first-child td { border-top: none; }

        .val-code {
            font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.82rem;
            background: #eef2f7; padding: 2px 6px; border-radius: 4px;
        }
        .txt-ok { color: #14532d; font-weight: 600; }
        .txt-warn { color: #8a6d00; font-weight: 600; }
        .txt-danger { color: #842029; font-weight: 600; }
        .txt-info { color: #1e3a8a; font-weight: 600; }
        .action-hint { font-size: 0.82rem; color: #6c757d; }

        .note {
            background: #edf4ff; border: 1px solid #c7d9f4; border-radius: 10px;
            padding: 12px 16px; font-size: 0.88rem; color: #2c3e50; margin-top: 10px;
        }
        .note strong { color: #1d3557; }

        .btn-back {
            display: inline-block; padding: 6px 14px; background: #3498db; color: #fff;
            border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 500;
        }
        .btn-back:hover { background: #2980b9; }
    </style>
</head>

<body>
    <div class="info-wrap">
        <div class="info-header">
            <div class="top-actions">
                <a class="btn-back" href="fcm-logs.php">← Back to Logs</a>
            </div>
            <h1>Notification Logs — Field & Status Reference</h1>
            <div class="subtitle">Explanation of every column in the Notification Logs table, every FCM status we store, and every field in the health-check payload the mobile app returns.</div>
        </div>

        <!-- ============ OUR LOGS ============ -->
        <div class="section-title">Notification Logs Table</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">Sent At</span>
                <span class="type-tag">timestamp</span>
                <span class="info-badge bg-info">Timing</span>
            </div>
            <div class="field-desc">Server-local timestamp when we attempted to send the FCM message. Stored in <code class="val-code">fcm_notifications.sent_at</code>.</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">User ID</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Identity</span>
            </div>
            <div class="field-desc">The target user's OneDrive folder name (matches <code class="val-code">users.user_id</code>).</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">Type</span>
                <span class="type-tag">string enum</span>
                <span class="info-badge bg-info">Category</span>
            </div>
            <div class="field-desc">Which part of the system triggered this send.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-info">health_check</td><td>Sent by the periodic cron <code class="val-code">cron/send-health-check.php</code> to every active user.</td></tr>
                <tr><td class="val-code txt-info">admin_manual</td><td>Sent manually by an admin from the <em>Users</em> page "Send Notification" button.</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">HTTP Status</span>
                <span class="type-tag">integer</span>
                <span class="info-badge bg-info">FCM Response</span>
            </div>
            <div class="field-desc">Raw HTTP status code returned by the Firebase Cloud Messaging HTTP v1 API.</div>
            <table class="info-table">
                <tr><th>Code</th><th>Meaning</th><th>Our Action</th></tr>
                <tr><td class="val-code txt-ok">200</td><td class="txt-ok">Message accepted by FCM</td><td class="action-hint">Log as SUCCESS, stop there</td></tr>
                <tr><td class="val-code txt-warn">400</td><td class="txt-warn">INVALID_ARGUMENT — usually a bad/expired token</td><td class="action-hint">Mark token invalid; clear <code class="val-code">fcm_token</code></td></tr>
                <tr><td class="val-code txt-danger">401</td><td class="txt-danger">UNAUTHENTICATED — service account credentials failed</td><td class="action-hint">Abort the batch; config issue</td></tr>
                <tr><td class="val-code txt-danger">403</td><td class="txt-danger">SENDER_ID_MISMATCH — token belongs to a different Firebase project</td><td class="action-hint">Mark token invalid</td></tr>
                <tr><td class="val-code txt-warn">404</td><td class="txt-warn">UNREGISTERED — app uninstalled or token revoked</td><td class="action-hint">Mark token invalid; clear <code class="val-code">fcm_token</code></td></tr>
                <tr><td class="val-code txt-warn">429</td><td class="txt-warn">QUOTA_EXCEEDED — too many requests</td><td class="action-hint">Transient; retry next cron run</td></tr>
                <tr><td class="val-code txt-warn">500 / 503 / 504</td><td class="txt-warn">FCM server-side error</td><td class="action-hint">Transient; retry next cron run</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">FCM Status</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">FCM Response</span>
            </div>
            <div class="field-desc">The symbolic status extracted from Firebase's error body (or <code class="val-code">SUCCESS</code> on 200). Drives the colored badge in the logs table.</div>
            <table class="info-table">
                <tr><th>Status</th><th>Bucket</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">SUCCESS</td><td class="txt-ok">success</td><td>FCM accepted the message for delivery.</td></tr>
                <tr><td class="val-code txt-danger">UNREGISTERED</td><td class="txt-danger">invalid_token</td><td>Token no longer valid (app uninstalled or cleared).</td></tr>
                <tr><td class="val-code txt-danger">INVALID_ARGUMENT</td><td class="txt-danger">invalid_token</td><td>Malformed/stale token.</td></tr>
                <tr><td class="val-code txt-danger">SENDER_ID_MISMATCH</td><td class="txt-danger">invalid_token</td><td>Token was issued for a different Firebase sender.</td></tr>
                <tr><td class="val-code txt-danger">NOT_FOUND</td><td class="txt-danger">invalid_token</td><td>Token not found in FCM registry.</td></tr>
                <tr><td class="val-code txt-warn">UNAVAILABLE</td><td class="txt-warn">transient</td><td>FCM temporarily unavailable — retry.</td></tr>
                <tr><td class="val-code txt-warn">INTERNAL</td><td class="txt-warn">transient</td><td>FCM internal error — retry.</td></tr>
                <tr><td class="val-code txt-warn">DEADLINE_EXCEEDED</td><td class="txt-warn">transient</td><td>Request timed out inside FCM — retry.</td></tr>
                <tr><td class="val-code txt-warn">QUOTA_EXCEEDED</td><td class="txt-warn">transient</td><td>Rate-limited — retry next cron.</td></tr>
                <tr><td class="val-code txt-warn">RESOURCE_EXHAUSTED</td><td class="txt-warn">transient</td><td>Same as QUOTA_EXCEEDED.</td></tr>
                <tr><td class="val-code txt-warn">ABORTED</td><td class="txt-warn">transient</td><td>Concurrent conflict in FCM — retry.</td></tr>
                <tr><td class="val-code txt-danger">UNAUTHENTICATED</td><td class="txt-danger">config_error</td><td>Service account auth failed — fix server config.</td></tr>
                <tr><td class="val-code txt-danger">PERMISSION_DENIED</td><td class="txt-danger">config_error</td><td>Service account missing permissions.</td></tr>
                <tr><td class="val-code txt-warn">NETWORK_ERROR</td><td class="txt-warn">transient</td><td>Curl failed to reach FCM (DNS, timeout, etc.).</td></tr>
                <tr><td class="val-code">UNKNOWN</td><td class="action-hint">unknown</td><td>Unrecognized status — inspect raw response in the Error modal.</td></tr>
            </table>
            <div class="note">
                <strong>Action bucket:</strong> <code>invalid_token</code> → stop sending to this user until they re-register. <code>transient</code> → leave token in place, cron will retry. <code>config_error</code> → whole batch aborts, operator must fix service account or Firebase project config.
            </div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">Error</span>
                <span class="type-tag">button</span>
                <span class="info-badge bg-crit">Conditional</span>
            </div>
            <div class="field-desc">The <strong>View Error</strong> button appears only when the send failed. Opens a modal showing the FCM error status, HTTP code, message, and the raw Firebase response.</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">App Status</span>
                <span class="type-tag">button</span>
                <span class="info-badge bg-info">Conditional</span>
            </div>
            <div class="field-desc">The <strong>Show Status</strong> button appears only when the mobile app has already responded to this ping (matched by <code class="val-code">request_id</code>). Opens a modal with the diagnostics payload described below in the <em>Health Check Payload</em> sections.</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">Request ID</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Tracking</span>
            </div>
            <div class="field-desc">Opaque ID generated by the backend when sending the silent push. The mobile app echoes it in its health-check response so we can correlate send → receive.</div>
            <table class="info-table">
                <tr><th>Prefix</th><th>Source</th></tr>
                <tr><td class="val-code">hc_…</td><td>Cron-sent health check</td></tr>
                <tr><td class="val-code">adm_…</td><td>Admin-triggered manual send</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">device_status</span>
                <span class="type-tag">enum (users table)</span>
                <span class="info-badge bg-info">Device State</span>
            </div>
            <div class="field-desc">Per-user flag on the <code class="val-code">users</code> table controlling whether the cron will still target this device.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Cron behaviour</th></tr>
                <tr><td class="val-code txt-ok">active</td><td class="txt-ok">Device has a valid token and has not rejected recent sends</td><td class="action-hint">Included in the send loop</td></tr>
                <tr><td class="val-code txt-warn">unreachable</td><td class="txt-warn">Reserved — device has been silent on health checks for too long (not yet auto-flipped)</td><td class="action-hint">Still sent to; meant for admin UI filtering</td></tr>
                <tr><td class="val-code txt-danger">invalid_token</td><td class="txt-danger">FCM permanently rejected the stored token; it has been cleared</td><td class="action-hint">Skipped until user re-registers via <code class="val-code">/api/fcm-token.php</code></td></tr>
            </table>
        </div>

        <!-- ============ HEALTH-CHECK PAYLOAD ============ -->
        <div class="section-title">Health Check Payload — Root Fields</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">user_id</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Identity</span>
            </div>
            <div class="field-desc">The OneDrive folder name configured by the user during setup. Uniquely identifies the device/employee in the system.</div>
            <table class="info-table">
                <tr><th>Example</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-info">"TestAkshayGupta"</td><td>Employee's configured folder name</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">department_id</span>
                <span class="type-tag">integer</span>
                <span class="info-badge bg-info">Identity</span>
            </div>
            <div class="field-desc">Department this user belongs to, selected during app setup.</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">app_version</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Info</span>
            </div>
            <div class="field-desc">The installed version of the app on this device. Useful for detecting outdated app versions.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">"1.0.4"</td><td class="txt-ok">Latest version installed</td></tr>
                <tr><td class="val-code txt-warn">"1.0.1"</td><td class="txt-warn">Outdated version — user needs to update</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">device_model</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Info</span>
            </div>
            <div class="field-desc">Manufacturer and model of the device. Useful for identifying problematic phone brands (Xiaomi, OPPO, Vivo, Realme) that have aggressive battery management.</div>
            <table class="info-table">
                <tr><th>Example</th><th>Note</th></tr>
                <tr><td class="val-code">"Xiaomi 23076RN4BI"</td><td class="txt-warn">Xiaomi/Redmi — check battery optimization carefully</td></tr>
                <tr><td class="val-code">"samsung SM-A325F"</td><td class="txt-ok">Samsung — generally more reliable background tasks</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">android_version</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Info</span>
            </div>
            <div class="field-desc">Android OS version installed on the device.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Note</th></tr>
                <tr><td class="val-code">"15"</td><td>Android 15 — latest, stricter background rules apply</td></tr>
                <tr><td class="val-code">"12"</td><td>Android 12 — hibernation feature present</td></tr>
                <tr><td class="val-code">"11"</td><td>Android 11 — auto-revoke permissions feature present</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">ping_received_at</span>
                <span class="type-tag">ISO 8601 timestamp</span>
                <span class="info-badge bg-info">Timing</span>
            </div>
            <div class="field-desc">The exact time the device received the silent FCM health-check ping and started collecting diagnostics. All times are in UTC.</div>
            <table class="info-table">
                <tr><th>Example</th><th>Meaning</th></tr>
                <tr><td class="val-code">"2026-04-19T12:38:21Z"</td><td>Device was reachable and responded at this time</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">request_id</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Tracking</span>
            </div>
            <div class="field-desc">Unique ID of the ping request sent by the backend. Used to match a sent ping with the received health-check response. Format: <code class="val-code">hc_{date}_{time}_{random}</code> or <code class="val-code">adm_{date}_{time}_{random}</code>.</div>
            <table class="info-table">
                <tr><th>Example</th><th>Meaning</th></tr>
                <tr><td class="val-code">"hc_20260419_123820_7627"</td><td>Cron ping sent on April 19, 2026 at 12:38:20</td></tr>
            </table>
        </div>

        <!-- app_status -->
        <div class="section-title">app_status Fields</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">is_battery_optimized</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-warn">Warning</span>
            </div>
            <div class="field-desc">Whether Android battery optimization is ON for this app. When ON, Android may delay or kill background upload jobs — especially on Xiaomi, OPPO, Vivo, and Realme devices.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">false</td><td class="txt-ok">Battery optimization is OFF — background uploads run freely</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-warn">true</td><td class="txt-warn">Battery optimization is ON — uploads may be delayed or skipped</td><td class="action-hint">Ask user: Settings → Apps → [App] → Battery → Select "Unrestricted"</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">is_background_restricted</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether Android has restricted background activity for this app. When restricted, WorkManager jobs are blocked entirely. Only detectable on Android 9 (Pie) and above.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">false</td><td class="txt-ok">Background activity allowed</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">true</td><td class="txt-danger">Background work is BLOCKED — uploads will never run</td><td class="action-hint">Ask user: Settings → Apps → [App] → Battery → Remove restriction</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">has_call_log_permission</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether the app has permission to read the device call logs. Without this, call metadata (direction, duration, SIM slot) cannot be sent to the server.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">true</td><td class="txt-ok">Call log access granted</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">false</td><td class="txt-danger">Call log permission denied — no call metadata will be sent</td><td class="action-hint">Ask user: Settings → Apps → [App] → Permissions → Phone → Allow</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">has_storage_permission</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether the app still has access to the recordings folder selected during setup (via Android Storage Access Framework). If revoked, the app cannot read or upload any recording files.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">true</td><td class="txt-ok">Recordings folder access granted</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">false</td><td class="txt-danger">Folder access lost — no recordings will be uploaded</td><td class="action-hint">Ask user to open app and re-select the recordings folder in setup</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">has_notification_permission</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-warn">Warning</span>
            </div>
            <div class="field-desc">Whether the app has notification permission. Required on Android 13+ for FCM silent push to work reliably. On Android 12 and below this is always true.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">true</td><td class="txt-ok">Notifications allowed — FCM fully reliable</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-warn">false</td><td class="txt-warn">Notifications denied — silent health-check pings may be unreliable on Android 13+</td><td class="action-hint">Ask user: Settings → Apps → [App] → Notifications → Allow</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">is_app_hibernated</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether Android has put this app into hibernation due to long inactivity. Android 12+ automatically hibernates apps not used for weeks — this revokes permissions and stops all background work. This is the most severe state an app can be in.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">false</td><td class="txt-ok">App is active — not hibernated</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">true</td><td class="txt-danger">App is HIBERNATED — all permissions may be revoked, no background work runs</td><td class="action-hint">Ask user to open the app once to wake it from hibernation. Then re-grant any revoked permissions.</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">is_auto_revoke_enabled</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-warn">Warning</span>
            </div>
            <div class="field-desc">Whether Android's auto-revoke feature is enabled for this app. Android 11+ can automatically remove permissions if the app is not used for a few months. If the user does not open the app regularly, permissions like call log access may disappear silently.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">false</td><td class="txt-ok">Auto-revoke disabled — permissions are permanent</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-warn">true</td><td class="txt-warn">Auto-revoke ON — permissions may disappear if app is unused for months</td><td class="action-hint">Ask user: Settings → Apps → [App] → Permissions → Pause app activity if unused → Turn OFF</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">can_schedule_exact_alarm</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether the app is allowed to schedule exact alarms (Android 12+ <code class="val-code">SCHEDULE_EXACT_ALARM</code> permission). The daily upload job relies on an exact alarm to fire on time; without this permission Android may delay or skip the alarm, breaking scheduled uploads.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">true</td><td class="txt-ok">Exact alarm permission granted — upload alarm fires on time</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">false</td><td class="txt-danger">Exact alarm permission denied — scheduled uploads may not fire reliably</td><td class="action-hint">Ask user: Settings → Apps → [App] → Alarms &amp; reminders → Allow</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">alarm_scheduled</span>
                <span class="type-tag">boolean</span>
                <span class="info-badge bg-crit">Critical</span>
            </div>
            <div class="field-desc">Whether the app currently has the daily upload alarm registered with Android's AlarmManager. If <code class="val-code">false</code>, no alarm exists on the device and the upload job will never start on its own — usually caused by a force stop, reinstall, or setup never completing.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Action</th></tr>
                <tr><td class="val-code txt-ok">true</td><td class="txt-ok">Alarm is registered — upload will trigger at the scheduled time</td><td class="action-hint">No action needed</td></tr>
                <tr><td class="val-code txt-danger">false</td><td class="txt-danger">No alarm registered — uploads will never auto-start on this device</td><td class="action-hint">Ask user to open the app to re-schedule the alarm. If <code class="val-code">can_schedule_exact_alarm</code> is also false, grant that permission first.</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">fcm_token</span>
                <span class="type-tag">string</span>
                <span class="info-badge bg-info">Identity</span>
            </div>
            <div class="field-desc">The current Firebase Cloud Messaging token for this device. Used by the backend to send silent pings. If this token differs from the stored token, it means Firebase rotated it and the stored value should be updated.</div>
            <table class="info-table">
                <tr><th>State</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">Non-empty string</td><td class="txt-ok">Device is reachable via FCM</td></tr>
                <tr><td class="val-code txt-danger">Empty string ""</td><td class="txt-danger">Token missing — FCM not initialized or setup incomplete</td></tr>
            </table>
            <div class="note">
                <strong>Note:</strong> The token is never shown in the admin App Status modal — it is stripped server-side before the row is sent to the browser.
            </div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">work_manager_state</span>
                <span class="type-tag">string enum</span>
                <span class="info-badge bg-info">Upload Status</span>
            </div>
            <div class="field-desc">The current state of the daily scheduled upload job (WorkManager). This tells you exactly what the upload scheduler is doing on the device right now.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th><th>Severity</th></tr>
                <tr><td class="val-code txt-ok">ENQUEUED</td><td>Job is scheduled and waiting for its scheduled time</td><td class="txt-ok">Healthy</td></tr>
                <tr><td class="val-code txt-ok">RUNNING</td><td>Upload is currently in progress right now</td><td class="txt-ok">Healthy</td></tr>
                <tr><td class="val-code txt-ok">SUCCEEDED</td><td>Last upload run completed successfully</td><td class="txt-ok">Healthy</td></tr>
                <tr><td class="val-code txt-warn">FAILED</td><td>Last upload run failed — check last_error field</td><td class="txt-warn">Warning</td></tr>
                <tr><td class="val-code txt-warn">BLOCKED</td><td>Job is waiting for constraints — usually no internet connection at scheduled time</td><td class="txt-warn">Warning</td></tr>
                <tr><td class="val-code txt-danger">CANCELLED</td><td>Job was explicitly cancelled — app may have been force stopped or reinstalled</td><td class="txt-danger">Critical</td></tr>
                <tr><td class="val-code txt-danger">NOT_FOUND</td><td>No scheduled job exists at all — app was force stopped, reinstalled, or setup never completed</td><td class="txt-danger">Critical</td></tr>
            </table>
        </div>

        <!-- last_run -->
        <div class="section-title">last_run Fields</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_run_at</span>
                <span class="type-tag">ISO 8601 or empty</span>
                <span class="info-badge bg-info">Timing</span>
            </div>
            <div class="field-desc">The last time the upload worker started running (whether it succeeded or failed). Empty string means the worker has never run on this device.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">"2026-04-19T07:00:01Z"</td><td class="txt-ok">Worker ran at this time</td></tr>
                <tr><td class="val-code txt-warn">""</td><td class="txt-warn">Worker has never run — app may be newly installed or reset</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_success_at</span>
                <span class="type-tag">ISO 8601 or empty</span>
                <span class="info-badge bg-info">Timing</span>
            </div>
            <div class="field-desc">The last time the upload worker completed fully and successfully. Compare this with today's date to know how long ago the last successful upload was.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">"2026-04-18T19:00:04Z"</td><td class="txt-ok">Last successful upload was yesterday evening</td></tr>
                <tr><td class="val-code txt-warn">""</td><td class="txt-warn">No successful upload has ever happened on this device</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_error</span>
                <span class="type-tag">string or empty</span>
                <span class="info-badge bg-warn">Error Info</span>
            </div>
            <div class="field-desc">The error message from the last failed upload attempt. Empty string means the last run had no errors.</div>
            <table class="info-table">
                <tr><th>Example</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">""</td><td class="txt-ok">No error — last run was clean</td></tr>
                <tr><td class="val-code txt-warn">"Chunk upload failed 503"</td><td class="txt-warn">OneDrive server error during upload</td></tr>
                <tr><td class="val-code txt-danger">"Folder not accessible (SAF)"</td><td class="txt-danger">Recordings folder permission was lost</td></tr>
                <tr><td class="val-code txt-danger">"Missing config (treeUri/driveId)"</td><td class="txt-danger">Setup was never completed or data was cleared</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_uploaded_file_name</span>
                <span class="type-tag">string or empty</span>
                <span class="info-badge bg-info">Info</span>
            </div>
            <div class="field-desc">The remote filename of the last successfully uploaded recording. Format: <code class="val-code">{direction}_{userId}_{originalName}_{appVersion}_{callId}.{ext}</code></div>
            <table class="info-table">
                <tr><th>Example</th><th>Meaning</th></tr>
                <tr><td class="val-code">"out_TestMK_rec_1.0.4_512.m4a"</td><td>Outgoing call recording uploaded successfully</td></tr>
                <tr><td class="val-code">"in_TestMK_rec_1.0.4_508.m4a"</td><td>Incoming call recording uploaded successfully</td></tr>
                <tr><td class="val-code txt-warn">""</td><td class="txt-warn">No file has been uploaded yet</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_run_total_audio_count</span>
                <span class="type-tag">integer</span>
                <span class="info-badge bg-info">Stats</span>
            </div>
            <div class="field-desc">Total number of audio recording files found in the recordings folder during the last upload run.</div>
            <table class="info-table">
                <tr><th>Value</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">15</td><td class="txt-ok">15 recordings were found and attempted</td></tr>
                <tr><td class="val-code txt-warn">0</td><td class="txt-warn">No recordings found — folder may be empty or wrong folder selected</td></tr>
            </table>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">last_run_uploaded_count</span>
                <span class="type-tag">integer</span>
                <span class="info-badge bg-info">Stats</span>
            </div>
            <div class="field-desc">Number of recordings successfully uploaded and deleted from device in the last run. Compare with <code class="val-code">last_run_total_audio_count</code> to see if any files failed.</div>
            <table class="info-table">
                <tr><th>Scenario</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-ok">total = 15, uploaded = 15</td><td class="txt-ok">All recordings uploaded successfully</td></tr>
                <tr><td class="val-code txt-warn">total = 15, uploaded = 12</td><td class="txt-warn">3 files failed — check failure_log for details</td></tr>
                <tr><td class="val-code txt-danger">total = 15, uploaded = 0</td><td class="txt-danger">All uploads failed — likely a network or OneDrive issue</td></tr>
            </table>
        </div>

        <!-- failure_log -->
        <div class="section-title">failure_log Fields</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">failure_log</span>
                <span class="type-tag">array of objects</span>
                <span class="info-badge bg-warn">Error History</span>
            </div>
            <div class="field-desc">List of the last 50 individual file upload failures across all runs. Newest failures appear first. Cleared automatically after a fully successful run. Each entry contains the following fields:</div>
            <table class="info-table">
                <tr><th>Sub-field</th><th>Type</th><th>Meaning</th></tr>
                <tr><td class="val-code txt-info">date</td><td>string</td><td>Date the failure occurred — format YYYY-MM-DD</td></tr>
                <tr><td class="val-code txt-info">failed_file</td><td>string</td><td>Remote filename that failed to upload</td></tr>
                <tr><td class="val-code txt-info">error</td><td>string</td><td>Error message — e.g. "Chunk upload failed 503", "openInputStream returned null"</td></tr>
                <tr><td class="val-code txt-info">failed_at</td><td>ISO timestamp</td><td>Exact time the failure occurred in UTC</td></tr>
            </table>
            <div class="note">
                <strong>Note:</strong> An empty array <code class="val-code">[]</code> means either no failures have occurred, or the last full run was successful and cleared the log. This is the healthy state.
            </div>
        </div>

        <!-- reachability -->
        <div class="section-title">Device Reachability (FCM Delivery)</div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">FCM Delivery Success</span>
                <span class="info-badge bg-ok">Reachable</span>
            </div>
            <div class="field-desc">If a health-check response is received from a device, it means FCM delivery succeeded and the app is alive on that device.</div>
        </div>

        <div class="field-card">
            <div class="field-header">
                <span class="field-key">FCM Delivery Failure</span>
                <span class="info-badge bg-crit">Unreachable</span>
            </div>
            <div class="field-desc">If FCM returns an error when sending a ping to a token, the device is unreachable. This happens in these cases:</div>
            <table class="info-table">
                <tr><th>FCM Error</th><th>Cause</th></tr>
                <tr><td class="val-code txt-danger">UNREGISTERED / 404</td><td class="txt-danger">App was uninstalled — token is dead</td></tr>
                <tr><td class="val-code txt-danger">No response / timeout</td><td class="txt-danger">App was force stopped or disabled</td></tr>
                <tr><td class="val-code txt-warn">TOKEN_NOT_REGISTERED</td><td class="txt-warn">App data was cleared — new token needed when user reopens app</td></tr>
            </table>
            <div class="note">
                <strong>Admin action:</strong> If a device has not responded to 2 or more consecutive pings, mark it as <strong>Unreachable</strong> and notify the manager.
            </div>
        </div>
    </div>
</body>

</html>
