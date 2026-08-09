package com.olasentra.staff.feature.shifts.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
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
import com.olasentra.staff.core.ui.components.PortalPageHeader
import com.olasentra.staff.core.ui.theme.OlasentraColors
import com.olasentra.staff.domain.model.AvailableEvent

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AvailableEventsScreen(
    modifier: Modifier = Modifier,
    viewModel: AvailableEventsViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                PortalPageHeader(
                    title = "Available Events",
                    subtitle = "Apply for open event shifts",
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
                        CircularProgressIndicator(color = OlasentraColors.PrimaryOrange)
                    }
                }

                uiState.errorMessage != null && uiState.events.isEmpty() -> {
                    Text(
                        text = uiState.errorMessage.orEmpty(),
                        modifier = Modifier.padding(16.dp),
                        color = OlasentraColors.Danger,
                    )
                }

                uiState.events.isEmpty() -> {
                    Text(
                        text = "No events are open for registration right now.",
                        modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                else -> {
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        uiState.actionMessage?.let { message ->
                            item {
                                Text(
                                    text = message,
                                    color = OlasentraColors.Success,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = FontWeight.SemiBold,
                                )
                            }
                        }

                        items(uiState.events, key = { it.eventId }) { event ->
                            AvailableEventCard(
                                event = event,
                                isApplying = uiState.applyingEventId == event.eventId,
                                onApply = { viewModel.applyForEvent(event.eventId) },
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun AvailableEventCard(
    event: AvailableEvent,
    isApplying: Boolean,
    onApply: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(
                text = event.eventName,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Text(
                text = buildString {
                    append(event.eventDate)
                    if (event.timeLabel.isNotBlank() && event.timeLabel != "—") {
                        append(" · ")
                        append(event.timeLabel)
                    }
                },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = listOfNotNull(
                    event.venueName.takeIf { it.isNotBlank() && it != "—" },
                    event.employer.takeIf { it.isNotBlank() && it != "—" },
                ).joinToString(" · "),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = event.approvalStatus,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            if (event.canApply) {
                Spacer(modifier = Modifier.height(4.dp))
                Button(
                    onClick = onApply,
                    enabled = !isApplying,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (isApplying) "Applying…" else "Apply")
                }
            }
        }
    }
}
