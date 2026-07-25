package com.maloogroup.callrecordings

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.time.LocalDateTime
import java.time.ZoneId
import java.util.concurrent.TimeUnit

object WorkScheduler {
    private const val ALARM_REQUEST_CODE = 7001

    fun scheduleNextRun(context: Context, hour: Int, minute: Int) {
        val appContext = context.applicationContext
        val am = appContext.getSystemService(AlarmManager::class.java)

        val now = LocalDateTime.now()
        var next = now.withHour(hour).withMinute(minute).withSecond(0).withNano(0)
        if (!next.isAfter(now)) next = next.plusDays(1)
        val triggerMs = next.atZone(ZoneId.systemDefault()).toInstant().toEpochMilli()

        val pi = buildPendingIntent(appContext)
        am.cancel(pi)

        when {
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && am.canScheduleExactAlarms() ->
                am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerMs, pi)
            else ->
                // Fallback: fires during next Doze maintenance window — typically within minutes
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerMs, pi)
        }
    }

    // Periodic watchdog — the primary same-day guarantee. KEEP so repeated calls
    // don't reset its schedule. WorkManager persists this across reboot/app-kill.
    fun ensurePeriodicWatchdog(context: Context) {
        val req = PeriodicWorkRequestBuilder<UploadWatchdogWorker>(
            AppConstants.WATCHDOG_INTERVAL_HOURS, TimeUnit.HOURS
        )
            .addTag(AppConstants.WORK_TASK_WATCHDOG)
            .build()

        WorkManager.getInstance(context.applicationContext)
            .enqueueUniquePeriodicWork(
                AppConstants.WATCHDOG_UNIQUE_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                req
            )
    }

    fun cancelWatchdog(context: Context) {
        WorkManager.getInstance(context.applicationContext)
            .cancelUniqueWork(AppConstants.WATCHDOG_UNIQUE_NAME)
    }

    fun isAlarmScheduled(context: Context): Boolean {
        val intent = Intent(context.applicationContext, AlarmReceiver::class.java)
        val pi = PendingIntent.getBroadcast(
            context.applicationContext,
            ALARM_REQUEST_CODE,
            intent,
            PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE
        )
        return pi != null
    }

    private fun buildPendingIntent(context: Context): PendingIntent {
        val intent = Intent(context, AlarmReceiver::class.java)
        return PendingIntent.getBroadcast(
            context,
            ALARM_REQUEST_CODE,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }
}
