package com.olasentra.staff.core.navigation

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Message
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Work
import androidx.compose.ui.graphics.vector.ImageVector

enum class BottomNavDestination(
    val route: Route,
    val label: String,
    val icon: ImageVector,
) {
    Home(
        route = Route.Dashboard,
        label = "Home",
        icon = Icons.Default.Home,
    ),
    Shifts(
        route = Route.Shifts,
        label = "Shifts",
        icon = Icons.Default.Work,
    ),
    CheckIn(
        route = Route.CheckIn,
        label = "Check-In",
        icon = Icons.Default.LocationOn,
    ),
    Messages(
        route = Route.Messages,
        label = "Messages",
        icon = Icons.Default.Message,
    ),
    Profile(
        route = Route.Profile,
        label = "Profile",
        icon = Icons.Default.Person,
    ),
    ;

    val routeString: String get() = route.route

    companion object {
        fun fromRoute(route: String?): BottomNavDestination? =
            entries.firstOrNull { it.routeString == route }
    }
}
