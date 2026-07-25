package com.maloogroup.callrecordings
import com.maloogroup.callrecordings.BuildConfig
object AppConstants {

    // Set to false before giving to client if they don't want a manual upload button
    const val SHOW_UPLOAD_NOW_BUTTON = true

    // Set to false before giving to client if they don't want device status sheet
    const val SHOW_PERMISSION_STATUS = false


    // Same as Flutter AppConstants.shareUrl
    const val SHARE_URL =
        "https://malooblr-my.sharepoint.com/:f:/g/personal/recordings_maloogroup_net/IgAo3eUE7V5sTI7Dvl0bV40uAYRO1-Xw2SkDdoIMPRzun_k?e=SlbxSq"

    // Same meaning as Flutter AppConstants.workTaskUpload
    // (Flutter Workmanager task name; on Android we still keep it for parity/logging)
    const val WORK_TASK_UPLOAD = "dailyUploadTask"

    // Same meaning as Flutter AppConstants.workUniqueName
    const val UNIQUE_WORK_NAME = "daily_upload_unique"

    // ── Reliability layer ────────────────────────────────────────────────
    // Periodic WorkManager watchdog: the primary same-day guarantee. It survives
    // reboot/app-kill, re-arms the exact alarm, and runs the upload if the alarm
    // was suppressed by OEM battery management.
    const val WATCHDOG_UNIQUE_NAME = "upload_watchdog_periodic"
    const val WORK_TASK_WATCHDOG = "uploadWatchdog"
    const val WATCHDOG_INTERVAL_HOURS = 3L

    // Catch-up window for automatic runs: when a run is missed, upload at most this
    // many days back (never dump months of old recordings). Manual / first-install
    // runs bypass this via KEY_UPLOAD_ALL.
    const val MAX_LOOKBACK_DAYS = 2

    // Worker input-data flag: when true, ignore MAX_LOOKBACK_DAYS and upload everything.
    const val KEY_UPLOAD_ALL = "upload_all"

    // Primary upload time = 20:00. This is when the server sends the daily FCM push
    // (the main upload trigger). Used for the setup-screen display and the scheduled_time
    // reported in the health check. The user no longer picks a time.
    const val SCHEDULED_HOUR = 20
    const val SCHEDULED_MINUTE = 0

    // Fallback time = 20:15. The on-device alarm + WorkManager watchdog + catch-up run
    // 15 min AFTER the push, so the local fallback never collides with the 20:00 push.
    // If the push already uploaded at 20:00, the 20:15 fallback finds nothing to do.
    const val FALLBACK_HOUR = 20
    const val FALLBACK_MINUTE = 15

    // Quiescence window: never upload/delete a recording modified within this window —
    // it may still be written by an in-progress call, and CallLog hasn't inserted the
    // row yet (so metadata wouldn't match). Such files are picked up on the next run.
    const val RECORDING_QUIESCENCE_MS = 2L * 60 * 1000  // 2 minutes

    // Server API endpoint for call logs
    const val CALL_LOGS_API_ENDPOINT = "https://investmg.in/api/call-data.php"
    const val DEPARTMENTS_API_ENDPOINT = "https://investmg.in/api/departments.php"
    const val FCM_TOKEN_ENDPOINT = "https://investmg.in/api/fcm-token.php"
    const val HEALTH_CHECK_ENDPOINT = "https://investmg.in/api/health-check.php"

    fun getAppVersion(): String {
        return BuildConfig.VERSION_NAME
    }
}
