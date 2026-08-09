package com.olasentra.staff.feature.gps.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.LastSyncBanner
import com.olasentra.staff.domain.model.GpsStatusSummary

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun GpsStatusScreen(
    modifier: Modifier = Modifier,
    viewModel: GpsStatusViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

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
                uiState.isInitialLoading -> {
                    Column(
                        modifier = Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) {
                        CircularProgressIndicator()
                    }
                }

                uiState.status == null && uiState.errorMessage != null -> {
                    Column(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(24.dp),
                        verticalArrangement = Arrangement.Center,
                    ) {
                        Text(
                            text = uiState.errorMessage.orEmpty(),
                            color = MaterialTheme.colorScheme.error,
                        )
                    }
                }

                else -> {
                    uiState.status?.let { status ->
                        GpsStatusContent(status = status)
                    } ?: Column(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(24.dp),
                        verticalArrangement = Arrangement.Center,
                    ) {
                        Text(
                            text = "No GPS status available",
                            style = MaterialTheme.typography.bodyLarge,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun GpsStatusContent(
    status: GpsStatusSummary,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        status.eventName?.let { eventName ->
            Text(
                text = eventName,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.SemiBold,
            )
        }

        StatusCard(
            title = "GPS status",
            primary = buildGpsStatusLabel(status),
            secondary = if (status.gpsEnabled) {
                "GPS attendance enabled"
            } else {
                "GPS attendance disabled"
            },
        )

        StatusCard(
            title = "Current attendance",
            primary = status.attendanceState,
            secondary = listOfNotNull(
                status.checkedInAt?.let { "Checked in: $it" },
                status.checkedOutAt?.let { "Checked out: $it" },
            ).joinToString(" · ").ifBlank { null },
        )

        StatusCard(
            title = "Check-in eligibility",
            primary = if (status.checkInAllowed) "Eligible" else "Not eligible",
            secondary = status.checkInReason,
        )

        StatusCard(
            title = "Check-out eligibility",
            primary = if (status.checkOutAllowed) "Eligible" else "Not eligible",
            secondary = status.checkOutReason,
        )

        status.maxAccuracyM?.let { accuracy ->
            Text(
                text = "Required GPS accuracy: within ${accuracy}m",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(modifier = Modifier.height(8.dp))
        Text(
            text = "Live check-in and check-out actions arrive in Phase 2D.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun StatusCard(
    title: String,
    primary: String,
    secondary: String?,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = title,
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = primary,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Medium,
            )
            if (!secondary.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(
                    text = secondary,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

private fun buildGpsStatusLabel(status: GpsStatusSummary): String {
    return when {
        status.liveTracking && status.monitoringActive -> "Live monitoring active"
        status.monitoringActive -> "Monitoring active"
        status.liveTracking -> "Live tracking"
        else -> "Inactive"
    }
}
