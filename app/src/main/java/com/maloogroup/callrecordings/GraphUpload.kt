package com.maloogroup.callrecordings

import android.content.Context
import android.net.Uri
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.BufferedSink
import org.json.JSONObject

class GraphUpload {

    private val jsonMedia = "application/json".toMediaType()

    private fun createUploadSession(
        driveId: String,
        parentFolderItemId: String,
        fileName: String
    ): String {
        val url = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$parentFolderItemId:/$fileName:/createUploadSession"
        val bodyJson = JSONObject()
            .put("item", JSONObject()
                .put("@microsoft.graph.conflictBehavior", "replace")
                .put("name", fileName)
            ).toString()

        fun callOnce(token: String): Pair<Int, String> {
            val req = Request.Builder()
                .url(url)
                .header("Authorization", "Bearer $token")
                .post(bodyJson.toRequestBody(jsonMedia))
                .build()
            Http.client.newCall(req).execute().use { res ->
                return res.code to (res.body?.string().orEmpty())
            }
        }

        var token = GraphAuthClient.getAppToken()
        var (code, body) = callOnce(token)

        if (code == 401) {
            GraphAuthClient.clearToken()
            token = GraphAuthClient.getAppToken()
            val retry = callOnce(token)
            code = retry.first
            body = retry.second
        }

        if (code != 200 && code != 201) error("createUploadSession failed $code: $body")
        return JSONObject(body).getString("uploadUrl")
    }

    fun uploadViaSession(
        ctx: Context,
        fileUri: Uri,
        fileName: String,
        totalBytes: Long,
        driveId: String,
        parentFolderItemId: String,
        chunkSize: Int = 5 * 1024 * 1024
    ) {
        val uploadUrl = createUploadSession(driveId, parentFolderItemId, fileName)
        var offset = 0L
        val cr = ctx.contentResolver

        cr.openInputStream(fileUri).use { input ->
            if (input == null) error("openInputStream returned null for $fileName")

            while (offset < totalBytes) {
                val remaining = (totalBytes - offset).toInt()
                val thisChunk = minOf(chunkSize, remaining)
                val start = offset
                val endInclusive = offset + thisChunk - 1

                val buf = ByteArray(thisChunk)
                var readTotal = 0
                while (readTotal < thisChunk) {
                    val r = input.read(buf, readTotal, thisChunk - readTotal)
                    if (r <= 0) break
                    readTotal += r
                }
                if (readTotal != thisChunk) error("Short read: expected=$thisChunk got=$readTotal")

                val body = object : RequestBody() {
                    override fun contentType() = "application/octet-stream".toMediaType()
                    override fun contentLength() = thisChunk.toLong()
                    override fun writeTo(sink: BufferedSink) { sink.write(buf) }
                }

                val req = Request.Builder()
                    .url(uploadUrl)
                    .put(body)
                    .header("Content-Length", thisChunk.toString())
                    .header("Content-Range", "bytes $start-$endInclusive/$totalBytes")
                    .build()

                // Per-chunk retry: up to 3 attempts for network errors only
                var chunkAttempt = 0
                var chunkDone = false
                var lastNetworkEx: Exception? = null

                while (chunkAttempt < 3 && !chunkDone) {
                    try {
                        Http.client.newCall(req).execute().use { res ->
                            when (res.code) {
                                202 -> { offset += thisChunk; chunkDone = true }
                                200, 201 -> return   // final chunk — upload complete
                                else -> {
                                    val s = res.body?.string().orEmpty()
                                    // Server errors are not retried at chunk level
                                    error("Chunk upload failed ${res.code}: $s")
                                }
                            }
                        }
                    } catch (e: java.net.SocketTimeoutException) {
                        lastNetworkEx = e
                        chunkAttempt++
                        if (chunkAttempt < 3) Thread.sleep(3000L * chunkAttempt)
                    } catch (e: java.io.IOException) {
                        lastNetworkEx = e
                        chunkAttempt++
                        if (chunkAttempt < 3) Thread.sleep(3000L * chunkAttempt)
                    }
                }

                if (!chunkDone) throw lastNetworkEx
                    ?: Exception("Chunk upload failed after 3 attempts at offset $offset")
            }

            error("Upload ended unexpectedly: offset=$offset totalBytes=$totalBytes")
        }
    }
}
