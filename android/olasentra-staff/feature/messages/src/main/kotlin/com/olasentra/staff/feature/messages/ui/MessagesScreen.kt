package com.olasentra.staff.feature.messages.ui

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
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
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
import com.olasentra.staff.domain.model.StaffMessage

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MessagesScreen(
    modifier: Modifier = Modifier,
    viewModel: MessagesViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Text(
                    text = "Messages",
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 16.dp),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                LastSyncBanner(
                    lastSyncedAtEpochMs = uiState.lastSyncedAtEpochMs,
                    isOfflineData = uiState.showOfflineBanner,
                )
                TabRow(selectedTabIndex = uiState.activeTab.ordinal) {
                    Tab(
                        selected = uiState.activeTab == MessagesTab.INBOX,
                        onClick = { viewModel.onTabSelected(MessagesTab.INBOX) },
                        text = {
                            Text("Inbox (${uiState.overview?.unreadCount ?: 0} unread)")
                        },
                    )
                    Tab(
                        selected = uiState.activeTab == MessagesTab.SENT,
                        onClick = { viewModel.onTabSelected(MessagesTab.SENT) },
                        text = { Text("Sent") },
                    )
                }
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
                uiState.overview == null && uiState.errorMessage != null -> ErrorState(uiState.errorMessage.orEmpty())
                else -> {
                    val messages = when (uiState.activeTab) {
                        MessagesTab.INBOX -> uiState.overview?.inbox.orEmpty()
                        MessagesTab.SENT -> uiState.overview?.sent.orEmpty()
                    }
                    if (messages.isEmpty()) {
                        Column(
                            modifier = Modifier
                                .fillMaxSize()
                                .padding(24.dp),
                            verticalArrangement = Arrangement.Center,
                        ) {
                            Text(
                                text = if (uiState.activeTab == MessagesTab.INBOX) {
                                    "No inbox messages"
                                } else {
                                    "No sent messages"
                                },
                                style = MaterialTheme.typography.bodyLarge,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    } else {
                        LazyColumn(
                            modifier = Modifier.fillMaxSize(),
                            contentPadding = PaddingValues(16.dp),
                            verticalArrangement = Arrangement.spacedBy(12.dp),
                        ) {
                            items(messages, key = { it.id }) { message ->
                                MessageCard(message = message)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun MessageCard(
    message: StaffMessage,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = message.subject,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = if (message.isRead) FontWeight.Normal else FontWeight.SemiBold,
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = message.senderLabel,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (message.body.isNotBlank()) {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = message.body,
                    style = MaterialTheme.typography.bodyMedium,
                    maxLines = 4,
                )
            }
            Spacer(modifier = Modifier.height(8.dp))
            Text(
                text = "${if (message.isRead) "Read" else "Unread"} · ${message.createdAt}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.secondary,
            )
        }
    }
}

@Composable
private fun LoadingState(modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.fillMaxSize(),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        CircularProgressIndicator()
    }
}

@Composable
private fun ErrorState(message: String, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
    ) {
        Text(text = message, color = MaterialTheme.colorScheme.error)
    }
}
