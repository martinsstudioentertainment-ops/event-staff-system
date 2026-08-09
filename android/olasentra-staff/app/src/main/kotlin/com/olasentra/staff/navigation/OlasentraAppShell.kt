package com.olasentra.staff.navigation

import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.olasentra.staff.app.permissions.RequestNotificationPermissionEffect
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.core.ui.components.OlasentraBottomBar
import com.olasentra.staff.feature.dashboard.navigation.dashboardGraph
import com.olasentra.staff.feature.gps.navigation.gpsGraph
import com.olasentra.staff.feature.messages.navigation.messagesGraph
import com.olasentra.staff.feature.profile.navigation.profileGraph
import com.olasentra.staff.feature.shifts.navigation.shiftsGraph

import com.olasentra.staff.fcm.FcmTokenRegistrar
import com.olasentra.staff.domain.repository.PushRepository
import dagger.hilt.android.EntryPointAccessors
import dagger.hilt.EntryPoint
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import kotlinx.coroutines.launch

@EntryPoint
@InstallIn(SingletonComponent::class)
interface PushRepositoryEntryPoint {
    fun pushRepository(): PushRepository
}

@EntryPoint
@InstallIn(SingletonComponent::class)
interface FcmTokenRegistrarEntryPoint {
    fun fcmTokenRegistrar(): FcmTokenRegistrar
}

@Composable
fun OlasentraAppShell(
    onSignedOut: () -> Unit,
    onNavigateToOuterRoute: (String) -> Unit,
    innerDeepLinkRoute: String? = null,
    onInnerDeepLinkConsumed: () -> Unit = {},
    modifier: Modifier = Modifier,
) {
    val innerNavController = rememberNavController()
    val backStackEntry by innerNavController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    val context = androidx.compose.ui.platform.LocalContext.current

    RequestNotificationPermissionEffect()

    LaunchedEffect(Unit) {
        val pushRepository = EntryPointAccessors.fromApplication(
            context.applicationContext,
            PushRepositoryEntryPoint::class.java,
        ).pushRepository()
        val fcmTokenRegistrar = EntryPointAccessors.fromApplication(
            context.applicationContext,
            FcmTokenRegistrarEntryPoint::class.java,
        ).fcmTokenRegistrar()
        fcmTokenRegistrar.registerCurrentDeviceToken()
        pushRepository.registerPendingTokenIfNeeded()
    }

    LaunchedEffect(innerDeepLinkRoute) {
        val route = innerDeepLinkRoute ?: return@LaunchedEffect
        innerNavController.navigate(route) {
            popUpTo(innerNavController.graph.findStartDestination().id) {
                saveState = true
            }
            launchSingleTop = true
            restoreState = route != Route.ShiftDetail.route
        }
        onInnerDeepLinkConsumed()
    }

    Scaffold(
        modifier = modifier,
        bottomBar = {
            OlasentraBottomBar(
                currentRoute = currentRoute,
                onNavigate = { route ->
                    innerNavController.navigate(route) {
                        popUpTo(innerNavController.graph.findStartDestination().id) {
                            saveState = true
                        }
                        launchSingleTop = true
                        restoreState = true
                    }
                },
            )
        },
    ) { innerPadding ->
        NavHost(
            navController = innerNavController,
            startDestination = Route.Dashboard.route,
            modifier = Modifier.padding(innerPadding),
        ) {
            dashboardGraph(
                onOpenNotifications = { onNavigateToOuterRoute(Route.Notifications.route) },
            )
            shiftsGraph(
                onShiftSelected = { registrationId ->
                    innerNavController.navigate(Route.ShiftDetail.createRoute(registrationId))
                },
                onBrowseAvailableEvents = {
                    innerNavController.navigate(Route.AvailableEvents.route) {
                        launchSingleTop = true
                    }
                },
            )
            gpsGraph()
            messagesGraph()
            profileGraph(
                navController = innerNavController,
                onSignedOut = onSignedOut,
                onOpenDocuments = { onNavigateToOuterRoute(Route.Documents.route) },
                onOpenAvailability = { onNavigateToOuterRoute(Route.Availability.route) },
                onOpenNotifications = { onNavigateToOuterRoute(Route.Notifications.route) },
            )
        }
    }
}
