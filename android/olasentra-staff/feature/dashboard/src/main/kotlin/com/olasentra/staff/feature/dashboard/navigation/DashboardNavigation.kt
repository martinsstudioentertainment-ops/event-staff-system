package com.olasentra.staff.feature.dashboard.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.dashboard.ui.DashboardScreen

fun NavGraphBuilder.dashboardGraph(
    onOpenNotifications: () -> Unit = {},
) {
    composable(Route.Dashboard.route) {
        DashboardScreen(onOpenNotifications = onOpenNotifications)
    }
}
