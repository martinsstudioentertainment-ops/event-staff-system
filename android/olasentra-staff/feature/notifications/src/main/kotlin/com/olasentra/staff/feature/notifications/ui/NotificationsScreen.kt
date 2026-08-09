package com.olasentra.staff.feature.notifications.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import com.olasentra.staff.domain.model.NotificationCategory
import com.olasentra.staff.domain.model.StaffNotification

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun NotificationsScreen(
    onOpenRoute: (String) -> Unit = {},
    onOpenShiftDetail: (Long) -> Unit = {},
    modifier: Modifier = Modifier,
    viewModel: NotificationsViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val notifications = viewModel.filteredNotifications()

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 16.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = "Notifications",
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.SemiBold,
                    )
                    TextButton(
                        onClick = viewModel::markAllRead,
                        enabled = !uiState.isUpdating && (uiState.overview?.unreadCount ?: 0) > 0,
                    ) {
                        Text(text = "Mark all read")
                    }
                }
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
                uiState.overview == null && uiState.errorMessage != null -> ErrorState(uiState.errorMessage.orEmpty())
                else -> {
                    val overview = uiState.overview
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        item {
                            Text(
                                text = "${overview?.unreadCount ?: 0} unread",
                                style = MaterialTheme.typography.titleMedium,
                            )
                        }

                        val categories = overview?.categories.orEmpty()
                        if (categories.isNotEmpty()) {
                            item {
                                Text(
                                    text = "Categories",
                                    style = MaterialTheme.typography.labelLarge,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                Spacer(modifier = Modifier.height(8.dp))
                                FlowRow(
                                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                                    verticalArrangement = Arrangement.spacedBy(8.dp),
                                ) {
                                    FilterChip(
                                        selected = uiState.selectedCategory == null,
                                        onClick = { viewModel.selectCategory(null) },
                                        label = { Text("All") },
                                    )
                                    categories.forEach { category ->
                                        CategoryChip(
                                            category = category,
                                            selected = uiState.selectedCategory == category.category,
                                            onClick = { viewModel.selectCategory(category.category) },
                                        )
                                    }
                                }
                            }
                        }

                        if (notifications.isEmpty()) {
                            item {
                                Text(
                                    text = "No notifications",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        } else {
                            items(notifications, key = { it.id }) { notification ->
                                NotificationCard(
                                    notification = notification,
                                    onOpen = {
                                        viewModel.markRead(notification)
                                        handleNotificationAction(
                                            notification = notification,
                                            onOpenRoute = onOpenRoute,
                                            onOpenShiftDetail = onOpenShiftDetail,
                                        )
                                    },
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun handleNotificationAction(
    notification: StaffNotification,
    onOpenRoute: (String) -> Unit,
    onOpenShiftDetail: (Long) -> Unit,
) {
    notification.relatedId?.let { relatedId ->
        if (notification.category.startsWith("shift") || notification.type.contains("shift", ignoreCase = true)) {
            onOpenShiftDetail(relatedId)
            return
        }
    }

    val actionUrl = notification.actionUrl?.lowercase().orEmpty()
    val relatedId = notification.relatedId
    val actionLabel = notification.actionLabel
    when {
        actionUrl.contains("shift") && relatedId != null -> onOpenShiftDetail(relatedId)
        actionUrl.contains("shift") -> onOpenRoute("shifts")
        actionUrl.contains("message") || actionUrl.contains("inbox") -> onOpenRoute("messages")
        actionUrl.contains("document") || actionUrl.contains("psa") -> onOpenRoute("documents")
        actionUrl.contains("availability") || actionUrl.contains("leave") -> onOpenRoute("availability")
        actionUrl.contains("check") || actionUrl.contains("attendance") -> onOpenRoute("check_in")
        notification.category == "message_received" -> onOpenRoute("messages")
        notification.category == "document_expiry" -> onOpenRoute("documents")
        notification.category == "check_in_reminder" -> onOpenRoute("check_in")
        notification.category.startsWith("shift") && relatedId != null -> onOpenShiftDetail(relatedId)
        notification.category.startsWith("shift") -> onOpenRoute("shifts")
        else -> Unit
    }
}

@Composable
private fun CategoryChip(
    category: NotificationCategory,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    FilterChip(
        selected = selected,
        onClick = onClick,
        modifier = modifier,
        label = { Text(category.label) },
    )
}

@Composable
private fun NotificationCard(
    notification: StaffNotification,
    onOpen: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val actionLabel = notification.actionLabel
    Card(
        modifier = modifier
            .fillMaxWidth()
            .clickable(onClick = onOpen),
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(
                text = notification.categoryLabel,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.secondary,
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = notification.title,
                style = MaterialTheme.typography.titleMedium,
                fontWeight = if (notification.isRead) FontWeight.Normal else FontWeight.SemiBold,
            )
            if (notification.body.isNotBlank()) {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = notification.body,
                    style = MaterialTheme.typography.bodyMedium,
                    maxLines = 4,
                )
            }
            Spacer(modifier = Modifier.height(8.dp))
            Text(
                text = "${if (notification.isRead) "Read" else "Unread"} · ${notification.createdAt}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (!actionLabel.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(8.dp))
                OutlinedButton(onClick = onOpen, modifier = Modifier.fillMaxWidth()) {
                    Text(text = actionLabel)
                }
            }
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
