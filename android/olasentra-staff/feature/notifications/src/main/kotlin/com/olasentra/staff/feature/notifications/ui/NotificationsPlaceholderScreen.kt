package com.olasentra.staff.feature.notifications.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun NotificationsPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Notifications",
        subtitle = "Push and in-app notifications from the Mobile API arrive in Phase 2B.",
        icon = Icons.Default.Notifications,
        modifier = modifier,
    )
}
