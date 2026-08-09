package com.olasentra.staff.navigation

import com.olasentra.staff.core.navigation.DeepLinkDestination
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class NotificationDeepLinkResolver @Inject constructor() {

    fun resolveFromActionUrl(actionUrl: String?): DeepLinkDestination? {
        if (actionUrl.isNullOrBlank()) return null
        val normalized = actionUrl.lowercase()

        return when {
            normalized.contains("shift") && registrationIdFromUrl(normalized) != null ->
                DeepLinkDestination.ShiftDetail(registrationIdFromUrl(normalized)!!)
            normalized.contains("shift") -> DeepLinkDestination.Shifts
            normalized.contains("check") || normalized.contains("attendance") || normalized.contains("gps") ->
                DeepLinkDestination.CheckIn
            normalized.contains("message") || normalized.contains("inbox") -> DeepLinkDestination.Messages
            normalized.contains("document") || normalized.contains("psa") -> DeepLinkDestination.Documents
            normalized.contains("availability") || normalized.contains("leave") || normalized.contains("holiday") ->
                DeepLinkDestination.Availability
            normalized.contains("notification") -> DeepLinkDestination.Notifications
            else -> DeepLinkDestination.Dashboard
        }
    }

    fun resolveFromFcmData(data: Map<String, String>): DeepLinkDestination? {
        data["deep_link"]?.let { route ->
            return resolveRouteToken(route)
        }
        data["action_url"]?.let { return resolveFromActionUrl(it) }
        data["category"]?.let { category ->
            return when (category.lowercase()) {
                "shift_assigned", "shift_updated", "shift_cancelled", "event_reminder" -> DeepLinkDestination.Shifts
                "check_in_reminder" -> DeepLinkDestination.CheckIn
                "message_received" -> DeepLinkDestination.Messages
                "document_expiry" -> DeepLinkDestination.Documents
                "approval_status" -> DeepLinkDestination.Availability
                else -> DeepLinkDestination.Notifications
            }
        }
        data["related_id"]?.toLongOrNull()?.let { relatedId ->
            return DeepLinkDestination.ShiftDetail(relatedId)
        }
        return DeepLinkDestination.Notifications
    }

    fun routeForDestination(destination: DeepLinkDestination): String {
        return when (destination) {
            DeepLinkDestination.Notifications -> com.olasentra.staff.core.navigation.Route.Notifications.route
            DeepLinkDestination.Messages -> com.olasentra.staff.core.navigation.Route.Messages.route
            DeepLinkDestination.Documents -> com.olasentra.staff.core.navigation.Route.Documents.route
            DeepLinkDestination.Availability -> com.olasentra.staff.core.navigation.Route.Availability.route
            DeepLinkDestination.Shifts -> com.olasentra.staff.core.navigation.Route.Shifts.route
            DeepLinkDestination.CheckIn -> com.olasentra.staff.core.navigation.Route.CheckIn.route
            DeepLinkDestination.Dashboard -> com.olasentra.staff.core.navigation.Route.Dashboard.route
            is DeepLinkDestination.ShiftDetail -> com.olasentra.staff.core.navigation.Route.ShiftDetail.createRoute(destination.registrationId)
        }
    }

    private fun resolveRouteToken(route: String): DeepLinkDestination? {
        return when (route.lowercase()) {
            "notifications" -> DeepLinkDestination.Notifications
            "messages" -> DeepLinkDestination.Messages
            "documents" -> DeepLinkDestination.Documents
            "availability" -> DeepLinkDestination.Availability
            "shifts" -> DeepLinkDestination.Shifts
            "check_in", "check-in", "checkin" -> DeepLinkDestination.CheckIn
            "dashboard", "home" -> DeepLinkDestination.Dashboard
            else -> {
                val prefix = "shift_detail:"
                if (route.startsWith(prefix)) {
                    route.removePrefix(prefix).toLongOrNull()?.let { DeepLinkDestination.ShiftDetail(it) }
                } else {
                    null
                }
            }
        }
    }

    private fun registrationIdFromUrl(url: String): Long? {
        val regex = Regex("registration[_-]?id[=\\-/](\\d+)|shifts/(\\d+)|shift[_-](\\d+)")
        val match = regex.find(url) ?: return null
        return match.groupValues.drop(1).firstOrNull { it.isNotBlank() }?.toLongOrNull()
    }
}
