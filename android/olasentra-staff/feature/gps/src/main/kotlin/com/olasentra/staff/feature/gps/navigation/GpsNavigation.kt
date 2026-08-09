package com.olasentra.staff.feature.gps.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.gps.ui.CheckInScreen

fun NavGraphBuilder.gpsGraph() {
    composable(Route.CheckIn.route) {
        CheckInScreen()
    }
}
