package com.olasentra.staff.feature.shifts.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Work
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun ShiftsPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Shifts",
        subtitle = "Browse assigned shifts, accept invitations, and view details in Phase 2B.",
        icon = Icons.Default.Work,
        modifier = modifier,
    )
}
