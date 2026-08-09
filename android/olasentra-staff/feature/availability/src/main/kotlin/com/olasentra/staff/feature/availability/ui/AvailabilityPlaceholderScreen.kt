package com.olasentra.staff.feature.availability.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.EventAvailable
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun AvailabilityPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Availability",
        subtitle = "Submit availability and leave requests through the Mobile API in Phase 2B.",
        icon = Icons.Default.EventAvailable,
        modifier = modifier,
    )
}
