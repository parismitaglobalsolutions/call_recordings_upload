package com.maloogroup.callrecordings

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED &&
            intent.action != "android.intent.action.QUICKBOOT_POWERON"
        ) return

        val pendingResult = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val prefs = ConfigStore(context).read()
                val driveId = (prefs[Keys.driveId] ?: "").trim()
                if (driveId.isEmpty()) return@launch   // setup not yet completed

                val hour = AppConstants.FALLBACK_HOUR
                val minute = AppConstants.FALLBACK_MINUTE

                // If today's scheduled time has already passed and we have no success today,
                // enqueue an immediate catch-up run rather than waiting until tomorrow.
                val now = LocalDateTime.now()
                val scheduledToday = now.withHour(hour).withMinute(minute).withSecond(0).withNano(0)
                val lastSuccess = (prefs[Keys.lastSuccessAt] ?: "").trim()
                val lastSuccessDate = if (lastSuccess.isNotEmpty()) {
                    try {
                        Instant.parse(lastSuccess).atZone(ZoneId.systemDefault()).toLocalDate()
                    } catch (_: Exception) { null }
                } else null

                if (scheduledToday.isBefore(now) && lastSuccessDate != now.toLocalDate()) {
                    val constraints = Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                    val req = OneTimeWorkRequestBuilder<UploaderWorker>()
                        .setConstraints(constraints)
                        .addTag(AppConstants.WORK_TASK_UPLOAD)
                        .build()
                    WorkManager.getInstance(context)
                        .enqueueUniqueWork(AppConstants.UNIQUE_WORK_NAME, ExistingWorkPolicy.REPLACE, req)
                }

                WorkScheduler.scheduleNextRun(context, hour, minute)
                WorkScheduler.ensurePeriodicWatchdog(context)
            } catch (_: Exception) {
                // Boot receiver must never crash
            } finally {
                pendingResult.finish()
            }
        }
    }
}
