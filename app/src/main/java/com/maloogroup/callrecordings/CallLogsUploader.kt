package com.maloogroup.callrecordings

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

/**
 * Thrown when a call-log POST fails.
 * @param httpCode the HTTP status, or 0 for a network/connection failure (no response).
 *
 * 4xx (except 408/429) = the server permanently rejected this body — retrying won't help.
 * 0 / 5xx / 408 / 429 = transient — safe to retry later.
 */
class CallLogUploadException(val httpCode: Int, message: String) : Exception(message) {
    val isPermanent: Boolean
        get() = httpCode in 400..499 && httpCode != 408 && httpCode != 429
}

/**
 * Uploads call logs JSON to the server.
 * Configure the API endpoint in AppConstants.CALL_LOGS_API_ENDPOINT
 */
class CallLogsUploader {

    private val jsonMedia = "application/json".toMediaType()

    /**
     * Sends call logs JSON to the server via HTTP POST
     * @param jsonData The call logs JSON string
     * @param userId The uploader ID (device name)
     * @throws Exception if upload fails
     */
    fun uploadCallLogs(jsonData: String, userId: String, departmentId: Int) {
        val url = AppConstants.CALL_LOGS_API_ENDPOINT
        
        val req = Request.Builder()
            .url(url)
            .header("Content-Type", "application/json")
            .header("User-Agent", "CallRecordingsApp/1.0")
            .header("X-Device-ID", userId)  // Optional: send device ID as header
            .post(jsonData.toRequestBody(jsonMedia))
            .build()

        val res = try {
            Http.client.newCall(req).execute()
        } catch (e: IOException) {
            // No response — connection/DNS/timeout. Transient.
            throw CallLogUploadException(0, "Call logs upload network error: ${e.message}")
        }
        res.use {
            if (it.code != 200 && it.code != 201) {
                val body = it.body?.string().orEmpty()
                throw CallLogUploadException(it.code, "Call logs upload failed: ${it.code} $body")
            }
        }
    }
}
