package com.olasentra.staff.domain.model

data class DashboardSummary(
    val staffDisplayName: String,
    val approvalLabel: String,
    val approvalDetail: String,
    val upcomingShifts: List<UpcomingShiftSummary>,
    val unreadMessages: Int,
    val unreadNotifications: Int,
    val availableEventsCount: Int,
)

data class UpcomingShiftSummary(
    val eventName: String,
    val eventDate: String,
    val venueName: String,
    val shiftStatus: String,
)
