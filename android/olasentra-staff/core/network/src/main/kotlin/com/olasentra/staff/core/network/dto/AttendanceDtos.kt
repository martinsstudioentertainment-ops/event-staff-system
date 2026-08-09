package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class GpsCoordinatesDto(
    val lat: Double? = null,
    val lng: Double? = null,
    @Json(name = "accuracy_m") val accuracyM: Int? = null,
)

@JsonClass(generateAdapter = true)
data class VenueDistanceDto(
    @Json(name = "distance_m") val distanceM: Int? = null,
    @Json(name = "radius_m") val radiusM: Int? = null,
    @Json(name = "in_zone") val inZone: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class CheckinRequest(
    @Json(name = "registration_id") val registrationId: Long,
    @Json(name = "sign_lat") val signLat: Double,
    @Json(name = "sign_lng") val signLng: Double,
    @Json(name = "sign_accuracy_m") val signAccuracyM: Int? = null,
)

@JsonClass(generateAdapter = true)
data class CheckinResponse(
    val ok: Boolean? = null,
    @Json(name = "check_in_status") val checkInStatus: String? = null,
    val already: Boolean? = null,
    val message: String? = null,
    @Json(name = "checked_in_at") val checkedInAt: String? = null,
    @Json(name = "attendance_status") val attendanceStatus: String? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
    val coordinates: GpsCoordinatesDto? = null,
    @Json(name = "venue_distance") val venueDistance: VenueDistanceDto? = null,
    @Json(name = "monitoring_required") val monitoringRequired: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class CheckoutRequest(
    @Json(name = "registration_id") val registrationId: Long,
    @Json(name = "sign_lat") val signLat: Double? = null,
    @Json(name = "sign_lng") val signLng: Double? = null,
    @Json(name = "sign_accuracy_m") val signAccuracyM: Int? = null,
)

@JsonClass(generateAdapter = true)
data class CheckoutResponse(
    val ok: Boolean? = null,
    @Json(name = "check_out_status") val checkOutStatus: String? = null,
    @Json(name = "signed_out") val signedOut: Boolean? = null,
    val already: Boolean? = null,
    val message: String? = null,
    @Json(name = "checked_out_at") val checkedOutAt: String? = null,
    @Json(name = "hours_worked") val hoursWorked: Double? = null,
    @Json(name = "attendance_status") val attendanceStatus: String? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
    val coordinates: GpsCoordinatesDto? = null,
    @Json(name = "venue_distance") val venueDistance: VenueDistanceDto? = null,
)

@JsonClass(generateAdapter = true)
data class GpsPingRequest(
    @Json(name = "sign_lat") val signLat: Double,
    @Json(name = "sign_lng") val signLng: Double,
    @Json(name = "sign_accuracy_m") val signAccuracyM: Int? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
)

@JsonClass(generateAdapter = true)
data class GpsPingResponse(
    val ok: Boolean? = null,
    @Json(name = "in_zone") val inZone: Boolean? = null,
    val activated: Boolean? = null,
    @Json(name = "signed_out") val signedOut: Boolean? = null,
    @Json(name = "outside_warning") val outsideWarning: Boolean? = null,
    val strikes: Int? = null,
    val message: String? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
    val coordinates: GpsCoordinatesDto? = null,
    @Json(name = "venue_distance") val venueDistance: VenueDistanceDto? = null,
)
