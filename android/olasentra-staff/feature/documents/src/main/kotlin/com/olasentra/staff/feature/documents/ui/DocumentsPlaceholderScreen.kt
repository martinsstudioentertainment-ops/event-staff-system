package com.olasentra.staff.feature.documents.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Description
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun DocumentsPlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Documents",
        subtitle = "Upload and manage compliance documents through the Mobile API in Phase 2B.",
        icon = Icons.Default.Description,
        modifier = modifier,
    )
}
