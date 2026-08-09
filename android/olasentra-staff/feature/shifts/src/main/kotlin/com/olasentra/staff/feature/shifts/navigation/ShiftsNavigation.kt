package com.olasentra.staff.feature.shifts.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.NavType
import androidx.navigation.compose.composable
import androidx.navigation.navArgument
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.shifts.ui.AvailableEventsScreen
import com.olasentra.staff.feature.shifts.ui.ShiftDetailScreen
import com.olasentra.staff.feature.shifts.ui.ShiftsScreen

fun NavGraphBuilder.shiftsGraph(
    onShiftSelected: (Long) -> Unit,
    onBrowseAvailableEvents: () -> Unit,
) {
    composable(Route.Shifts.route) {
        ShiftsScreen(
            onShiftSelected = onShiftSelected,
            onBrowseAvailableEvents = onBrowseAvailableEvents,
        )
    }

    composable(Route.AvailableEvents.route) {
        AvailableEventsScreen()
    }

    composable(
        route = Route.ShiftDetail.route,
        arguments = listOf(
            navArgument(Route.ShiftDetail.registrationIdArg) {
                type = NavType.LongType
            },
        ),
    ) { backStackEntry ->
        val registrationId = backStackEntry.arguments?.getLong(Route.ShiftDetail.registrationIdArg)
            ?: return@composable
        ShiftDetailScreen(registrationId = registrationId)
    }
}
