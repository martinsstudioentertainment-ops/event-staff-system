package com.olasentra.staff.navigation

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.auth.navigation.authGraph
import com.olasentra.staff.feature.availability.navigation.availabilityGraph
import com.olasentra.staff.feature.documents.navigation.documentsGraph
import com.olasentra.staff.feature.notifications.navigation.notificationsGraph
import com.olasentra.staff.ui.SessionNavigationViewModel
import com.olasentra.staff.ui.SplashScreen
import dagger.hilt.android.EntryPointAccessors
import dagger.hilt.EntryPoint
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent

@EntryPoint
@InstallIn(SingletonComponent::class)
interface PendingDeepLinkEntryPoint {
    fun pendingDeepLinkBus(): PendingDeepLinkBus
}

@Composable
fun OlasentraNavHost(
    initialDeepLinkRoute: String? = null,
    modifier: Modifier = Modifier,
    navController: NavHostController = rememberNavController(),
    sessionNavigationViewModel: SessionNavigationViewModel = hiltViewModel(),
) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val deepLinkBus = remember {
        EntryPointAccessors.fromApplication(
            context.applicationContext,
            PendingDeepLinkEntryPoint::class.java,
        ).pendingDeepLinkBus()
    }
    var innerDeepLinkRoute by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(initialDeepLinkRoute) {
        initialDeepLinkRoute?.let { route ->
            DeepLinkNavigator.navigate(navController, route) { innerDeepLinkRoute = it }
        }
    }

    LaunchedEffect(deepLinkBus) {
        deepLinkBus.events.collect { route ->
            DeepLinkNavigator.navigate(navController, route) { innerDeepLinkRoute = it }
        }
    }

    LaunchedEffect(sessionNavigationViewModel) {
        sessionNavigationViewModel.sessionExpiredEvents.collect {
            navController.navigate(Route.Login.route) {
                popUpTo(Route.Splash.route) { inclusive = false }
                launchSingleTop = true
            }
        }
    }

    NavHost(
        navController = navController,
        startDestination = Route.Splash.route,
        modifier = modifier,
    ) {
        composable(Route.Splash.route) {
            SplashScreen(
                onNavigateToLogin = {
                    navController.navigate(Route.Login.route) {
                        popUpTo(Route.Splash.route) { inclusive = true }
                    }
                },
                onNavigateToMain = {
                    navController.navigate(Route.Main.route) {
                        popUpTo(Route.Splash.route) { inclusive = true }
                    }
                },
            )
        }

        authGraph(
            navController = navController,
            onLoginSuccess = {
                navController.navigate(Route.Main.route) {
                    popUpTo(Route.Login.route) { inclusive = true }
                }
            },
        )

        composable(Route.Main.route) {
            OlasentraAppShell(
                innerDeepLinkRoute = innerDeepLinkRoute,
                onInnerDeepLinkConsumed = { innerDeepLinkRoute = null },
                onNavigateToOuterRoute = { route ->
                    navController.navigate(route) {
                        launchSingleTop = true
                    }
                },
                onSignedOut = {
                    navController.navigate(Route.Login.route) {
                        popUpTo(Route.Main.route) { inclusive = true }
                    }
                },
            )
        }

        notificationsGraph(
            onOpenRoute = { route ->
                DeepLinkNavigator.navigate(navController, route) { innerDeepLinkRoute = it }
            },
            onOpenShiftDetail = { registrationId ->
                DeepLinkNavigator.navigateShiftDetail(navController, registrationId) {
                    innerDeepLinkRoute = it
                }
            },
        )
        documentsGraph()
        availabilityGraph()
    }
}
