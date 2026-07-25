package com.maloogroup.callrecordings

import android.app.ActivityManager
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.DocumentsContract
import androidx.core.content.ContextCompat
import androidx.work.CoroutineWorker
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant

class HealthCheckWorker(
    private val appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    private val jsonMedia = "application/json".toMediaType()

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        try {
            val requestId = inputData.getString("request_id") ?: ""
            val store = ConfigStore(appContext)
            val prefs = store.read()

            val userId = (prefs[Keys.uploaderId] ?: "").trim()
            val departmentId = prefs[Keys.departmentId] ?: 0

            // Don't proceed if setup was never completed
            if (userId.isEmpty()) return@withContext Result.success()

            val payload = buildPayload(
                prefs = prefs,
                userId = userId,
                departmentId = departmentId,
                requestId = requestId
            )

            postToBackend(payload)

            Result.success()
        } catch (_: Exception) {
            // Health check failure must never crash anything
            Result.success()
        }
    }

    private fun buildPayload(
        prefs: androidx.datastore.preferences.core.Preferences,
        userId: String,
        departmentId: Int,
        requestId: String
    ): JSONObject {

        // ── App Status ──────────────────────────────────────────
        val isBatteryOptimized = isBatteryOptimized()
        val isBackgroundRestricted = isBackgroundRestricted()
        val hasCallLogPermission = hasPermission(android.Manifest.permission.READ_CALL_LOG)
        val hasStoragePermission = hasStoragePermission()
        val fcmToken = (prefs[Keys.fcmToken] ?: "").trim()
        val workManagerState = getWorkManagerState()

        val appStatus = JSONObject().apply {
            put("is_battery_optimized", isBatteryOptimized)
            put("is_background_restricted", isBackgroundRestricted)
            put("has_call_log_permission", hasCallLogPermission)
            put("has_storage_permission", hasStoragePermission)
            put("has_notification_permission", hasNotificationPermission())
            put("is_app_hibernated", isAppHibernated())
            put("is_auto_revoke_enabled", isAutoRevokeEnabled())
            put("can_schedule_exact_alarm", canScheduleExactAlarm())
            put("alarm_scheduled", WorkScheduler.isAlarmScheduled(appContext))
            put("watchdog_scheduled", isWatchdogScheduled())
            put("fcm_token", fcmToken)
            put("work_manager_state", workManagerState)
        }

        // ── Last Run Ledger ──────────────────────────────────────
        val lastRun = JSONObject().apply {
            put("last_run_at", prefs[Keys.lastRunAt] ?: "")
            put("last_success_at", prefs[Keys.lastSuccessAt] ?: "")
            put("last_error", prefs[Keys.lastError] ?: "")
            put("last_uploaded_file_name", prefs[Keys.lastUploadedFileName] ?: "")
            put("last_run_total_audio_count", prefs[Keys.lastRunTotalAudioCount] ?: 0)
            put("last_run_uploaded_count", prefs[Keys.lastRunUploadedCount] ?: 0)
        }

        // ── Failure Log ──────────────────────────────────────────
        val failureLog = buildFailureLog(prefs)

        // ── Setup Info (configured during initial setup) ─────────
        val setupInfo = JSONObject().apply {
            put("uploader_name", userId)
            put("department_id", departmentId)
            put("department_name", (prefs[Keys.departmentName] ?: "").trim())
            put("scheduled_time", "%02d:%02d".format(
                AppConstants.SCHEDULED_HOUR, AppConstants.SCHEDULED_MINUTE))
        }

        // ── Recordings Folder (live state) ───────────────────────
        // current_recording_count: -1 means the folder couldn't be read.
        // For adm_ pings this runs AFTER the upload, so the count is what remains.
        val treeUriStr = (prefs[Keys.treeUri] ?: "").trim()
        val recCount = countAudioFiles(treeUriStr)
        val recordingsFolder = JSONObject().apply {
            put("folder_path", folderPath(treeUriStr))
            put("folder_uri", treeUriStr)
            put("current_recording_count", recCount)
            put("folder_accessible", recCount >= 0)
        }

        // ── Root Payload ─────────────────────────────────────────
        return JSONObject().apply {
            put("user_id", userId)
            put("department_id", departmentId)
            put("app_version", AppConstants.getAppVersion())
            put("device_model", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
            put("android_version", Build.VERSION.RELEASE)
            put("ping_received_at", Instant.now().toString())
            put("request_id", requestId)
            put("setup_info", setupInfo)
            put("recordings_folder", recordingsFolder)
            put("app_status", appStatus)
            put("last_run", lastRun)
            put("failure_log", failureLog)
        }
    }
    private fun canScheduleExactAlarm(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val am = appContext.getSystemService(Context.ALARM_SERVICE) as android.app.AlarmManager
            am.canScheduleExactAlarms()
        } else {
            true // permission not required before Android 12
        }
    }

    private fun isAppHibernated(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            try {
                val usageStatsManager = appContext.getSystemService(Context.USAGE_STATS_SERVICE)
                        as android.app.usage.UsageStatsManager
                val bucket = usageStatsManager.appStandbyBucket
                // STANDBY_BUCKET_RESTRICTED = 45 means hibernated/heavily restricted
                bucket >= 45
            } catch (_: Exception) {
                false
            }
        } else {
            false
        }
    }
    private fun isAutoRevokeEnabled(): Boolean {
        // Android 11+ — check if system can auto-revoke permissions on inactivity
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            try {
                val appOpsManager = appContext.getSystemService(Context.APP_OPS_SERVICE)
                        as android.app.AppOpsManager
                val mode = appOpsManager.unsafeCheckOpNoThrow(
                    "android:auto_revoke_permissions_if_unused",
                    android.os.Process.myUid(),
                    appContext.packageName
                )
                // MODE_ALLOWED = auto-revoke is ON (bad)
                // MODE_IGNORED = user disabled auto-revoke for this app (good)
                mode == android.app.AppOpsManager.MODE_ALLOWED
            } catch (_: Exception) {
                false
            }
        } else {
            false
        }
    }
    private fun hasNotificationPermission(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(
                appContext,
                android.Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            // Below Android 13 — permission not required, always true
            true
        }
    }

    // ── Diagnostics Helpers ──────────────────────────────────────

    private fun isBatteryOptimized(): Boolean {
        val pm = appContext.getSystemService(Context.POWER_SERVICE) as PowerManager
        return !pm.isIgnoringBatteryOptimizations(appContext.packageName)
    }

    private fun isBackgroundRestricted(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            val am = appContext.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
            am.isBackgroundRestricted
        } else {
            false // Not detectable on older Android
        }
    }

    private fun hasPermission(permission: String): Boolean {
        return ContextCompat.checkSelfPermission(
            appContext, permission
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun hasStoragePermission(): Boolean {
        // SAF tree URI is the storage access mechanism — if it's stored, access was granted
        // We verify it's still accessible by checking the persisted URI permissions
        return try {
            val treeUriStr = "" // will read from prefs in caller — checked below
            val persistedUris = appContext.contentResolver.persistedUriPermissions
            persistedUris.any { it.isReadPermission && it.isWritePermission }
        } catch (_: Exception) {
            false
        }
    }

    private fun getWorkManagerState(): String {
        return try {
            val workInfos = WorkManager.getInstance(appContext)
                .getWorkInfosForUniqueWork(AppConstants.UNIQUE_WORK_NAME)
                .get()

            when {
                workInfos.isNullOrEmpty() -> "NOT_FOUND"
                else -> workInfos.first().state.name // ENQUEUED, RUNNING, SUCCEEDED, etc.
            }
        } catch (_: Exception) {
            "NOT_FOUND"
        }
    }

    private val audioExt = setOf("mp3", "m4a", "wav", "aac", "ogg", "flac", "amr", "3gp")

    private fun isAudio(name: String?): Boolean {
        if (name.isNullOrBlank()) return false
        val ext = name.substringAfterLast('.', "").lowercase()
        return ext.isNotEmpty() && audioExt.contains(ext)
    }

    // Counts audio files currently in the recordings folder via a single cursor query
    // (efficient even for large folders). Returns -1 if the folder can't be read.
    private fun countAudioFiles(treeUriStr: String): Int {
        if (treeUriStr.isEmpty()) return -1
        return try {
            val treeUri = Uri.parse(treeUriStr)
            val docId = DocumentsContract.getTreeDocumentId(treeUri)
            val childrenUri = DocumentsContract.buildChildDocumentsUriUsingTree(treeUri, docId)
            var count = 0
            appContext.contentResolver.query(
                childrenUri,
                arrayOf(DocumentsContract.Document.COLUMN_DISPLAY_NAME),
                null, null, null
            )?.use { cursor ->
                while (cursor.moveToNext()) {
                    if (isAudio(cursor.getString(0))) count++
                }
            }
            count
        } catch (_: Exception) {
            -1
        }
    }

    // Readable folder path, e.g. "primary:Recordings/Call". Falls back to the raw URI.
    private fun folderPath(treeUriStr: String): String {
        if (treeUriStr.isEmpty()) return ""
        return try {
            val uri = Uri.parse(treeUriStr)
            DocumentsContract.getTreeDocumentId(uri) ?: uri.lastPathSegment ?: treeUriStr
        } catch (_: Exception) {
            treeUriStr
        }
    }

    // Periodic watchdog stays ENQUEUED between runs; RUNNING while executing.
    // If it's neither, the safety net is not registered on this device.
    private fun isWatchdogScheduled(): Boolean {
        return try {
            val infos = WorkManager.getInstance(appContext)
                .getWorkInfosForUniqueWork(AppConstants.WATCHDOG_UNIQUE_NAME)
                .get()
            infos != null && infos.any {
                it.state == WorkInfo.State.ENQUEUED || it.state == WorkInfo.State.RUNNING
            }
        } catch (_: Exception) {
            false
        }
    }

    // ── Failure Log Builder ──────────────────────────────────────

    private fun buildFailureLog(
        prefs: androidx.datastore.preferences.core.Preferences
    ): JSONArray {
        return try {
            val raw = prefs[Keys.failureLog] ?: return JSONArray()
            JSONArray(raw)
        } catch (_: Exception) {
            JSONArray()
        }
    }

    // ── HTTP POST ────────────────────────────────────────────────

    private fun postToBackend(payload: JSONObject) {
        val req = Request.Builder()
            .url(AppConstants.HEALTH_CHECK_ENDPOINT)
            .header("Content-Type", "application/json")
            .post(payload.toString().toRequestBody(jsonMedia))
            .build()

        Http.client.newCall(req).execute().use { res ->
            if (!res.isSuccessful) {
                val body = res.body?.string().orEmpty()
                error("Health check POST failed: ${res.code} $body")
            }
        }
    }
}