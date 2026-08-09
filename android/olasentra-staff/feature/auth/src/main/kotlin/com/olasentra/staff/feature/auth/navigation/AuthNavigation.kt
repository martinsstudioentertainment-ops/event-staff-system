package com.olasentra.staff.feature.auth.navigation

import androidx.compose.runtime.remember
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.composable
import androidx.navigation.navArgument
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.auth.ui.ApplyRegistrationScreen
import com.olasentra.staff.feature.auth.ui.EmailSignInScreen
import com.olasentra.staff.feature.auth.ui.LoginScreen
import com.olasentra.staff.feature.auth.ui.LoginViewModel
import com.olasentra.staff.feature.auth.ui.NativeRegistrationScreen
import com.olasentra.staff.feature.auth.ui.OtpVerificationScreen
import com.olasentra.staff.feature.auth.ui.OtpVerificationViewModel
import com.olasentra.staff.feature.auth.ui.RegistrationEmailScreen

fun NavGraphBuilder.authGraph(
    navController: NavHostController,
    onLoginSuccess: () -> Unit,
) {
    composable(Route.Login.route) {
        LoginScreen(
            onLoginSuccess = onLoginSuccess,
            onApplyToJoin = {
                navController.navigate(Route.ApplyRegistration.route)
            },
            onEmailSignIn = {
                navController.navigate(Route.EmailSignIn.route)
            },
        )
    }

    composable(Route.EmailSignIn.route) {
        EmailSignInScreen(
            onBack = { navController.popBackStack() },
            onCodeSent = { email ->
                navController.navigate(
                    Route.OtpVerification.createRoute(
                        email = email,
                        purpose = OtpVerificationViewModel.PURPOSE_LOGIN,
                    ),
                )
            },
        )
    }

    composable(
        route = Route.OtpVerification.route,
        arguments = listOf(
            navArgument(Route.OtpVerification.emailArg) { type = NavType.StringType },
            navArgument(Route.OtpVerification.purposeArg) { type = NavType.StringType },
        ),
    ) {
        OtpVerificationScreen(
            onBack = { navController.popBackStack() },
            onLoginSuccess = onLoginSuccess,
            onRegistrationVerified = {
                navController.popBackStack(Route.Login.route, inclusive = false)
            },
        )
    }

    composable(
        route = Route.RegistrationEmail.route,
        arguments = listOf(
            navArgument(Route.RegistrationEmail.formSlugArg) { type = NavType.StringType },
        ),
    ) {
        RegistrationEmailScreen(
            onBack = { navController.popBackStack() },
            onCodeSent = { email ->
                navController.navigate(
                    Route.OtpVerification.createRoute(
                        email = email,
                        purpose = OtpVerificationViewModel.PURPOSE_REGISTRATION,
                    ),
                )
            },
        )
    }

    composable(Route.ApplyRegistration.route) {
        ApplyRegistrationScreen(
            onBack = { navController.popBackStack() },
            onStartRegistration = { formSlug ->
                navController.navigate(Route.NativeRegistration.createRoute(formSlug))
            },
        )
    }

    composable(
        route = Route.NativeRegistration.route,
        arguments = listOf(
            navArgument(Route.NativeRegistration.formSlugArg) {
                type = NavType.StringType
            },
        ),
    ) { backStackEntry ->
        val loginEntry = remember(backStackEntry) {
            navController.getBackStackEntry(Route.Login.route)
        }
        val loginViewModel: LoginViewModel = hiltViewModel(loginEntry)
        val loginState = loginViewModel.uiState.collectAsStateWithLifecycle().value
        NativeRegistrationScreen(
            registrationSiteUrl = loginState.registrationSiteUrl,
            googleIdToken = loginState.pendingGoogleIdToken,
            onBack = { navController.popBackStack() },
            onSubmitted = {
                navController.popBackStack(Route.Login.route, inclusive = false)
            },
        )
    }
}
