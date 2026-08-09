package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class ShiftEligibilityDto(
    val allowed: Boolean? = null,
    val reason: String? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftAttendanceDto(
    @Json(name = "is_checked_in") val isCheckedIn: Boolean? = null,
    @Json(name = "checked_in_at") val checkedInAt: String? = null,
    @Json(name = "checked_out_at") val checkedOutAt: String? = null,
    @Json(name = "attendance_status") val attendanceStatus: String? = null,
    @Json(name = "hours_worked") val hoursWorked: Double? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftObjectDto(
    @Json(name = "registration_id") val registrationId: Long? = null,
    @Json(name = "waitlist_id") val waitlistId: Long? = null,
    @Json(name = "record_type") val recordType: String? = null,
    @Json(name = "event_id") val eventId: Long? = null,
    @Json(name = "event_name") val eventName: String? = null,
    @Json(name = "event_date") val eventDate: String? = null,
    val venue: ShiftVenueDto? = null,
    @Json(name = "start_time") val startTime: String? = null,
    @Json(name = "end_time") val endTime: String? = null,
    @Json(name = "time_label") val timeLabel: String? = null,
    val status: String? = null,
    @Json(name = "shift_status") val shiftStatus: String? = null,
    @Json(name = "shift_status_label") val shiftStatusLabel: String? = null,
    @Json(name = "shift_response") val shiftResponse: String? = null,
    @Json(name = "assigned_company") val assignedCompany: String? = null,
    @Json(name = "check_in_eligibility") val checkInEligibility: ShiftEligibilityDto? = null,
    @Json(name = "check_out_eligibility") val checkOutEligibility: ShiftEligibilityDto? = null,
    val attendance: ShiftAttendanceDto? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftsPaginationDto(
    val page: Int? = null,
    @Json(name = "per_page") val perPage: Int? = null,
    val total: Int? = null,
    @Json(name = "total_pages") val totalPages: Int? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftsFiltersDto(
    val filter: String? = null,
    val employer: String? = null,
    val q: String? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftsListResponse(
    val ok: Boolean? = null,
    val shifts: List<ShiftObjectDto>? = null,
    val pagination: ShiftsPaginationDto? = null,
    val filters: ShiftsFiltersDto? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftDetailResponse(
    val ok: Boolean? = null,
    val shift: ShiftObjectDto? = null,
)

@JsonClass(generateAdapter = true)
data class CheckInStatusDto(
    @Json(name = "has_shift_today") val hasShiftToday: Boolean? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
    @Json(name = "checked_in") val checkedIn: Boolean? = null,
    @Json(name = "checked_in_at") val checkedInAt: String? = null,
    @Json(name = "checked_out_at") val checkedOutAt: String? = null,
    @Json(name = "attendance_status") val attendanceStatus: String? = null,
    @Json(name = "checkin_allowed") val checkinAllowed: Boolean? = null,
    @Json(name = "checkin_block_reason") val checkinBlockReason: String? = null,
    @Json(name = "monitoring_active") val monitoringActive: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftTodayResponse(
    val ok: Boolean? = null,
    val shift: ShiftObjectDto? = null,
    val checkin: CheckInStatusDto? = null,
)
