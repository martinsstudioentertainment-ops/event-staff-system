package com.olasentra.staff.feature.gps.ui

import android.app.Activity
import android.content.ContextWrapper
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.app.ActivityCompat
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.location.LocationPermissionState
import com.olasentra.staff.core.ui.components.LastSyncBanner
import com.olasentra.staff.domain.model.GpsStatusSummary
import com.olasentra.staff.domain.model.VenueDistanceInfo

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CheckInScreen(
    modifier: Modifier = Modifier,
    viewModel: CheckInViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val activity = context.findHostActivity()
    val lifecycleOwner = LocalLifecycleOwner.current

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions(),
    ) { results ->
        val granted = results.values.all { it }
        val shouldShowRationale = activity?.let {
            ActivityCompat.shouldShowRequestPermissionRationale(
                it,
                android.Manifest.permission.ACCESS_FINE_LOCATION,
            )
        } ?: false
        viewModel.onPermissionResult(granted = granted, shouldShowRationale = shouldShowRationale)
    }

    LaunchedEffect(Unit) {
        viewModel.onPermissionNotRequestedYet()
    }

    DisposableEffect(lifecycleOwner, activity) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                val shouldShowRationale = activity?.let {
                    ActivityCompat.shouldShowRequestPermissionRationale(
                        it,
                        android.Manifest.permission.ACCESS_FINE_LOCATION,
                    )
                } ?: false
                viewModel.syncPermissionFromSystem(shouldShowRationale)
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Text(
                    text = "Check-In",
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 16.dp),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                LastSyncBanner(
                    lastSyncedAtEpochMs = uiState.lastSyncedAtEpochMs,
                    isOfflineData = uiState.showOfflineBanner,
                )
            }
        },
    ) { innerPadding ->
        PullToRefreshBox(
            isRefreshing = uiState.isRefreshing,
            onRefresh = viewModel::refresh,
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding),
        ) {
            when {
                uiState.isInitialLoading -> LoadingState()
                uiState.status == null && uiState.errorMessage != null -> ErrorState(uiState.errorMessage.orEmpty())
                else -> {
                    CheckInContent(
                        uiState = uiState,
                        onRequestPermission = {
                            permissionLauncher.launch(
                                arrayOf(
                                    android.Manifest.permission.ACCESS_FINE_LOCATION,
                                    android.Manifest.permission.ACCESS_COARSE_LOCATION,
                                ),
                            )
                        },
                        onOpenSettings = {
                            val intent = Intent(
                                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                Uri.fromParts("package", context.packageName, null),
                            )
                            context.startActivity(intent)
                        },
                        onCheckIn = viewModel::checkIn,
                        onCheckOut = viewModel::checkOut,
                        onRefreshLocation = viewModel::refreshLocation,
                    )
                }
            }
        }
    }
}

@Composable
private fun CheckInContent(
    uiState: CheckInUiState,
    onRequestPermission: () -> Unit,
    onOpenSettings: () -> Unit,
    onCheckIn: () -> Unit,
    onCheckOut: () -> Unit,
    onRefreshLocation: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        if (uiState.locationPermission != LocationPermissionState.GRANTED) {
            LocationPermissionRationale(
                onRequestPermission = onRequestPermission,
                onOpenSettings = onOpenSettings,
                permanentlyDenied = uiState.locationPermission == LocationPermissionState.PERMANENTLY_DENIED,
            )
        }

        uiState.status?.let { status ->
            ShiftDetailsCard(status = status)
        }

        LocationCard(
            latitude = uiState.currentLocation?.latitude,
            longitude = uiState.currentLocation?.longitude,
            accuracyM = uiState.currentLocation?.accuracyMeters,
            isLoading = uiState.isLocationLoading,
            error = uiState.locationError,
            onRefreshLocation = onRefreshLocation,
        )

        VenueDistanceCard(distance = uiState.venueDistance ?: uiState.status?.venueDistance)

        StatusCard(
            title = "Check-in eligibility",
            primary = if (uiState.status?.checkInAllowed == true) "Eligible" else "Not eligible",
            secondary = uiState.status?.checkInReason,
        )

        if (uiState.status?.isCheckedIn == true) {
            StatusCard(
                title = "Current attendance",
                primary = uiState.status.attendanceState,
                secondary = buildString {
                    uiState.status.checkedInAt?.let { append("Checked in: $it") }
                    uiState.status.hoursWorked?.let {
                        if (isNotEmpty()) append(" · ")
                        append("Hours worked: ${"%.2f".format(it)}")
                    }
                }.ifBlank { null },
            )
            StatusCard(
                title = "Check-out eligibility",
                primary = if (uiState.status.checkOutAllowed) "Eligible" else "Not eligible",
                secondary = uiState.status.checkOutReason,
            )
        }

        if (uiState.pendingOfflineCount > 0) {
            Text(
                text = "${uiState.pendingOfflineCount} action(s) queued for sync",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.secondary,
            )
        }

        uiState.actionMessage?.let {
            Text(text = it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.primary)
        }
        uiState.actionError?.let {
            Text(text = it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error)
        }

        if (uiState.status?.isCheckedIn != true) {
            Button(
                onClick = onCheckIn,
                enabled = uiState.checkInEnabled && !uiState.isCheckingIn,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (uiState.isCheckingIn) {
                    CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.height(20.dp))
                } else {
                    Text(text = "Check in")
                }
            }
        } else {
            OutlinedButton(
                onClick = onCheckOut,
                enabled = uiState.checkOutEnabled && !uiState.isCheckingOut,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (uiState.isCheckingOut) {
                    CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.height(20.dp))
                } else {
                    Text(text = "Check out")
                }
            }
        }
    }
}

