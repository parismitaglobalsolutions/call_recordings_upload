package com.maloogroup.callrecordings

import android.content.Context
import java.io.File

/**
 * Durable retry queue for call-log API bodies.
 *
 * The call-log POST happens AFTER recordings are uploaded and deleted. If that POST
 * fails, the body can never be rebuilt (the recordings it references are already gone).
 * So we persist the exact JSON body to internal storage (never the SAF folder, so it
 * survives folder changes and is invisible to the user). Each pending body is one file.
 *
 * Flow:
 *   - Before POSTing today's body, [save] it (write-ahead) so a process-kill mid-POST
 *     never loses it.
 *   - On a successful POST, [delete] that file.
 *   - At the start of every upload run, [list] is flushed first (re-POST → delete on
 *     success), so yesterday's failed body goes up before today's data.
 */
class PendingCallLogStore(private val ctx: Context) {

    private fun dir(): File {
        val d = File(ctx.filesDir, DIR_NAME)
        if (!d.exists()) d.mkdirs()
        return d
    }

    /** Write-ahead persist. Returns the file so the caller can delete it after a successful POST. */
    fun save(body: String): File? {
        return try {
            val name = "calllog_${System.currentTimeMillis()}_${(0..9999).random()}.json"
            val f = File(dir(), name)
            f.writeText(body, Charsets.UTF_8)
            f
        } catch (_: Exception) {
            null
        }
    }

    /** Oldest-first so retries preserve original order. Parked (.parked) files are excluded. */
    fun list(): List<File> {
        return try {
            dir().listFiles { f -> f.isFile && f.name.endsWith(".json") }
                ?.sortedBy { it.name }
                ?: emptyList()
        } catch (_: Exception) {
            emptyList()
        }
    }

    fun delete(file: File) {
        try {
            file.delete()
        } catch (_: Exception) {
            // best-effort
        }
    }

    /**
     * Park a body the server permanently rejected (4xx). It's renamed to ".parked" so it's
     * NEVER retried and NEVER blocks the rest of the queue — but it's kept on disk (not
     * deleted) so the data is preserved for inspection. Nothing is ever lost.
     */
    fun park(file: File) {
        try {
            file.renameTo(File(file.parentFile, file.name + ".parked"))
        } catch (_: Exception) {
            // best-effort; if rename fails it just gets retried, no harm.
        }
    }

    companion object {
        private const val DIR_NAME = "pending_calllogs"
    }
}
