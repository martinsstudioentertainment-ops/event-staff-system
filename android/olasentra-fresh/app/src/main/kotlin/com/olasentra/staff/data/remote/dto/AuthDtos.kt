package com.olasentra.staff.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class StaffSummaryDto(
    val id: Long? = null,
    val email: String? = null,
    @Json(name = "first_name") val firstName: String? = null,
    val surname: String? = null,
    @Json(name = "profile_complete") val profileComplete: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class AuthSuccessResponse(
    val ok: Boolean? = null,
    @Json(name = "access_token") val accessToken: String? = null,
    @Json(name = "refresh_token") val refreshToken: String? = null,
    @Json(name = "expires_in") val expiresIn: Int? = null,
    @Json(name = "token_type") val tokenType: String? = null,
    val staff: StaffSummaryDto? = null,
)

@JsonClass(generateAdapter = true)
data class AuthOtpSendRequest(
    val email: String,
    val purpose: String = "login",
    @Json(name = "device_id") val deviceId: String,
)

@JsonClass(generateAdapter = true)
data class AuthOtpVerifyRequest(
    val email: String,
    val code: String,
    @Json(name = "device_id") val deviceId: String,
    @Json(name = "fcm_token") val fcmToken: String? = null,
)

@JsonClass(generateAdapter = true)
data class OtpSendResponse(
    val ok: Boolean? = null,
    @Json(name = "expires_in") val expiresIn: Int? = null,
    @Json(name = "resend_in") val resendIn: Int? = null,
)

@JsonClass(generateAdapter = true)
data class DashboardResponse(
    val ok: Boolean? = null,
    val profile: Map<String, Any?>? = null,
    @Json(name = "approval_status") val approvalStatus: Map<String, Any?>? = null,
    @Json(name = "upcoming_shifts") val upcomingShifts: List<Map<String, Any?>>? = null,
    val unread: Map<String, Any?>? = null,
    @Json(name = "check_in_status") val checkInStatus: Map<String, Any?>? = null,
    @Json(name = "available_events_count") val availableEventsCount: Int? = null,
    @Json(name = "today_shift") val todayShift: Map<String, Any?>? = null,
)

@JsonClass(generateAdapter = true)
data class ApiErrorResponse(
    val ok: Boolean? = null,
    val error: String? = null,
    val message: String? = null,
    val code: String? = null,
)
