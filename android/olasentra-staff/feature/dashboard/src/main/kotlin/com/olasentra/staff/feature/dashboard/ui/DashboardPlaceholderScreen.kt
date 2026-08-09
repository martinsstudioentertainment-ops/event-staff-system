package com.olasentra.staff.feature.dashboard.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun DashboardPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Home",
        subtitle = "Your dashboard with shifts, alerts, and quick actions arrives in Phase 2B.",
        icon = Icons.Default.Home,
        modifier = modifier,
    )
}
