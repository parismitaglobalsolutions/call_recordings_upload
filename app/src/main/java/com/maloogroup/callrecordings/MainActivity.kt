package com.maloogroup.callrecordings

import android.Manifest
import com.maloogroup.callrecordings.BuildConfig
import android.app.ActivityManager
import android.app.AlarmManager
import android.content.Intent
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.util.Log
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.ui.graphics.Color
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import com.maloogroup.callrecordings.ui.theme.AppColors
import com.maloogroup.callrecordings.ui.theme.MalooTheme
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import okhttp3.Request
import org.json.JSONObject
import java.util.Calendar

data class Department(
    val id: Int,
    val name: String
)

private data class StatusItem(val label: String, val ok: Boolean, val value: String)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            val scope = rememberCoroutineScope()
            val store = remember { ConfigStore(this@MainActivity) }
            var uploaderId by remember { mutableStateOf("") }
            var pickedTreeUri by remember { mutableStateOf<Uri?>(null) }
            // Fallback alarm/catch-up time (20:15). The displayed/primary time is 20:00
            // (server push); these vals only drive the on-device fallback scheduling.
            val hour = AppConstants.FALLBACK_HOUR
            val minute = AppConstants.FALLBACK_MINUTE
            var status by remember { mutableStateOf("Ready") }
            var saving by remember { mutableStateOf(false) }
            var showDashboard by remember { mutableStateOf(false) }

            var departments by remember { mutableStateOf<List<Department>>(emptyList()) }
            var selectedDepartment by remember { mutableStateOf<Department?>(null) }
            var loadingDepartments by remember { mutableStateOf(false) }

            // ─── Folder Picker ─────────────────────────────────────────────────

            val folderPicker = rememberLauncherForActivityResult(
                contract = ActivityResultContracts.OpenDocumentTree()
            ) { uri: Uri? ->
                if (uri == null) return@rememberLauncherForActivityResult
                contentResolver.takePersistableUriPermission(
                    uri,
                    Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
                )
                pickedTreeUri = uri
                status = "Picked folder OK"
                Log.d("SETUP", "Picked treeUri=$uri")
            }

            // ─── Initial load ──────────────────────────────────────────────────

            LaunchedEffect(Unit) {
                val prefs = store.read()
                val recordingsFolderId = (prefs[Keys.recordingsFolderId] ?: "").trim()
                val tree = (prefs[Keys.treeUri] ?: "").trim()
                val drive = (prefs[Keys.driveId] ?: "").trim()
                showDashboard = recordingsFolderId.isNotEmpty() && tree.isNotEmpty() && drive.isNotEmpty()
                uploaderId = prefs[Keys.uploaderId] ?: ""

                // If already set up, keep the safety net alive and catch up on open:
                // if today's scheduled time has passed with no success yet, run now.
                if (showDashboard) {
                    WorkScheduler.scheduleNextRun(this@MainActivity, hour, minute)
                    WorkScheduler.ensurePeriodicWatchdog(this@MainActivity)
                    maybeCatchUpToday(prefs, hour, minute)
                }

                loadingDepartments = true
                try {
                    val fetchedDepartments = fetchDepartments()
                    departments = fetchedDepartments

                    val savedDeptId = prefs[Keys.departmentId] ?: 0
                    val savedDeptName = prefs[Keys.departmentName] ?: ""
                    if (savedDeptId > 0 && savedDeptName.isNotEmpty()) {
                        selectedDepartment = Department(savedDeptId, savedDeptName)
                    }
                } catch (e: Exception) {
                    Log.e("DEPARTMENTS", "Failed to fetch departments", e)
                    status = "Failed to load departments: ${e.message}"
                } finally {
                    loadingDepartments = false
                }

                scope.launch(Dispatchers.IO) {
                    try {
                        com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                            .addOnSuccessListener { freshToken ->
                                kotlinx.coroutines.CoroutineScope(Dispatchers.IO).launch {
                                    try {
                                        val latestPrefs = store.read()
                                        val storedToken = (latestPrefs[Keys.fcmToken] ?: "").trim()
                                        if (freshToken != storedToken) {
                                            store.saveFcmToken(freshToken)
                                            FcmTokenUploader(this@MainActivity).uploadToken(freshToken)
                                        }
                                    } catch (_: Exception) { }
                                }
                            }
                    } catch (_: Exception) { }
                }
            }

            // ─── UI ────────────────────────────────────────────────────────────

            MalooTheme {
                if (showDashboard) {
                    DashboardScreen(
                        uploaderId = uploaderId,
                        onReset = {
                            scope.launch {
                                store.clearAll()
                                WorkManager.getInstance(this@MainActivity)
                                    .cancelUniqueWork(AppConstants.UNIQUE_WORK_NAME)
                                WorkScheduler.cancelWatchdog(this@MainActivity)
                                showDashboard = false
                                uploaderId = ""
                                pickedTreeUri = null
                                selectedDepartment = null
                                status = "Ready"
                            }
                        }
                    )
                } else {
                    SetupScreen(
                        uploaderId = uploaderId,
                        onUploaderIdChange = { uploaderId = it },
                        folderDisplayName = pickedTreeUri?.lastPathSegment,
                        onPickFolder = { folderPicker.launch(null) },
                        status = status,
                        saving = saving,
                        departments = departments,
                        selectedDepartment = selectedDepartment,
                        onDepartmentSelected = { selectedDepartment = it },
                        loadingDepartments = loadingDepartments,
                        onSave = {
                            scope.launch {
                                if (saving) return@launch
                                saving = true
                                try {
                                    val id = uploaderId.trim()
                                    if (id.isEmpty()) {
                                        status = "Enter folder name first"
                                        return@launch
                                    }

                                    val uri = pickedTreeUri
                                    if (uri == null) {
                                        status = "Select recordings folder first"
                                        return@launch
                                    }

                                    val dept = selectedDepartment
                                    if (dept == null) {
                                        status = "Select department first"
                                        return@launch
                                    }

                                    status = "Saving config..."
                                    store.saveUploaderAndFolder(id, uri.toString(), dept.id, dept.name)
                                    store.saveSchedule(hour, minute)

                                    val triple = withContext(Dispatchers.IO) {
                                        val root = GraphShareResolver().resolveSharedFolder(AppConstants.SHARE_URL)
                                        val gf = GraphFolders()
                                        val uploaderFolderId = gf.ensureChildFolder(
                                            driveId = root.driveId,
                                            parentItemId = root.itemId,
                                            folderName = id
                                        )
                                        val recordingsFolderId = gf.ensureChildFolder(
                                            driveId = root.driveId,
                                            parentItemId = uploaderFolderId,
                                            folderName = "recordings"
                                        )
                                        Triple(root.driveId, root.itemId, recordingsFolderId)
                                    }

                                    store.saveGraph(
                                        driveId = triple.first,
                                        rootItemId = triple.second,
                                        recordingsFolderId = triple.third
                                    )

                                    status = "Setup complete"
                                    showDashboard = true

                                    // Just schedule — no immediate upload. The alarm + watchdog
                                    // run the first upload at the configured time.
                                    WorkScheduler.scheduleNextRun(this@MainActivity, hour, minute)
                                    WorkScheduler.ensurePeriodicWatchdog(this@MainActivity)

                                    withContext(Dispatchers.IO) {
                                        try {
                                            com.google.firebase.messaging.FirebaseMessaging.getInstance()
                                                .token
                                                .addOnSuccessListener { token ->
                                                    kotlinx.coroutines.CoroutineScope(Dispatchers.IO).launch {
                                                        try {
                                                            store.saveFcmToken(token)
                                                            FcmTokenUploader(this@MainActivity).uploadToken(token)
                                                            Log.d("FCM", "Token registered successfully")
                                                        } catch (e: Exception) {
                                                            Log.w("FCM", "Token registration failed: ${e.message}")
                                                        }
                                                    }
                                                }
                                        } catch (e: Exception) {
                                            Log.w("FCM", "Could not get FCM token: ${e.message}")
                                        }
                                    }

                                } catch (e: Exception) {
                                    Log.e("SETUP", "Setup failed", e)
                                    status = "Setup failed: ${e.message}"
                                } finally {
                                    saving = false
                                }
                            }
                        }
                    )
                }
            }
        }
    }

    private suspend fun fetchDepartments(): List<Department> = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url(AppConstants.DEPARTMENTS_API_ENDPOINT)
            .get()
            .build()

        Http.client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw Exception("Failed to fetch departments: ${response.code}")
            }

            val body = response.body?.string() ?: throw Exception("Empty response")
            val json = JSONObject(body)

            if (!json.getBoolean("success")) {
                throw Exception("API returned success=false")
            }

            val dataArray = json.getJSONArray("data")
            val departments = mutableListOf<Department>()

            for (i in 0 until dataArray.length()) {
                val deptJson = dataArray.getJSONObject(i)
                departments.add(
                    Department(
                        id = deptJson.getInt("id"),
                        name = deptJson.getString("department_name")
                    )
                )
            }

            departments
        }
    }

    // App-open catch-up: if today's scheduled time has passed and there has been no
    // successful upload today, enqueue one now (bounded window, KEEP so it won't
    // disturb an already-pending/running job).
    private fun maybeCatchUpToday(
        prefs: androidx.datastore.preferences.core.Preferences,
        hour: Int,
        minute: Int
    ) {
        try {
            val now = java.time.LocalDateTime.now()
            val scheduledToday = now.withHour(hour).withMinute(minute).withSecond(0).withNano(0)
            if (now.isBefore(scheduledToday)) return

            val lastSuccess = (prefs[Keys.lastSuccessAt] ?: "").trim()
            val lastSuccessDate = if (lastSuccess.isNotEmpty()) {
                try {
                    java.time.Instant.parse(lastSuccess)
                        .atZone(java.time.ZoneId.systemDefault()).toLocalDate()
                } catch (_: Exception) { null }
            } else null
            if (lastSuccessDate == now.toLocalDate()) return

            val req = OneTimeWorkRequestBuilder<UploaderWorker>()
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .addTag(AppConstants.WORK_TASK_UPLOAD)
                .build()
            WorkManager.getInstance(this@MainActivity)
                .enqueueUniqueWork(AppConstants.UNIQUE_WORK_NAME, ExistingWorkPolicy.KEEP, req)
        } catch (_: Exception) {
            // Best-effort catch-up — never block app open.
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun SetupScreen(
        uploaderId: String,
        onUploaderIdChange: (String) -> Unit,
        folderDisplayName: String?,
        onPickFolder: () -> Unit,
        status: String,
        saving: Boolean,
        departments: List<Department>,
        selectedDepartment: Department?,
        onDepartmentSelected: (Department) -> Unit,
        loadingDepartments: Boolean,
        onSave: () -> Unit
    ) {
        // ─── Permission launchers ──────────────────────────────────────────────

        var permRefreshKey by remember { mutableIntStateOf(0) }

        val callLogLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.RequestPermission()
        ) { permRefreshKey++ }

        val notifLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.RequestPermission()
        ) { permRefreshKey++ }

        val batteryLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { permRefreshKey++ }

        val alarmLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { permRefreshKey++ }

        val bgRestrictionLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { permRefreshKey++ }

        // Refresh permission states when user returns from Settings
        val lifecycleOwner = LocalLifecycleOwner.current
        DisposableEffect(lifecycleOwner) {
            val observer = LifecycleEventObserver { _, event ->
                if (event == Lifecycle.Event.ON_RESUME) permRefreshKey++
            }
            lifecycleOwner.lifecycle.addObserver(observer)
            onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
        }

        val hasCallLog = remember(permRefreshKey) {
            ContextCompat.checkSelfPermission(
                this@MainActivity, Manifest.permission.READ_CALL_LOG
            ) == PackageManager.PERMISSION_GRANTED
        }
        val hasNotification = remember(permRefreshKey) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.POST_NOTIFICATIONS
                ) == PackageManager.PERMISSION_GRANTED
            } else true
        }
        val isBatteryOptimized = remember(permRefreshKey) {
            val pm = getSystemService(PowerManager::class.java)
            !pm.isIgnoringBatteryOptimizations(packageName)
        }
        val canExactAlarm = remember(permRefreshKey) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                getSystemService(AlarmManager::class.java).canScheduleExactAlarms()
            } else true
        }
        val isBackgroundRestricted = remember(permRefreshKey) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                getSystemService(ActivityManager::class.java).isBackgroundRestricted
            } else false
        }

        var permError by remember { mutableStateOf("") }

        // ─── UI ───────────────────────────────────────────────────────────────

        Scaffold(
            containerColor = AppColors.ScaffoldBg,
            topBar = {
                TopAppBar(
                    title = { Text("Initial Setup") },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = AppColors.Primary,
                        titleContentColor = AppColors.OnPrimary
                    )
                )
            }
        ) { padding ->
            Column(
                modifier = Modifier
                    .padding(padding)
                    .padding(16.dp)
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Surface(color = AppColors.CardBg, shape = androidx.compose.foundation.shape.RoundedCornerShape(16.dp)) {
                    Column(modifier = Modifier.padding(14.dp)) {
                        Text("Maloo Group Recordings Uploader", fontWeight = FontWeight.W700, color = AppColors.TextPrimary)
                        Spacer(Modifier.height(6.dp))
                        Text("Configure OneDrive folder and daily upload.", color = AppColors.TextSecondary)
                    }
                }

                CardSection("OneDrive folder name") {
                    OutlinedTextField(
                        value = uploaderId,
                        onValueChange = onUploaderIdChange,
                        modifier = Modifier.fillMaxWidth(),
                        placeholder = { Text("Example: TestMK") }
                    )
                }

                CardSection("Department") {
                    if (loadingDepartments) {
                        Box(
                            modifier = Modifier.fillMaxWidth().height(56.dp),
                            contentAlignment = Alignment.Center
                        ) {
                            CircularProgressIndicator()
                        }
                    } else if (departments.isEmpty()) {
                        Text(
                            "Failed to load departments. Please check your internet connection.",
                            color = AppColors.TextSecondary
                        )
                    } else {
                        DepartmentDropdown(
                            departments = departments,
                            selectedDepartment = selectedDepartment,
                            onDepartmentSelected = onDepartmentSelected
                        )
                    }
                }

                CardSection("Recordings folder") {
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(folderDisplayName ?: "Not selected", fontWeight = FontWeight.W600, color = AppColors.TextPrimary)
                            Spacer(Modifier.height(2.dp))
                            Text("Select folder so app can read files (SAF).", color = AppColors.TextSecondary)
                        }
                        Spacer(Modifier.width(12.dp))
                        OutlinedButton(onClick = onPickFolder) { Text("Select") }
                    }
                }

                CardSection("Required Permissions") {
                    PermissionRow(
                        label = "Call Log",
                        description = "Read call records for upload metadata",
                        isGranted = hasCallLog,
                        buttonLabel = "Grant",
                        onGrant = { callLogLauncher.launch(Manifest.permission.READ_CALL_LOG) }.takeIf { !hasCallLog }
                    )

                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        PermissionRow(
                            label = "Notifications",
                            description = "Show background job status",
                            isGranted = hasNotification,
                            buttonLabel = "Grant",
                            onGrant = { notifLauncher.launch(Manifest.permission.POST_NOTIFICATIONS) }.takeIf { !hasNotification }
                        )
                    }

                    PermissionRow(
                        label = "Battery Optimization",
                        description = "Required for reliable background uploads",
                        isGranted = !isBatteryOptimized,
                        grantedLabel = "Unrestricted",
                        deniedLabel = "Restricted",
                        buttonLabel = "Fix",
                        onGrant = {
                            batteryLauncher.launch(
                                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                                    data = Uri.parse("package:$packageName")
                                }
                            )
                        }.takeIf { isBatteryOptimized }
                    )

                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        PermissionRow(
                            label = "Exact Alarm",
                            description = "Required for precise daily scheduling",
                            isGranted = canExactAlarm,
                            buttonLabel = "Allow",
                            onGrant = {
                                alarmLauncher.launch(
                                    Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM).apply {
                                        data = Uri.parse("package:$packageName")
                                    }
                                )
                            }.takeIf { !canExactAlarm }
                        )
                    }

                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P && isBackgroundRestricted) {
                        PermissionRow(
                            label = "Background Restriction",
                            description = "Tap Fix → Battery → Unrestricted to allow background uploads",
                            isGranted = false,
                            deniedLabel = "Restricted",
                            buttonLabel = "Fix",
                            onGrant = {
                                bgRestrictionLauncher.launch(
                                    Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                                        data = Uri.parse("package:$packageName")
                                    }
                                )
                            }
                        )
                    }
                }

                if (permError.isNotEmpty()) {
                    Text(permError, color = Color(0xFFC62828), fontSize = 13.sp)
                }

                Button(
                    onClick = {
                        val missing = buildList {
                            if (!hasCallLog) add("Call Log")
                            if (isBatteryOptimized) add("Battery Optimization")
                            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && !canExactAlarm) add("Exact Alarm")
                        }
                        if (missing.isNotEmpty()) {
                            permError = "Grant required permissions: ${missing.joinToString(", ")}"
                        } else {
                            permError = ""
                            onSave()
                        }
                    },
                    enabled = !saving && !loadingDepartments && departments.isNotEmpty(),
                    modifier = Modifier.fillMaxWidth().height(48.dp),
                    shape = androidx.compose.foundation.shape.RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = AppColors.Primary,
                        contentColor = AppColors.OnPrimary
                    )
                ) {
                    Text(if (saving) "Saving..." else "Save & Continue")
                }

                Text(status, color = AppColors.TextSecondary)
            }
        }
    }

    @Composable
    private fun PermissionRow(
        label: String,
        description: String,
        isGranted: Boolean,
        grantedLabel: String = "Granted",
        deniedLabel: String = "Not Granted",
        buttonLabel: String = "Grant",
        onGrant: (() -> Unit)?
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 8.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(label, fontWeight = FontWeight.W600, color = AppColors.TextPrimary)
                Text(description, color = AppColors.TextSecondary, fontSize = 12.sp)
            }
            Spacer(Modifier.width(8.dp))
            if (isGranted) {
                Text(grantedLabel, color = Color(0xFF2E7D32), fontWeight = FontWeight.W600, fontSize = 13.sp)
            } else {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(deniedLabel, color = Color(0xFFC62828), fontSize = 13.sp)
                    if (onGrant != null) {
                        Spacer(Modifier.width(8.dp))
                        OutlinedButton(
                            onClick = onGrant,
                            modifier = Modifier.height(32.dp),
                            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp)
                        ) {
                            Text(buttonLabel, fontSize = 12.sp)
                        }
                    }
                }
            }
        }
        HorizontalDivider()
    }

    @Composable
    private fun DepartmentDropdown(
        departments: List<Department>,
        selectedDepartment: Department?,
        onDepartmentSelected: (Department) -> Unit
    ) {
        var expanded by remember { mutableStateOf(false) }

        Box {
            OutlinedTextField(
                value = selectedDepartment?.name ?: "",
                onValueChange = {},
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { expanded = true },
                enabled = false,
                placeholder = { Text("Select Department") },
                readOnly = true
            )

            DropdownMenu(
                expanded = expanded,
                onDismissRequest = { expanded = false }
            ) {
                departments.forEach { dept ->
                    DropdownMenuItem(
                        text = { Text(dept.name) },
                        onClick = {
                            onDepartmentSelected(dept)
                            expanded = false
                        }
                    )
                }
            }
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun DashboardScreen(
        uploaderId: String,
        onReset: () -> Unit = {}
    ) {
        val store = remember { ConfigStore(this@MainActivity) }
        val scope = rememberCoroutineScope()
        var departmentName by remember { mutableStateOf("") }
        var showStatusSheet by remember { mutableStateOf(false) }
        var fcmToken by remember { mutableStateOf("") }
        var workManagerState by remember { mutableStateOf("") }
        var watchdogScheduled by remember { mutableStateOf(false) }
        var statusRefreshKey by remember { androidx.compose.runtime.mutableIntStateOf(0) }

        suspend fun loadAsyncStatus() {
            val prefs = store.read()
            departmentName = prefs[Keys.departmentName] ?: ""
            fcmToken = (prefs[Keys.fcmToken] ?: "").trim()
            withContext(Dispatchers.IO) {
                workManagerState = try {
                    val infos = WorkManager.getInstance(this@MainActivity)
                        .getWorkInfosForUniqueWork(AppConstants.UNIQUE_WORK_NAME)
                        .get()
                    if (infos.isNullOrEmpty()) "NOT_FOUND" else infos.first().state.name
                } catch (_: Exception) { "NOT_FOUND" }
                watchdogScheduled = try {
                    val infos = WorkManager.getInstance(this@MainActivity)
                        .getWorkInfosForUniqueWork(AppConstants.WATCHDOG_UNIQUE_NAME)
                        .get()
                    infos != null && infos.any {
                        it.state == androidx.work.WorkInfo.State.ENQUEUED ||
                            it.state == androidx.work.WorkInfo.State.RUNNING
                    }
                } catch (_: Exception) { false }
            }
        }

        LaunchedEffect(Unit) {
            loadAsyncStatus()
        }

        val statusItems = remember(fcmToken, workManagerState, watchdogScheduled, statusRefreshKey) {
            val hasCallLog = ContextCompat.checkSelfPermission(
                this@MainActivity, Manifest.permission.READ_CALL_LOG
            ) == PackageManager.PERMISSION_GRANTED

            val hasNotification = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.POST_NOTIFICATIONS
                ) == PackageManager.PERMISSION_GRANTED
            } else true

            val pm = getSystemService(PowerManager::class.java)
            val isBatteryOptimized = !pm.isIgnoringBatteryOptimizations(packageName)

            val am = getSystemService(ActivityManager::class.java)
            val isBackgroundRestricted = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P && am.isBackgroundRestricted

            val hasSAF = contentResolver.persistedUriPermissions.any { it.isReadPermission && it.isWritePermission }

            val canExactAlarm = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val am = getSystemService(AlarmManager::class.java)
                am.canScheduleExactAlarms()
            } else true

            val isAutoRevoke = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                try {
                    val appOps = getSystemService(android.app.AppOpsManager::class.java)
                    appOps.unsafeCheckOpNoThrow(
                        "android:auto_revoke_permissions_if_unused",
                        android.os.Process.myUid(), packageName
                    ) == android.app.AppOpsManager.MODE_ALLOWED
                } catch (_: Exception) { false }
            } else false

            val isHibernated = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                try {
                    val usm = getSystemService(android.app.usage.UsageStatsManager::class.java)
                    usm.appStandbyBucket >= 45
                } catch (_: Exception) { false }
            } else false

            val alarmSet = WorkScheduler.isAlarmScheduled(this@MainActivity)

            listOf(
                StatusItem("Call Log Permission",   hasCallLog,              if (hasCallLog) "Granted" else "Denied"),
                StatusItem("Notification",          hasNotification,         if (hasNotification) "Granted" else "Denied"),
                StatusItem("Battery Optimization",  !isBatteryOptimized,     if (!isBatteryOptimized) "Disabled" else "Active"),
                StatusItem("Background Restricted", !isBackgroundRestricted, if (!isBackgroundRestricted) "No" else "Yes"),
                StatusItem("Storage Access (SAF)",  hasSAF,                  if (hasSAF) "Granted" else "Not Set"),
                StatusItem("Exact Alarm",           canExactAlarm,           if (canExactAlarm) "Allowed" else "Denied"),
                StatusItem("Auto Revoke",           !isAutoRevoke,           if (!isAutoRevoke) "Off" else "On"),
                StatusItem("App Hibernated",        !isHibernated,           if (!isHibernated) "No" else "Yes"),
                StatusItem("FCM Token",             fcmToken.isNotEmpty(),   if (fcmToken.isNotEmpty()) "Set" else "Not Set"),
                StatusItem("Upload Job",            workManagerState in listOf("ENQUEUED", "RUNNING", "SUCCEEDED"), workManagerState.ifEmpty { "NOT_FOUND" }),
                StatusItem("Next Alarm",            alarmSet,                if (alarmSet) "Set" else "Not Set"),
                StatusItem("Watchdog",              watchdogScheduled,       if (watchdogScheduled) "Active" else "Off"),
            )
        }

        val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

        fun greet(): String {
            val h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY)
            return when {
                h < 12 -> "Good morning"
                h < 17 -> "Good afternoon"
                else -> "Good evening"
            }
        }

        if (showStatusSheet) {
            ModalBottomSheet(
                onDismissRequest = { showStatusSheet = false },
                sheetState = sheetState
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 20.dp)
                        .padding(bottom = 32.dp)
                ) {
                    Text(
                        "Device Status",
                        fontWeight = FontWeight.W700,
                        fontSize = 18.sp,
                        color = AppColors.TextPrimary,
                        modifier = Modifier.padding(bottom = 12.dp)
                    )
                    statusItems.forEach { item ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(vertical = 10.dp),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Text(item.label, color = AppColors.TextSecondary)
                            Text(
                                item.value,
                                color = if (item.ok) Color(0xFF2E7D32) else Color(0xFFC62828),
                                fontWeight = FontWeight.W600
                            )
                        }
                        HorizontalDivider()
                    }
                }
            }
        }

        Scaffold(
            containerColor = AppColors.ScaffoldBg,
            topBar = {
                TopAppBar(
                    title = { Text("Maloo Group") },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = AppColors.Primary,
                        titleContentColor = AppColors.OnPrimary
                    )
                )
            }
        ) { padding ->
            Column(
                modifier = Modifier
                    .padding(padding)
                    .padding(24.dp)
                    .fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(16.dp)
            ) {
                Surface(
                    color = AppColors.CardBg,
                    shape = androidx.compose.foundation.shape.RoundedCornerShape(16.dp)
                ) {
                    Column(modifier = Modifier.padding(24.dp)) {
                        Text(
                            greet(),
                            fontWeight = FontWeight.W800,
                            color = AppColors.TextPrimary,
                            fontSize = 28.sp
                        )
                        Spacer(Modifier.height(8.dp))
                        Text(
                            if (uploaderId.isBlank()) "Welcome" else "Welcome, $uploaderId",
                            color = AppColors.TextSecondary,
                            fontSize = 18.sp
                        )
                        if (departmentName.isNotEmpty()) {
                            Spacer(Modifier.height(4.dp))
                            Text(
                                departmentName,
                                color = AppColors.TextSecondary,
                                fontSize = 16.sp
                            )
                        }
                    }
                }

                Text(
                    "Your recordings will be automatically uploaded daily at the scheduled time.",
                    color = AppColors.TextSecondary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth()
                )

                if (AppConstants.SHOW_UPLOAD_NOW_BUTTON) {
                    OutlinedButton(
                        onClick = {
                            val cm = getSystemService(ConnectivityManager::class.java)
                            val caps = cm.activeNetwork?.let { cm.getNetworkCapabilities(it) }
                            val isConnected = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
                                && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
                            if (!isConnected) {
                                Toast.makeText(this@MainActivity, "No internet connection", Toast.LENGTH_SHORT).show()
                            } else {
                                val constraints = Constraints.Builder()
                                    .setRequiredNetworkType(NetworkType.CONNECTED)
                                    .build()
                                val req = OneTimeWorkRequestBuilder<UploaderWorker>()
                                    .setConstraints(constraints)
                                    .setInputData(workDataOf(AppConstants.KEY_UPLOAD_ALL to true))
                                    .addTag(AppConstants.WORK_TASK_UPLOAD)
                                    .build()
                                WorkManager.getInstance(this@MainActivity)
                                    .enqueueUniqueWork(AppConstants.UNIQUE_WORK_NAME, ExistingWorkPolicy.REPLACE, req)
                            }
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text("Upload Now")
                    }
                }

                if (AppConstants.SHOW_PERMISSION_STATUS) {
                    OutlinedButton(
                        onClick = {
                            statusRefreshKey++
                            scope.launch { loadAsyncStatus() }
                            showStatusSheet = true
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text("Device Status")
                    }
                }

                if (BuildConfig.DEBUG) {
                    Spacer(Modifier.height(8.dp))
                    OutlinedButton(
                        onClick = onReset,
                        modifier = Modifier.fillMaxWidth(),
                        colors = ButtonDefaults.outlinedButtonColors(
                            contentColor = Color.Red
                        )
                    ) {
                        Text("[DEBUG] Reset Setup")
                    }
                }
            }
        }
    }


    @Composable
    private fun CardSection(title: String, content: @Composable () -> Unit) {
        Surface(color = AppColors.CardBg, shape = androidx.compose.foundation.shape.RoundedCornerShape(16.dp)) {
            Column(modifier = Modifier.padding(14.dp)) {
                Text(title, fontWeight = FontWeight.W700, color = AppColors.TextPrimary)
                Spacer(Modifier.height(10.dp))
                content()
            }
        }
    }
}
