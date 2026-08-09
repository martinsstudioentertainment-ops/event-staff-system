package com.olasentra.staff.feature.availability.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.availability.ui.AvailabilityScreen

fun NavGraphBuilder.availabilityGraph() {
    composable(Route.Availability.route) {
        AvailabilityScreen()
    }
}
