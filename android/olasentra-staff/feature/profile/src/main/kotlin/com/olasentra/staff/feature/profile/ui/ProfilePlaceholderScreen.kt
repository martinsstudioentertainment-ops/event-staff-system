package com.olasentra.staff.feature.profile.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Person
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.olasentra.staff.core.ui.components.FeaturePlaceholderScreen

@Composable
fun ProfilePlaceholderScreen(
    modifier: Modifier = Modifier,
) {
    FeaturePlaceholderScreen(
        title = "Profile",
        subtitle = "View and update your staff profile from the Mobile API in Phase 2B.",
        icon = Icons.Default.Person,
        modifier = modifier,
    )
}
