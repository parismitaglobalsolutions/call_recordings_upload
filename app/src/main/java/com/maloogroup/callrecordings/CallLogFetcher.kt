package com.maloogroup.callrecordings

import android.content.Context
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.provider.CallLog
import androidx.documentfile.provider.DocumentFile
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

data class CallLogEntry(
    val callId: String,
    val startTime: String,      // Format: "yyyy-MM-dd HH:mm:ss"
    val durationSec: Int,
    val direction: String,       // "incoming" or "outgoing"
    val simSlot: Int,
    val startTimeMs: Long        // Timestamp in milliseconds for matching
)

data class RecordingEntry(
    val fileName: String,
    val startTime: String,       // Format: "yyyy-MM-dd HH:mm:ss"
    val durationSec: Int,
    val startTimeMs: Long        // Timestamp in milliseconds
)

class CallLogFetcher(private val ctx: Context) {

    suspend fun getCallLogs(recordingFiles: List<DocumentFile>? = null, sinceMs: Long = 0L): String? {
        return try {
            val store = ConfigStore(ctx)
            val prefs = store.read()

            val calls = fetchCalls(sinceMs)
            val dateStr = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
            val uploadTimeStr = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date())
            val userId = (prefs[Keys.uploaderId] ?: "").trim()
            val departmentId = prefs[Keys.departmentId] ?: 0

            // Extract recording metadata if files provided
            val recordingsByTime = if (recordingFiles != null) {
                extractRecordingMetadata(recordingFiles)
            } else {
                emptyMap()
            }

            val jsonObject = JSONObject()
            jsonObject.put("app_version", AppConstants.getAppVersion())
            jsonObject.put("user_id", userId)
            jsonObject.put("department_id", departmentId)

            jsonObject.put("date", dateStr)
            jsonObject.put("upload_time", uploadTimeStr)

            val callsArray = JSONArray()
            for (call in calls) {
                val callJson = JSONObject()
                callJson.put("call_id", call.callId)
                callJson.put("start_time", call.startTime)
                callJson.put("duration_sec", call.durationSec)
                callJson.put("direction", call.direction)
                callJson.put("sim_slot", call.simSlot)

                // Find matching recording based on call duration
                val matchingRecording = findMatchingRecording(call, recordingsByTime)
                if (matchingRecording != null) {
                    val recordingJson = JSONObject()
                    recordingJson.put("file_name", matchingRecording.fileName)
                    recordingJson.put("start_time", matchingRecording.startTime)
                    recordingJson.put("duration_sec", matchingRecording.durationSec)
                    callJson.put("recording", recordingJson)
                } else {
                    callJson.put("recording", JSONObject.NULL)
                }

                callsArray.put(callJson)
            }
            
            jsonObject.put("calls", callsArray)
            jsonObject.toString(2)
        } catch (e: Exception) {
            null
        }
    }

    private fun findMatchingRecording(
        call: CallLogEntry,
        recordingsByTime: Map<Long, RecordingEntry>
    ): RecordingEntry? {
        // Recording file is saved AFTER call ends, so match if:
        // recording_time >= call_start AND recording_time <= call_end + 30sec buffer
        val callStartMs = call.startTimeMs
        val callEndMs = callStartMs + (call.durationSec * 1000L)
        val bufferMs = 30_000L  // 30 second buffer after call ends
        
        return recordingsByTime.entries
            .filter { it.key >= callStartMs && it.key <= callEndMs + bufferMs }
            .minByOrNull { Math.abs(it.key - callEndMs) }  // Find closest to call end time
            ?.value
    }

    private fun extractRecordingMetadata(files: List<DocumentFile>): Map<Long, RecordingEntry> {
        val recordings = mutableMapOf<Long, RecordingEntry>()
        val audioExt = setOf("mp3", "m4a", "wav", "aac", "ogg", "flac", "amr", "3gp")

        for (file in files) {
            if (!file.isFile) continue
            val fileName = file.name ?: continue
            
            // Check if audio file
            val ext = fileName.substringAfterLast(".", "").lowercase()
            if (!audioExt.contains(ext)) continue

            try {
                // Get file last modified time (when file was saved)
                val lastModified = file.lastModified()
                if (lastModified <= 0) continue

                // Try to extract duration from file metadata
                val durationSec = getAudioDuration(file.uri) ?: 0

                // Calculate actual recording start time: file_save_time - duration
                val recordingStartMs = lastModified - (durationSec * 1000L)
                val recordingStartStr = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
                    .format(Date(recordingStartMs))

                val recording = RecordingEntry(
                    fileName = fileName,
                    startTime = recordingStartStr,
                    durationSec = durationSec,
                    startTimeMs = recordingStartMs
                )
                recordings[recordingStartMs] = recording
            } catch (_: Exception) {
                // Skip files that can't be processed
            }
        }
        return recordings
    }

    private fun getAudioDuration(fileUri: Uri): Int? {
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(ctx, fileUri)
            val durationMs = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)
                ?.toLongOrNull() ?: 0
            (durationMs / 1000).toInt()
        } catch (_: Exception) {
            null
        } finally {
            retriever.release()
        }
    }

    private fun fetchCalls(sinceMs: Long): List<CallLogEntry> {
        val calls = mutableListOf<CallLogEntry>()

        val projection = arrayOf(
            CallLog.Calls._ID,
            CallLog.Calls.DATE,
            CallLog.Calls.DURATION,
            CallLog.Calls.TYPE,
            CallLog.Calls.PHONE_ACCOUNT_ID
        )

        // sinceMs == 0 → all calls; otherwise only calls on/after the cutoff.
        val selection = if (sinceMs > 0L) "${CallLog.Calls.DATE} >= ?" else null
        val selectionArgs = if (sinceMs > 0L) arrayOf(sinceMs.toString()) else null

        try {
            ctx.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                projection,
                selection,
                selectionArgs,
                "${CallLog.Calls.DATE} DESC"
            )?.use { cursor ->
                val idColumn = cursor.getColumnIndexOrThrow(CallLog.Calls._ID)
                val dateColumn = cursor.getColumnIndexOrThrow(CallLog.Calls.DATE)
                val durationColumn = cursor.getColumnIndexOrThrow(CallLog.Calls.DURATION)
                val typeColumn = cursor.getColumnIndexOrThrow(CallLog.Calls.TYPE)
                val phoneAccountColumn = cursor.getColumnIndexOrThrow(CallLog.Calls.PHONE_ACCOUNT_ID)

                while (cursor.moveToNext()) {
                    val callId = cursor.getString(idColumn)
                    val dateMs = cursor.getLong(dateColumn)
                    val durationSec = cursor.getInt(durationColumn)
                    val typeInt = cursor.getInt(typeColumn)
                    val phoneAccountId = cursor.getString(phoneAccountColumn)

                    val direction = when (typeInt) {
                        CallLog.Calls.INCOMING_TYPE -> "incoming"
                        CallLog.Calls.OUTGOING_TYPE -> "outgoing"
//                        CallLog.Calls.MISSED_TYPE -> "incoming"  // missed = incoming
                        else -> "unknown"
                    }

                    // Filter: Only include calls with duration > 0 and valid direction
                    if (durationSec <= 0 || direction == "unknown") {
                        continue
                    }

                    // Convert timestamp to "yyyy-MM-dd HH:mm:ss" format
                    val startTime = formatDateTime(dateMs)

                    // Extract SIM slot (0 or 1, or parse from phoneAccountId)
                    val simSlot = extractSimSlot(phoneAccountId)

                    calls.add(
                        CallLogEntry(
                            callId = callId,
                            startTime = startTime,
                            durationSec = durationSec,
                            direction = direction,
                            simSlot = simSlot,
                            startTimeMs = dateMs  // Store original timestamp for matching
                        )
                    )
                }
            }
        } catch (_: Exception) {
            // Handle SecurityException or other errors gracefully
        }

        return calls
    }

    private fun formatDateTime(timeMs: Long): String {
        return SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date(timeMs))
    }

    private fun extractSimSlot(phoneAccountId: String?): Int {
        if (phoneAccountId.isNullOrBlank()) return 0
        // Phone account ID format may vary by device
        // Common format: "ComponentInfo{com.android.phone/com.android.phone.PhoneAccountService}/1"
        return try {
            phoneAccountId.substringAfterLast("/").toIntOrNull() ?: 0
        } catch (_: Exception) {
            0
        }
    }
}
