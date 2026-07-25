package com.maloogroup.callrecordings

import android.content.Context
import android.os.Build
import android.util.Log
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

class FcmTokenUploader(private val ctx: Context) {

    private val jsonMedia = "application/json".toMediaType()
    suspend fun uploadToken(token: String) {
        val store = ConfigStore(ctx)
        val prefs = store.read()

        val userId = (prefs[Keys.uploaderId] ?: "").trim()
        val departmentId = prefs[Keys.departmentId] ?: 0

        // Don't send if setup is not done yet
        if (userId.isEmpty()) return

        val payload = JSONObject().apply {
            put("user_id", userId)
            put("department_id", departmentId)
            put("fcm_token", token)
            put("app_version", AppConstants.getAppVersion())
            put("device_model", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
            put("android_version", Build.VERSION.RELEASE)
            put("timestamp", java.time.Instant.now().toString())
        }

        val req = Request.Builder()
            .url(AppConstants.FCM_TOKEN_ENDPOINT)
            .header("Content-Type", "application/json")
            .post(payload.toString().toRequestBody(jsonMedia))
            .build()

        Http.client.newCall(req).execute().use { res ->
            val body = res.body?.string().orEmpty()

            if (!res.isSuccessful) {
                error("FCM token upload failed: ${res.code} $body")
            }

            // Parse response
            val json = JSONObject(body)
            val success = json.optBoolean("success", false)

            if (!success) {
                val error = json.optString("error", "Unknown error")
                error("FCM token rejected by server: $error")
            }

            // Log outcome for debugging
            val tokenUpdated = json.optBoolean("token_updated", false)
            val userCreated = json.optBoolean("user_created", false)

            Log.d("FCM", when {
                userCreated -> "New device registered with FCM token"
                tokenUpdated -> "FCM token updated for existing user"
                else -> "FCM token unchanged — no update needed"
            })
        }
    }
}