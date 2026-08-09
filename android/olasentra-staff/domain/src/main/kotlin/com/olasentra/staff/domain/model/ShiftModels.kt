package com.olasentra.staff.domain.model

enum class ShiftFilter(val apiValue: String) {
    ALL("all"),
    UPCOMING("upcoming"),
    PAST("past"),
}

data class ShiftSummary(
    val registrationId: Long?,
    val eventName: String,
    val venueName: String,
    val eventDate: String,
    val startTime: String,
    val endTime: String,
    val statusLabel: String,
    val assignedCompany: String,
)

data class ShiftTodaySummary(
    val shift: ShiftSummary?,
    val hasShiftToday: Boolean,
    val checkedIn: Boolean,
    val attendanceStatus: String?,
)

data class ShiftsOverview(
    val today: ShiftTodaySummary?,
    val shifts: List<ShiftSummary>,
    val activeFilter: ShiftFilter,
)

data class ShiftDetail(
    val registrationId: Long,
    val eventName: String,
    val venueName: String,
    val eventDate: String,
    val startTime: String,
    val endTime: String,
    val statusLabel: String,
    val assignedCompany: String,
    val checkInAllowed: Boolean,
    val checkInReason: String?,
    val checkOutAllowed: Boolean,
    val checkOutReason: String?,
    val isCheckedIn: Boolean,
    val checkedInAt: String?,
    val checkedOutAt: String?,
    val attendanceStatus: String?,
)
