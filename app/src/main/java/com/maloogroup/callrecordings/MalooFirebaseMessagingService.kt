package com.maloogroup.callrecordings

import android.content.Context
import android.os.PowerManager
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class MalooFirebaseMessagingService : FirebaseMessagingService() {

    // Called when FCM delivers a message to the device
    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        super.onMessageReceived(remoteMessage)

        val type = remoteMessage.data["type"] ?: return

        when (type) {
            "health_check" -> {
                val requestId = remoteMessage.data["request_id"] ?: ""
                // adm_ (admin panel) and hc_ (daily/health pings) both flush recent
                // recordings first, then report — the notification is now the PRIMARY
                // upload trigger (survives OEM background-kill). Any other prefix stays
                // lightweight: report immediately, no upload.
                if (requestId.startsWith("adm_") || requestId.startsWith("hc_")) {
                    triggerUploadThenHealthCheck(requestId)
                } else {
                    triggerHealthCheck(requestId)
                }
            }
            // Future message types can be added here
        }
    }

    // Called when FCM rotates the token automatically
    // We save the new token and push it to backend
    override fun onNewToken(token: String) {
        super.onNewToken(token)

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val store = ConfigStore(applicationContext)
                store.saveFcmToken(token)
                FcmTokenUploader(applicationContext).uploadToken(token)
            } catch (_: Exception) {
                // Silently fail — will retry on next app open
            }
        }
    }

    // Enqueues HealthCheckWorker via WorkManager so it runs
    // even if the app is in the background / Doze mode
    private fun triggerHealthCheck(requestId: String) {
        val work = OneTimeWorkRequestBuilder<HealthCheckWorker>()
            .setInputData(workDataOf("request_id" to requestId))
            .build()

        WorkManager.getInstance(applicationContext)
            .enqueue(work)
    }

    // Admin ping: upload recent recordings (bounded window), then run the health check.
    // REPLACE so the chain always runs (and always reports) even if a job is pending.
    // The health check runs only after the upload succeeds — UploaderWorker returns
    // success() on every terminal path, so the report is guaranteed once the upload settles.
    private fun triggerUploadThenHealthCheck(requestId: String) {
        val uploadReq = OneTimeWorkRequestBuilder<UploaderWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .addTag(AppConstants.WORK_TASK_UPLOAD)
            .build()

        val healthReq = OneTimeWorkRequestBuilder<HealthCheckWorker>()
            .setInputData(workDataOf("request_id" to requestId))
            .build()

        WorkManager.getInstance(applicationContext)
            .beginUniqueWork(AppConstants.UNIQUE_WORK_NAME, ExistingWorkPolicy.REPLACE, uploadReq)
            .then(healthReq)
            .enqueue()
    }
}