package com.maloogroup.callrecordings

import android.util.Base64
import okhttp3.Request
import org.json.JSONObject

data class SharedRoot(val driveId: String, val itemId: String)

class GraphShareResolver {

    fun resolveSharedFolder(shareUrl: String): SharedRoot {

        // shareId = "u!" + base64url(shareUrl) (no '=' padding)
        val b64 = Base64.encodeToString(shareUrl.toByteArray(Charsets.UTF_8), Base64.NO_WRAP)
            .replace("+", "-")
            .replace("/", "_")
            .trimEnd('=')

        val shareId = "u!$b64"
        val url = "https://graph.microsoft.com/v1.0/shares/$shareId/driveItem"

        fun callOnce(token: String): Pair<Int, String> {
            val req = Request.Builder()
                .url(url)
                .header("Authorization", "Bearer $token")
                .get()
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

        if (code !in listOf(200, 201)) error("Resolve share failed $code: $body")

        val json = JSONObject(body)
        val parentRef = json.getJSONObject("parentReference")

        return SharedRoot(
            driveId = parentRef.getString("driveId"),
            itemId = json.getString("id")
        )
    }
}
