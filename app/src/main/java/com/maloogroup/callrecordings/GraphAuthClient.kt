package com.maloogroup.callrecordings

import okhttp3.FormBody
import okhttp3.Request
import org.json.JSONObject

class GraphAuthClient {

    companion object {
        @Volatile private var cachedToken: String? = null
        @Volatile private var cachedTokenExpiryMs: Long = 0L
        private const val SKEW_MS = 60_000L // refresh 60s before expiry

        @Synchronized
        fun clearToken() {
            cachedToken = null
            cachedTokenExpiryMs = 0L
        }

        @Synchronized
        fun getAppToken(): String {
            val now = System.currentTimeMillis()
            val token = cachedToken
            if (!token.isNullOrBlank() && now < cachedTokenExpiryMs - SKEW_MS) {
                return token
            }

            val url = "https://login.microsoftonline.com/${Secrets.TENANT_ID}/oauth2/v2.0/token"
            val body = FormBody.Builder()
                .add("client_id", Secrets.CLIENT_ID)
                .add("client_secret", Secrets.CLIENT_SECRET)
                .add("grant_type", "client_credentials")
                .add("scope", "https://graph.microsoft.com/.default")
                .build()

            val req = Request.Builder().url(url).post(body).build()

            Http.client.newCall(req).execute().use { res ->
                val s = res.body?.string().orEmpty()
                if (!res.isSuccessful) error("Token failed ${res.code}: $s")

                val json = JSONObject(s)
                val accessToken = json.getString("access_token")
                val expiresInSec = json.optLong("expires_in", 3599)

                cachedToken = accessToken
                cachedTokenExpiryMs = now + (expiresInSec * 1000L)

                return accessToken
            }
        }
    }
}
