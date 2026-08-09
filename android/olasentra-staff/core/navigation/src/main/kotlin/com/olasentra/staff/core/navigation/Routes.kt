package com.olasentra.staff.core.navigation

import android.net.Uri

sealed class Route(val route: String) {
    data object Splash : Route("splash")

    data object Welcome : Route("welcome")

    data object Login : Route("login")

    data object EmailSignIn : Route("email_sign_in")

    data object OtpVerification : Route("otp_verification/{email}/{purpose}") {
        fun createRoute(email: String, purpose: String): String =
            "otp_verification/${Uri.encode(email)}/$purpose"

        const val emailArg = "email"
        const val purposeArg = "purpose"
    }

    data object RegistrationEmail : Route("registration_email/{formSlug}") {
        fun createRoute(formSlug: String): String = "registration_email/$formSlug"

        const val formSlugArg = "formSlug"
    }

    data object ApplyRegistration : Route("apply_registration")

    data object NativeRegistration : Route("native_registration/{formSlug}") {
        fun createRoute(formSlug: String): String = "native_registration/$formSlug"

        const val formSlugArg = "formSlug"
    }

    data object Main : Route("main")

    data object Dashboard : Route("dashboard")

    data object Shifts : Route("shifts")

    data object ShiftDetail : Route("shifts/{registrationId}") {
        fun createRoute(registrationId: Long): String = "shifts/$registrationId"

        const val registrationIdArg = "registrationId"
    }

    data object CheckIn : Route("check_in")

    data object Messages : Route("messages")

    data object Profile : Route("profile")

    data object Settings : Route("settings")

    data object EditProfile : Route("edit_profile")

    data object ChangePassword : Route("change_password")

    data object Notifications : Route("notifications")

    data object Documents : Route("documents")

    data object Availability : Route("availability")

    data object AvailableEvents : Route("available_events")

    companion object {
        val bottomNavRoutes: Set<String> = setOf(
            Dashboard.route,
            Shifts.route,
            CheckIn.route,
            Messages.route,
            Profile.route,
        )

        fun fromRoute(route: String?): Route? = when (route) {
            Splash.route -> Splash
            Welcome.route -> Welcome
            Login.route -> Login
            EmailSignIn.route -> EmailSignIn
            ApplyRegistration.route -> ApplyRegistration
            Main.route -> Main
            Dashboard.route -> Dashboard
            Shifts.route -> Shifts
            CheckIn.route -> CheckIn
            Messages.route -> Messages
            Profile.route -> Profile
            Settings.route -> Settings
            EditProfile.route -> EditProfile
            ChangePassword.route -> ChangePassword
            Notifications.route -> Notifications
            Documents.route -> Documents
            Availability.route -> Availability
            AvailableEvents.route -> AvailableEvents
            else -> null
        }
    }
}
