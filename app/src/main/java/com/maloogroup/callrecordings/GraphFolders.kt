package com.maloogroup.callrecordings

import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

class GraphFolders {

    private val jsonMedia = "application/json".toMediaType()

    private fun listChildrenJson(driveId: String, parentItemId: String): JSONObject {
        val url = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$parentItemId/children"

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

        if (code !in listOf(200, 201)) error("List children failed $code: $body")
        return JSONObject(body)
    }

    private fun findChildFolderId(driveId: String, parentItemId: String, name: String): String? {
        val json = listChildrenJson(driveId, parentItemId)
        val arr = json.getJSONArray("value")
        for (i in 0 until arr.length()) {
            val item = arr.getJSONObject(i)
            val itemName = item.optString("name")
            val isFolder = item.has("folder")
            if (isFolder && itemName.equals(name, ignoreCase = true)) {
                return item.getString("id")
            }
        }
        return null
    }

    fun ensureChildFolder(driveId: String, parentItemId: String, folderName: String): String {
        // 1) Already exists?
        val existing = findChildFolderId(driveId, parentItemId, folderName)
        if (existing != null) return existing

        // 2) Create (conflictBehavior=fail so we don't overwrite)
        val url = "https://graph.microsoft.com/v1.0/drives/$driveId/items/$parentItemId/children"
        val bodyJson = JSONObject()
            .put("name", folderName)
            .put("folder", JSONObject())
            .put("@microsoft.graph.conflictBehavior", "fail")
            .toString()

        fun callOnce(token: String): Pair<Int, String> {
            val req = Request.Builder()
                .url(url)
                .header("Authorization", "Bearer $token")
                .header("Content-Type", "application/json")
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

        // If another request created it first, Graph returns 409
        if (code == 409) {
            val again = findChildFolderId(driveId, parentItemId, folderName)
            if (again != null) return again
            error("Folder exists (409) but could not be found after retry.")
        }

        if (code !in listOf(200, 201)) error("Create folder failed $code: $body")
        return JSONObject(body).getString("id")
    }
}
