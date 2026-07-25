package com.maloogroup.callrecordings

import android.content.Context
import android.net.Uri
import android.util.Log
import androidx.documentfile.provider.DocumentFile
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.time.Instant
import java.time.LocalDateTime
import java.util.Date
import java.util.Locale

class UploaderWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    private val audioExt = setOf("mp3", "m4a", "wav", "aac", "ogg", "flac", "amr", "3gp")

    private fun isNetworkReady(): Boolean {
        return try {
            val addr = java.net.InetAddress.getByName("login.microsoftonline.com")
            addr.hostAddress != null
        } catch (_: Exception) {
            false
        }
    }

    private fun isAudio(name: String?): Boolean {
        if (name.isNullOrBlank()) return false
        val dot = name.lastIndexOf(".")
        if (dot < 0) return false
        val ext = name.substring(dot + 1).lowercase()
        return audioExt.contains(ext)
    }

    private fun nowIso(): String = Instant.now().toString()

    private fun tsFileSafe(dt: LocalDateTime): String {
        fun two(v: Int) = v.toString().padStart(2, '0')
        return "${dt.year}${two(dt.monthValue)}${two(dt.dayOfMonth)}_${two(dt.hour)}${two(dt.minute)}${two(dt.second)}"
    }

    private fun uploadStamp(): String {
        // YYYYMMDD_HHMMSS
        return SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(Date())
    }

    private fun safePart(s: String): String {
        // Keep it simple: replace spaces with _, avoid weird chars in SharePoint names
        return s.trim()
            .replace(Regex("""\s+"""), "_")
            .replace(Regex("""[\\/:*?"<>|]"""), "_")
    }

    private fun buildRemoteFileName(deviceId: String, originalName: String, directionPrefix: String = "", callId: String = ""): String {
        val dev = safePart(deviceId)
        val cleanOriginal = safePart(originalName)
        val base = cleanOriginal.substringBeforeLast('.', cleanOriginal)
        val ext = cleanOriginal.substringAfterLast('.', "")
        val dir = if (directionPrefix.isBlank()) "unknown" else directionPrefix
        val id = if (callId.isBlank()) "0" else callId

        return if (ext.isBlank()) {
            "${dir}_${dev}_${base}_${AppConstants.getAppVersion()}_${id}"
        } else {
            "${dir}_${dev}_${base}_${AppConstants.getAppVersion()}_${id}.${ext}"
        }
    }


    private fun writeFailureJson(dir: DocumentFile, payload: Map<String, Any?>) {
        val name = "upload_failed_${tsFileSafe(LocalDateTime.now())}.json"
        val obj = JSONObject(payload)
        val f = dir.createFile("application/json", name) ?: return
        applicationContext.contentResolver.openOutputStream(f.uri)?.use { out ->
            out.write(obj.toString(2).toByteArray(Charsets.UTF_8))
        }
    }

    private fun buildFileToDirectionMap(callLogsJson: org.json.JSONObject, audioDocs: List<DocumentFile>): Map<Long, Map<String, String>> {
        val map = mutableMapOf<Long, Map<String, String>>()
        
        try {
            val calls = callLogsJson.optJSONArray("calls") ?: return map
            
            for (i in 0 until calls.length()) {
                val call = calls.getJSONObject(i)
                val direction = call.optString("direction", "unknown")
                val callID = call.optString("call_id", "unknown")

                val recording = call.optJSONObject("recording") ?: continue
                val fileName = recording.optString("file_name")
                
                // Find matching audio doc by name
                audioDocs.forEach { doc ->
                    if (doc.name?.equals(fileName, ignoreCase = true) == true) {
                        map[doc.lastModified()] = mapOf("direction" to direction, "call_id" to callID)
                    }
                }
            }
        } catch (_: Exception) {
            // If parsing fails, return empty map
        }
        
        return map
    }

    // Re-POSTs every queued call-log body (oldest first). Each success is deleted.
    // A body the server permanently rejects (4xx) is parked so it can't jam the queue —
    // the rest keep draining. A transient failure (network / 5xx) stops the run and is
    // retried next time, so nothing is ever lost. Never throws.
    private fun flushPendingCallLogs(uploaderId: String, departmentId: Int) {
        val pendingStore = PendingCallLogStore(applicationContext)
        val pending = pendingStore.list()
        if (pending.isEmpty()) return
        val uploader = CallLogsUploader()
        for (f in pending) {
            val body = try {
                f.readText(Charsets.UTF_8)
            } catch (_: Exception) {
                ""
            }
            if (body.isBlank()) {
                pendingStore.delete(f)
                continue
            }
            try {
                uploader.uploadCallLogs(body, uploaderId, departmentId)
                pendingStore.delete(f)
            } catch (e: CallLogUploadException) {
                if (e.isPermanent) {
                    pendingStore.park(f)   // poison body — skip it, keep draining the rest
                } else {
                    break                  // transient outage — stop, retry the whole queue next run
                }
            } catch (_: Exception) {
                break                      // unknown error — treat as transient
            }
        }
    }

    // Max call_ids remembered. Lookback is only MAX_LOOKBACK_DAYS, so ids older than that
    // never reappear in a body — pruning the oldest is safe.
    private val maxReportedIds = 2000

    // Reports the call-log body, but only if it contains calls we've never sent. Every
    // call is reported exactly once (dedup by call_id) — never re-posted, so a later run
    // can't overwrite a recording-matched call with a null one. Write-ahead persists the
    // body before POSTing so a crash never loses it. On a permanent (4xx) rejection the
    // body is parked and its calls are marked reported (we give up gracefully, never loop).
    private suspend fun postNewCallLogs(
        callLogsJsonStr: String?,
        uploaderId: String,
        departmentId: Int,
        store: ConfigStore,
        prevReportedCsv: String
    ) {
        if (callLogsJsonStr == null) return
        val bodyIds = extractCallIds(callLogsJsonStr)
        if (bodyIds.isEmpty()) return  // no calls in the window — nothing to report

        val reported = prevReportedCsv.split(",").filter { it.isNotBlank() }.toMutableList()
        val reportedSet = reported.toHashSet()
        if (bodyIds.none { it !in reportedSet }) return  // every call already sent

        val pendingStore = PendingCallLogStore(applicationContext)
        val savedBody = pendingStore.save(callLogsJsonStr)  // write-ahead
        try {
            CallLogsUploader().uploadCallLogs(callLogsJsonStr, uploaderId, departmentId)
            if (savedBody != null) pendingStore.delete(savedBody)
            markReported(bodyIds, reported, reportedSet, store)
        } catch (e: CallLogUploadException) {
            if (e.isPermanent) {
                // Server permanently rejects this body — park it and stop trying so we
                // don't recreate it every run. Data is preserved in the parked file.
                if (savedBody != null) pendingStore.park(savedBody)
                markReported(bodyIds, reported, reportedSet, store)
            }
            // transient (network/5xx): leave savedBody queued; flush retries it next run.
        } catch (_: Exception) {
            // unknown error — treat as transient, leave queued.
        }
    }

    private suspend fun markReported(
        bodyIds: List<String>,
        reported: MutableList<String>,
        reportedSet: MutableSet<String>,
        store: ConfigStore
    ) {
        for (id in bodyIds) {
            if (reportedSet.add(id)) reported.add(id)
        }
        val pruned = if (reported.size > maxReportedIds) {
            reported.subList(reported.size - maxReportedIds, reported.size)
        } else {
            reported
        }
        store.saveReportedCallIds(pruned.joinToString(","))
    }

    private fun extractCallIds(callLogsJsonStr: String): List<String> {
        return try {
            val calls = JSONObject(callLogsJsonStr).optJSONArray("calls") ?: return emptyList()
            val ids = ArrayList<String>(calls.length())
            for (i in 0 until calls.length()) {
                val id = calls.getJSONObject(i).optString("call_id")
                if (id.isNotBlank()) ids.add(id)
            }
            ids
        } catch (_: Exception) {
            emptyList()
        }
    }

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        val store = ConfigStore(applicationContext)

        // Keep the reliability safety net alive on every run.
        WorkScheduler.ensurePeriodicWatchdog(applicationContext)

        store.updateLedger(
            lastRunAt = nowIso(),
            lastError = ""
        )

        val prefs = store.read()

        val uploaderId = (prefs[Keys.uploaderId] ?: "").trim()
        val treeUriStr = (prefs[Keys.treeUri] ?: "").trim()
        val driveId = (prefs[Keys.driveId] ?: "").trim()
        val recordingsFolderId = (prefs[Keys.recordingsFolderId] ?: "").trim()

        // Fallback alarm fires at 20:15 (15 min after the 20:00 push) to avoid collision.
        val hour = AppConstants.FALLBACK_HOUR
        val minute = AppConstants.FALLBACK_MINUTE

        if (treeUriStr.isEmpty() || driveId.isEmpty() || recordingsFolderId.isEmpty()) {
            store.updateLedger(lastError = "Missing config (treeUri/driveId/recordingsFolderId).")
            WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
            return@withContext Result.success()
        }

        val root = DocumentFile.fromTreeUri(applicationContext, Uri.parse(treeUriStr))
        if (root == null) {
            store.updateLedger(lastError = "Folder not accessible (SAF).")
            WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
            return@withContext Result.success()
        }

        // Pre-check DNS before attempting any network calls
        if (!isNetworkReady()) {
            store.updateLedger(lastError = "Network DNS unavailable (attempt ${runAttemptCount + 1})")
            if (runAttemptCount >= 2) {
                WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
                return@withContext Result.success()
            }
            return@withContext Result.retry()
        }

        val departmentId = prefs[Keys.departmentId] ?: 0
        val prevReportedCsv = (prefs[Keys.reportedCallIds] ?: "")

        // STEP 1 — flush any call-log bodies that failed to POST on a previous run.
        // These reference recordings already uploaded+deleted, so they can never be
        // rebuilt; send them BEFORE today's data so nothing is lost or out of order.
        flushPendingCallLogs(uploaderId, departmentId)

        // Bounded catch-up window: automatic runs upload at most MAX_LOOKBACK_DAYS back.
        // Manual / first-install runs pass KEY_UPLOAD_ALL to upload everything.
        // Deduplication is handled by deleting each file after a successful upload —
        // if a file is still here, it was never successfully uploaded.
        val uploadAll = inputData.getBoolean(AppConstants.KEY_UPLOAD_ALL, false)
        val nowMs = System.currentTimeMillis()
        val cutoffMs = if (uploadAll) {
            0L
        } else {
            nowMs - AppConstants.MAX_LOOKBACK_DAYS * 24L * 60 * 60 * 1000
        }
        // Skip files touched in the last RECORDING_QUIESCENCE_MS — they may belong to an
        // in-progress call (still being written; no CallLog row yet). Caught next run.
        val freshCutoffMs = nowMs - AppConstants.RECORDING_QUIESCENCE_MS
        val audioDocs = root.listFiles().filter { doc ->
            doc.isFile && isAudio(doc.name) &&
                doc.lastModified() >= cutoffMs &&
                doc.lastModified() <= freshCutoffMs
        }

        // Fetch call logs once — reused for direction matching and backend upload
        val callLogsJsonStr: String? = try {
            val fetcher = CallLogFetcher(applicationContext)
            fetcher.getCallLogs(recordingFiles = audioDocs, sinceMs = cutoffMs)
        } catch (_: Exception) {
            null
        }

        // Build map of file modification time to call direction
        val fileToDirection = buildFileToDirectionMap(org.json.JSONObject(callLogsJsonStr ?: "{}"), audioDocs)

        store.updateLedger(
            lastRunTotalAudioCount = audioDocs.size,
            lastRunUploadedCount = 0
        )

        if (audioDocs.isEmpty()) {
            // No recordings to upload, but there may still be calls to report (recording
            // disabled/failed, or every recent file is within the quiescence window).
            // Report them so the backend always has call info, recording or not.
            postNewCallLogs(callLogsJsonStr, uploaderId, departmentId, store, prevReportedCsv)
            store.updateLedger(
                lastSuccessAt = nowIso(),
                lastError = ""
            )
            WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
            return@withContext Result.success()
        }

        val uploader = GraphUpload()
        var uploaded = 0

        for (doc in audioDocs) {
            val originalName = (doc.name ?: "").trim()
            if (originalName.isEmpty()) continue

            val size = doc.length()
            if (size <= 0L) continue

            // Get call direction for this file
            val direction:Map<String, String> = fileToDirection[doc.lastModified()] ?: mapOf()
            val directionPrefix = when (direction.getOrDefault("direction", "unknown")) {
                "incoming" -> "in"
                "outgoing" -> "out"
                else -> ""
            }

            val remoteName = buildRemoteFileName(
                deviceId = uploaderId.ifBlank { "device" },
                originalName = originalName,
                directionPrefix = directionPrefix,
                callId = direction.getOrDefault("call_id", "")
            )

            try {
                uploader.uploadViaSession(
                    ctx = applicationContext,
                    fileUri = doc.uri,
                    fileName = remoteName,
                    totalBytes = size,
                    driveId = driveId,
                    parentFolderItemId = recordingsFolderId,
                    chunkSize = 5 * 1024 * 1024
                )

                val deletedOriginal = doc.delete()
                uploaded++

                store.updateLedger(
                    lastUploadedFileName = remoteName,
                    lastRunUploadedCount = uploaded,
                    lastError = if (deletedOriginal) "" else "Uploaded but original delete failed for $originalName"
                )
            } catch (e: Exception) {
                // Optional — keep if you want local file logs too, remove if not needed
                try {
                    val payload = mapOf(
                        "timestamp" to nowIso(),
                        "uploaderId" to uploaderId,
                        "treeUri" to treeUriStr,
                        "driveId" to driveId,
                        "recordingsFolderId" to recordingsFolderId,
                        "originalFileName" to originalName,
                        "remoteFileName" to remoteName,
                        "uploadedCountSoFar" to uploaded,
                        "totalAudioFilesThisRun" to audioDocs.size,
                        "error" to e.toString()
                    )
                    writeFailureJson(root, payload)
                } catch (_: Exception) {
                    // ignore
                }

                store.updateLedger(
                    lastError = "FAILED at $originalName: $e",
                    lastRunUploadedCount = uploaded
                )

                // ✅ Log failure before retry/return
                FailureLogStore(applicationContext).addFailure(
                    fileName = remoteName,
                    error = e.toString(),
                    step = "file_upload",
                    attempt = runAttemptCount + 1,
                    fileSizeBytes = size
                )

                if (runAttemptCount >= 3) {
                    WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
                    return@withContext Result.success()
                }

                return@withContext Result.retry()
            }
        }

        // STEP 3 — report today's call-log body AFTER files are uploaded and deleted.
        // postNewCallLogs handles write-ahead, dedup-by-call_id, and retry queuing.
        postNewCallLogs(callLogsJsonStr, uploaderId, departmentId, store, prevReportedCsv)



        FailureLogStore(applicationContext).clearLog()

        store.updateLedger(
            lastSuccessAt = nowIso(),
            lastError = "",
            lastRunUploadedCount = uploaded,
            lastProcessedTimestamp = nowIso()
        )


        // ✅ Re-verify FCM token on every successful daily run
        try {
            val freshToken = com.google.android.gms.tasks.Tasks.await(
                com.google.firebase.messaging.FirebaseMessaging.getInstance().token
            )
            val storedToken = (prefs[Keys.fcmToken] ?: "").trim()
            if (freshToken != storedToken) {
                store.saveFcmToken(freshToken)
                FcmTokenUploader(applicationContext).uploadToken(freshToken)
                Log.d("FCM", "Token rotated — updated from background worker")
            }
        } catch (_: Exception) { }

        WorkScheduler.scheduleNextRun(applicationContext, hour, minute)
        return@withContext Result.success()
    }
}
