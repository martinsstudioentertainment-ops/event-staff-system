package com.olasentra.staff.feature.profile.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.NavHostController
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.profile.ui.ChangePasswordScreen
import com.olasentra.staff.feature.profile.ui.EditProfileScreen
import com.olasentra.staff.feature.profile.ui.ProfileScreen
import com.olasentra.staff.feature.profile.ui.SettingsScreen

fun NavGraphBuilder.profileGraph(
    navController: NavHostController,
    onSignedOut: () -> Unit,
    onOpenDocuments: () -> Unit = {},
    onOpenAvailability: () -> Unit = {},
    onOpenNotifications: () -> Unit = {},
) {
    composable(Route.Profile.route) {
        ProfileScreen(
            onSignedOut = onSignedOut,
            onOpenDocuments = onOpenDocuments,
            onOpenAvailability = onOpenAvailability,
            onOpenNotifications = onOpenNotifications,
            onOpenSettings = { navController.navigate(Route.Settings.route) },
        )
    }

    composable(Route.Settings.route) {
        SettingsScreen(
            onBack = { navController.popBackStack() },
            onUpdateProfile = { navController.navigate(Route.EditProfile.route) },
            onChangePassword = { navController.navigate(Route.ChangePassword.route) },
            onOpenNotifications = onOpenNotifications,
            onSignedOut = onSignedOut,
        )
    }

    composable(Route.EditProfile.route) {
        EditProfileScreen(
            onBack = { navController.popBackStack() },
            onSaved = { navController.popBackStack() },
        )
    }

    composable(Route.ChangePassword.route) {
        ChangePasswordScreen(
            onBack = { navController.popBackStack() },
            onPasswordChanged = { navController.popBackStack() },
        )
    }
}
