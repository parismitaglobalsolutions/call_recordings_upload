package com.maloogroup.callrecordings

import android.content.Context
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first

private val Context.ds by preferencesDataStore("config")

object Keys {
    val uploaderId = stringPreferencesKey("uploaderId")
    val departmentId = intPreferencesKey("departmentId")
    val departmentName = stringPreferencesKey("departmentName")

    val treeUri = stringPreferencesKey("treeUri")

    val driveId = stringPreferencesKey("driveId")
    val rootItemId = stringPreferencesKey("rootItemId")
    val recordingsFolderId = stringPreferencesKey("recordingsFolderId")

    val hour = intPreferencesKey("hour")
    val minute = intPreferencesKey("minute")

    // Flutter-like ledger keys
    val lastRunAt = stringPreferencesKey("lastRunAt")
    val lastSuccessAt = stringPreferencesKey("lastSuccessAt")
    val lastError = stringPreferencesKey("lastError")
    val lastUploadedFileName = stringPreferencesKey("lastUploadedFileName")

    val lastRunTotalAudioCount = intPreferencesKey("lastRunTotalAudioCount")
    val lastRunUploadedCount = intPreferencesKey("lastRunUploadedCount")
    
    // Track last processed timestamp to avoid duplicates (WorkManager timing is not rigid)
    val lastProcessedTimestamp = stringPreferencesKey("lastProcessedTimestamp")

    val fcmToken = stringPreferencesKey("fcmToken")
    // ✅ ADD THIS — stores failure log as JSON string array
    val failureLog = stringPreferencesKey("failureLog")

    // Comma-separated call_ids already POSTed to the backend (most-recent last, bounded).
    // Lets us report every call exactly once and never re-post / clobber it.
    val reportedCallIds = stringPreferencesKey("reportedCallIds")
}

class ConfigStore(private val ctx: Context) {

    suspend fun saveUploaderAndFolder(uploaderId: String, treeUri: String, departmentId: Int, departmentName: String) {
        ctx.ds.edit {
            it[Keys.uploaderId] = uploaderId.trim()
            it[Keys.treeUri] = treeUri.trim()
            it[Keys.departmentId] = departmentId
            it[Keys.departmentName] = departmentName.trim()

        }
    }

    suspend fun saveSchedule(hour: Int, minute: Int) {
        ctx.ds.edit {
            it[Keys.hour] = hour
            it[Keys.minute] = minute
        }
    }

    suspend fun saveGraph(driveId: String, rootItemId: String, recordingsFolderId: String) {
        ctx.ds.edit {
            it[Keys.driveId] = driveId
            it[Keys.rootItemId] = rootItemId
            it[Keys.recordingsFolderId] = recordingsFolderId
        }
    }

    suspend fun updateLedger(
        lastRunAt: String? = null,
        lastSuccessAt: String? = null,
        lastError: String? = null,
        lastUploadedFileName: String? = null,
        lastRunTotalAudioCount: Int? = null,
        lastRunUploadedCount: Int? = null,
        lastProcessedTimestamp: String? = null
    ) {
        ctx.ds.edit {
            if (lastRunAt != null) it[Keys.lastRunAt] = lastRunAt
            if (lastSuccessAt != null) it[Keys.lastSuccessAt] = lastSuccessAt
            if (lastError != null) it[Keys.lastError] = lastError
            if (lastUploadedFileName != null) it[Keys.lastUploadedFileName] = lastUploadedFileName
            if (lastRunTotalAudioCount != null) it[Keys.lastRunTotalAudioCount] = lastRunTotalAudioCount
            if (lastRunUploadedCount != null) it[Keys.lastRunUploadedCount] = lastRunUploadedCount
            if (lastProcessedTimestamp != null) it[Keys.lastProcessedTimestamp] = lastProcessedTimestamp
        }
    }

    suspend fun read(): Preferences = ctx.ds.data.first()

    suspend fun clearAll() {
        ctx.ds.edit { it.clear() }
    }

    suspend fun saveFcmToken(token: String) {
        ctx.ds.edit {
            it[Keys.fcmToken] = token
        }
    }

    suspend fun updateFailureLog(json: String) {
        ctx.ds.edit {
            it[Keys.failureLog] = json
        }
    }

    suspend fun saveReportedCallIds(csv: String) {
        ctx.ds.edit {
            it[Keys.reportedCallIds] = csv
        }
    }
}
