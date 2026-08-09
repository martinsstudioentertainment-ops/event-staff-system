package com.olasentra.staff.feature.shifts.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.LastSyncBanner
import com.olasentra.staff.domain.model.ShiftDetail

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ShiftDetailScreen(
    registrationId: Long,
    modifier: Modifier = Modifier,
    viewModel: ShiftDetailViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    LaunchedEffect(registrationId) {
        viewModel.load(registrationId)
    }

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Text(
                    text = "Shift details",
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

                uiState.detail == null && uiState.errorMessage != null -> {
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
                    uiState.detail?.let { detail ->
                        ShiftDetailContent(detail = detail)
                    }
                }
            }
        }
    }
}

@Composable
private fun ShiftDetailContent(
    detail: ShiftDetail,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        DetailField("Event", detail.eventName)
        DetailField("Venue", detail.venueName)
        DetailField("Date", detail.eventDate)
        DetailField("Start time", detail.startTime)
        DetailField("End time", detail.endTime)
        DetailField("Status", detail.statusLabel)
        DetailField("Assigned company", detail.assignedCompany)
        Spacer(modifier = Modifier.height(8.dp))
        DetailField(
            "Check-in eligibility",
            if (detail.checkInAllowed) "Eligible" else detail.checkInReason ?: "Not eligible",
        )
        DetailField(
            "Check-out eligibility",
            if (detail.checkOutAllowed) "Eligible" else detail.checkOutReason ?: "Not eligible",
        )
        if (detail.isCheckedIn) {
            DetailField("Checked in at", detail.checkedInAt ?: "—")
        }
        detail.attendanceStatus?.let {
            DetailField("Attendance", it)
        }
    }
}

@Composable
private fun DetailField(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier.fillMaxWidth()) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(modifier = Modifier.height(4.dp))
        Text(text = value, style = MaterialTheme.typography.bodyLarge)
    }
}
