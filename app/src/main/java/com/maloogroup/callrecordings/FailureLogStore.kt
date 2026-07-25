package com.maloogroup.callrecordings

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant
import java.time.LocalDate

class FailureLogStore(private val ctx: Context) {

    companion object {
        private const val MAX_ENTRIES = 50

        private fun classifyError(error: String): String = when {
            error.contains("UnknownHostException") -> "NETWORK_DNS"
            error.contains("SocketTimeoutException") || error.contains("timeout", ignoreCase = true) -> "NETWORK_TIMEOUT"
            error.contains("IOException") -> "NETWORK_IO"
            error.contains("401") -> "AUTH"
            error.contains("Chunk upload failed") || error.contains("createUploadSession failed") -> "SERVER"
            else -> "UNKNOWN"
        }
    }

    suspend fun addFailure(
        fileName: String,
        error: String,
        step: String = "",
        attempt: Int = 0,
        fileSizeBytes: Long = 0L
    ) {
        val store = ConfigStore(ctx)
        val prefs = store.read()

        val existing = try {
            JSONArray(prefs[Keys.failureLog] ?: "[]")
        } catch (_: Exception) {
            JSONArray()
        }

        val entry = JSONObject().apply {
            put("date", LocalDate.now().toString())
            put("failed_file", fileName)
            put("error", error)
            put("error_type", classifyError(error))
            put("step", step)
            put("attempt", attempt)
            put("file_size_bytes", fileSizeBytes)
            put("failed_at", Instant.now().toString())
        }

        val updated = JSONArray()
        updated.put(entry)
        for (i in 0 until existing.length()) {
            updated.put(existing.getJSONObject(i))
        }

        val trimmed = JSONArray()
        val limit = minOf(updated.length(), MAX_ENTRIES)
        for (i in 0 until limit) {
            trimmed.put(updated.getJSONObject(i))
        }

        store.updateFailureLog(trimmed.toString())
    }

    suspend fun clearLog() {
        ConfigStore(ctx).updateFailureLog("[]")
    }
}
