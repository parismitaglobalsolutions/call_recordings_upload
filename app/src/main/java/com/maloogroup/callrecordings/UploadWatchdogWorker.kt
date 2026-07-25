package com.maloogroup.callrecordings

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId

/**
 * Periodic watchdog — the primary "fire no matter what" guarantee.
 *
 * Runs every WATCHDOG_INTERVAL_HOURS, survives reboot/app-kill (WorkManager
 * persists periodic work). On each tick it:
 *   1. Re-arms the exact alarm (self-heals if the alarm was cancelled/suppressed).
 *   2. Re-ensures itself is scheduled.
 *   3. If today's scheduled time has passed and there has been no successful
 *      upload today, enqueues the real UploaderWorker (unique name, KEEP →
 *      never double-runs alongside the alarm-triggered job).
 *
 * The upload it enqueues uses the default (bounded) lookback window.
 */
class UploadWatchdogWorker(
    private val appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        try {
            val prefs = ConfigStore(appContext).read()

            val driveId = (prefs[Keys.driveId] ?: "").trim()
            val treeUri = (prefs[Keys.treeUri] ?: "").trim()
            val recordingsFolderId = (prefs[Keys.recordingsFolderId] ?: "").trim()
            // Setup not finished — nothing to do, but keep the watchdog alive.
            if (driveId.isEmpty() || treeUri.isEmpty() || recordingsFolderId.isEmpty()) {
                return@withContext Result.success()
            }

            val hour = AppConstants.FALLBACK_HOUR
            val minute = AppConstants.FALLBACK_MINUTE

            // Self-heal the schedule on every tick.
            WorkScheduler.scheduleNextRun(appContext, hour, minute)
            WorkScheduler.ensurePeriodicWatchdog(appContext)

            val now = LocalDateTime.now()
            val scheduledToday = now.withHour(hour).withMinute(minute).withSecond(0).withNano(0)

            val lastSuccess = (prefs[Keys.lastSuccessAt] ?: "").trim()
            val lastSuccessDate = if (lastSuccess.isNotEmpty()) {
                try {
                    Instant.parse(lastSuccess).atZone(ZoneId.systemDefault()).toLocalDate()
                } catch (_: Exception) { null }
            } else null

            val succeededToday = lastSuccessDate == now.toLocalDate()
            val timePassed = !now.isBefore(scheduledToday)

            if (timePassed && !succeededToday) {
                val req = OneTimeWorkRequestBuilder<UploaderWorker>()
                    .setConstraints(
                        Constraints.Builder()
                            .setRequiredNetworkType(NetworkType.CONNECTED)
                            .build()
                    )
                    .addTag(AppConstants.WORK_TASK_UPLOAD)
                    .build()
                WorkManager.getInstance(appContext)
                    .enqueueUniqueWork(
                        AppConstants.UNIQUE_WORK_NAME,
                        ExistingWorkPolicy.KEEP,
                        req
                    )
            }

            Result.success()
        } catch (_: Exception) {
            // Watchdog must never crash — failing here would kill the safety net.
            Result.success()
        }
    }
}
