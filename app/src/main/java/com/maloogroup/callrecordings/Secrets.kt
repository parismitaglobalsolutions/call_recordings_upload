package com.maloogroup.callrecordings

object Secrets {
    val TENANT_ID: String get() = BuildConfig.AZURE_TENANT_ID
    val CLIENT_ID: String get() = BuildConfig.AZURE_CLIENT_ID
    val CLIENT_SECRET: String get() = BuildConfig.AZURE_CLIENT_SECRET
}
