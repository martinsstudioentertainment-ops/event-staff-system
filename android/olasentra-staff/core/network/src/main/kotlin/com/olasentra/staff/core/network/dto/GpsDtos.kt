package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class GpsStatusVenueDto(
    val name: String? = null,
    val eircode: String? = null,
    @Json(name = "location_lat") val locationLat: Double? = null,
    @Json(name = "location_lng") val locationLng: Double? = null,
)

@JsonClass(generateAdapter = true)
data class GpsStatusShiftDto(
    @Json(name = "registration_id") val registrationId: Long? = null,
    @Json(name = "event_id") val eventId: Long? = null,
    @Json(name = "event_name") val eventName: String? = null,
    @Json(name = "event_date") val eventDate: String? = null,
    @Json(name = "start_time") val startTime: String? = null,
    @Json(name = "end_time") val endTime: String? = null,
    @Json(name = "shift_status") val shiftStatus: String? = null,
    @Json(name = "shift_status_label") val shiftStatusLabel: String? = null,
    @Json(name = "assigned_company") val assignedCompany: String? = null,
    val venue: GpsStatusVenueDto? = null,
    @Json(name = "check_in_eligibility") val checkInEligibility: ShiftEligibilityDto? = null,
    @Json(name = "check_out_eligibility") val checkOutEligibility: ShiftEligibilityDto? = null,
)

@JsonClass(generateAdapter = true)
data class GpsStatusAttendanceDto(
    @Json(name = "is_checked_in") val isCheckedIn: Boolean? = null,
    @Json(name = "checked_in") val checkedIn: Boolean? = null,
    @Json(name = "checked_in_at") val checkedInAt: String? = null,
    @Json(name = "checked_out_at") val checkedOutAt: String? = null,
    @Json(name = "attendance_status") val attendanceStatus: String? = null,
    @Json(name = "hours_worked") val hoursWorked: Double? = null,
)

@JsonClass(generateAdapter = true)
data class GpsPolicyDto(
    @Json(name = "gps_enabled") val gpsEnabled: Boolean? = null,
    @Json(name = "max_accuracy_m") val maxAccuracyM: Int? = null,
    @Json(name = "geofence_strikes") val geofenceStrikes: Int? = null,
)

@JsonClass(generateAdapter = true)
data class GpsStatusResponse(
    val ok: Boolean? = null,
    val monitoring: Boolean? = null,
    val live: Boolean? = null,
    val shift: GpsStatusShiftDto? = null,
    val attendance: GpsStatusAttendanceDto? = null,
    val policy: GpsPolicyDto? = null,
    @Json(name = "venue_distance") val venueDistance: VenueDistanceDto? = null,
)
