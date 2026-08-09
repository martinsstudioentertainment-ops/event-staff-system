package com.olasentra.staff.navigation

import androidx.navigation.NavHostController
import com.olasentra.staff.core.navigation.Route

object DeepLinkNavigator {

    fun navigate(
        navController: NavHostController,
        route: String,
        onInnerRoute: (String) -> Unit,
    ) {
        when (route) {
            Route.Notifications.route,
            Route.Documents.route,
            Route.Availability.route,
            -> {
                navController.navigate(route) {
                    launchSingleTop = true
                }
            }
            Route.ShiftDetail.route -> {
                navController.navigate(Route.Main.route) {
                    launchSingleTop = true
                }
                onInnerRoute(route)
            }
            else -> {
                navController.navigate(Route.Main.route) {
                    launchSingleTop = true
                }
                onInnerRoute(route)
            }
        }
    }

    fun navigateShiftDetail(
        navController: NavHostController,
        registrationId: Long,
        onInnerRoute: (String) -> Unit,
    ) {
        navigate(
            navController = navController,
            route = Route.ShiftDetail.createRoute(registrationId),
            onInnerRoute = onInnerRoute,
        )
    }
}