@Composable
private fun ShiftDetailsCard(status: GpsStatusSummary, modifier: Modifier = Modifier) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(
                text = status.eventName ?: "Shift",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            status.eventDate?.let { Text(text = it, style = MaterialTheme.typography.bodyMedium) }
            status.venueName?.let {
                Text(text = it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Text(
                text = listOfNotNull(status.shiftStartTime, status.shiftEndTime).joinToString(" – ").ifBlank { null }
                    ?: "—",
                style = MaterialTheme.typography.bodyMedium,
            )
            status.shiftStatusLabel?.let {
                Text(text = it, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.secondary)
            }
            status.assignedCompany?.let {
                Text(text = it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun LocationCard(
    latitude: Double?,
    longitude: Double?,
    accuracyM: Float?,
    isLoading: Boolean,
    error: String?,
    onRefreshLocation: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(text = "Current GPS", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            when {
                isLoading -> CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.height(20.dp))
                latitude != null && longitude != null -> {
                    Text(text = "Lat ${"%.5f".format(latitude)}, Lng ${"%.5f".format(longitude)}", style = MaterialTheme.typography.bodyLarge)
                    accuracyM?.let {
                        Text(text = "Accuracy ${it.toInt()}m", style = MaterialTheme.typography.bodyMedium)
                    }
                }
                else -> Text(text = error ?: "Location not available", style = MaterialTheme.typography.bodyMedium)
            }
            OutlinedButton(onClick = onRefreshLocation, modifier = Modifier.fillMaxWidth()) {
                Text(text = "Refresh location")
            }
        }
    }
}

@Composable
private fun VenueDistanceCard(distance: VenueDistanceInfo?, modifier: Modifier = Modifier) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(text = "Distance from venue", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(modifier = Modifier.height(4.dp))
            if (distance == null) {
                Text(text = "—", style = MaterialTheme.typography.bodyLarge)
            } else {
                Text(
                    text = distance.distanceM?.let { "${it}m from venue" } ?: "—",
                    style = MaterialTheme.typography.bodyLarge,
                )
                distance.radiusM?.let {
                    Text(text = "Allowed radius: ${it}m", style = MaterialTheme.typography.bodySmall)
                }
                Text(
                    text = when (distance.inZone) {
                        true -> "Inside venue zone"
                        false -> "Outside venue zone"
                        null -> "Zone unknown"
                    },
                    style = MaterialTheme.typography.labelMedium,
                    color = if (distance.inZone == true) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                )
            }
        }
    }
}

@Composable
private fun StatusCard(title: String, primary: String, secondary: String?, modifier: Modifier = Modifier) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(text = title, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(modifier = Modifier.height(4.dp))
            Text(text = primary, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Medium)
            if (!secondary.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(text = secondary, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun LoadingState(modifier: Modifier = Modifier) {
    Column(modifier = modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        CircularProgressIndicator()
    }
}

@Composable
private fun ErrorState(message: String, modifier: Modifier = Modifier) {
    Column(modifier = modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
        Text(text = message, color = MaterialTheme.colorScheme.error)
    }
}

private fun android.content.Context.findHostActivity(): Activity? {
    var currentContext = this
    while (currentContext is ContextWrapper) {
        if (currentContext is Activity) {
            return currentContext
        }
        currentContext = currentContext.baseContext
    }
    return null
}
