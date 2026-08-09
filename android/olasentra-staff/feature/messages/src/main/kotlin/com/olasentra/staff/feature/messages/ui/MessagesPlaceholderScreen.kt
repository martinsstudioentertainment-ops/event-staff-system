package com.olasentra.staff.feature.messages.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Message
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun MessagesPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Messages",
        subtitle = "Staff messaging from the communication hub arrives in Phase 2B.",
        icon = Icons.Default.Message,
        modifier = modifier,
    )
}
