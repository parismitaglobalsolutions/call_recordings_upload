package com.maloogroup.callrecordings

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager

class AlarmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val req = OneTimeWorkRequestBuilder<UploaderWorker>()
            .setConstraints(constraints)
            .addTag(AppConstants.WORK_TASK_UPLOAD)
            .build()

        WorkManager.getInstance(context)
            .enqueueUniqueWork(AppConstants.UNIQUE_WORK_NAME, ExistingWorkPolicy.REPLACE, req)

        WorkScheduler.ensurePeriodicWatchdog(context)
    }
}
