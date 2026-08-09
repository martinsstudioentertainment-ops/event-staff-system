package com.olasentra.staff.core.database

object ApiCacheKeys {
    const val DASHBOARD = "dashboard"
    const val PROFILE = "profile"
    const val SHIFTS_TODAY = "shifts_today"
    const val GPS_STATUS = "gps_status"
    const val MESSAGES = "messages"
    const val NOTIFICATIONS = "notifications"
    const val DOCUMENTS = "documents"
    const val AVAILABLE_EVENTS = "available_events"

    fun availability(month: String): String = "availability_$month"

    fun shiftsList(filter: String): String = "shifts_list_$filter"

    fun shiftDetail(registrationId: Long): String = "shift_detail_$registrationId"
}
