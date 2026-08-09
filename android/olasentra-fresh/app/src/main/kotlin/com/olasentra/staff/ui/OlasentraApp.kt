package com.olasentra.staff.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.olasentra.staff.ui.auth.LoginScreen
import com.olasentra.staff.ui.auth.OtpScreen
import com.olasentra.staff.ui.bootstrap.AppDestination
import com.olasentra.staff.ui.bootstrap.AppViewModel
import com.olasentra.staff.ui.dashboard.DashboardScreen
import java.net.URLDecoder
import java.net.URLEncoder
import java.nio.charset.StandardCharsets

private object Routes {
    const val Login = "login"
    const val Otp = "otp/{email}"
    const val Dashboard = "dashboard"

    fun otpRoute(email: String): String {
        val encoded = URLEncoder.encode(email, StandardCharsets.UTF_8.toString())
        return "otp/$encoded"
    }

    fun decodeEmail(raw: String): String {
        return URLDecoder.decode(raw, StandardCharsets.UTF_8.toString())
    }
}

@Composable
fun OlasentraApp(
    viewModel: AppViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val startDestination = state.startDestination

    if (state.isBootstrapping || startDestination == null) {
        Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center,
        ) {
            CircularProgressIndicator()
        }
        return
    }

    val navController = rememberNavController()
    val startRoute = when (startDestination) {
        AppDestination.Login -> Routes.Login
        AppDestination.Dashboard -> Routes.Dashboard
    }

    NavHost(
        navController = navController,
        startDestination = startRoute,
    ) {
        composable(Routes.Login) {
            LoginScreen(
                appName = state.appName,
                emailOtpEnabled = state.emailOtpEnabled,
                configError = state.configError,
                onOtpSent = { email ->
                    navController.navigate(Routes.otpRoute(email))
                },
            )
        }

        composable(
            route = Routes.Otp,
            arguments = listOf(navArgument("email") { type = NavType.StringType }),
        ) { entry ->
            val email = Routes.decodeEmail(entry.arguments?.getString("email").orEmpty())
            OtpScreen(
                email = email,
                onVerified = {
                    navController.navigate(Routes.Dashboard) {
                        popUpTo(Routes.Login) { inclusive = true }
                    }
                },
                onBack = { navController.popBackStack() },
            )
        }

        composable(Routes.Dashboard) {
            DashboardScreen(
                onSignedOut = {
                    navController.navigate(Routes.Login) {
                        popUpTo(Routes.Dashboard) { inclusive = true }
                    }
                },
            )
        }
    }
}
