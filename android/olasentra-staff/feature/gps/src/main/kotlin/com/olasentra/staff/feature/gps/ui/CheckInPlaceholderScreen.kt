package com.olasentra.staff.feature.gps.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun CheckInPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Check-In",
        subtitle = "GPS and QR check-in against live shifts connects in Phase 2B.",
        icon = Icons.Default.LocationOn,
        modifier = modifier,
    )
}
