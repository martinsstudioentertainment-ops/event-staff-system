package com.olasentra.staff.domain.model

data class VenueDistanceInfo(
    val distanceM: Int?,
    val radiusM: Int?,
    val inZone: Boolean?,
)

data class GpsStatusSummary(
    val monitoringActive: Boolean,
    val liveTracking: Boolean,
    val gpsEnabled: Boolean,
    val eventName: String?,
    val registrationId: Long?,
    val eventDate: String?,
    val venueName: String?,
    val venueLat: Double?,
    val venueLng: Double?,
    val shiftStartTime: String?,
    val shiftEndTime: String?,
    val shiftStatusLabel: String?,
    val assignedCompany: String?,
    val checkInAllowed: Boolean,
    val checkInReason: String?,
    val checkOutAllowed: Boolean,
    val checkOutReason: String?,
    val isCheckedIn: Boolean,
    val attendanceState: String,
    val checkedInAt: String?,
    val checkedOutAt: String?,
    val hoursWorked: Double?,
    val maxAccuracyM: Int?,
    val monitoringRequired: Boolean,
    val venueDistance: VenueDistanceInfo?,
)

data class AttendanceActionResult(
    val success: Boolean,
    val message: String,
    val alreadySubmitted: Boolean = false,
    val queuedOffline: Boolean = false,
    val monitoringRequired: Boolean = false,
    val venueDistance: VenueDistanceInfo? = null,
    val hoursWorked: Double? = null,
)
