package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class AuthGoogleRequest(
    @Json(name = "id_token") val idToken: String,
    @Json(name = "device_id") val deviceId: String,
    @Json(name = "device_label") val deviceLabel: String? = null,
    @Json(name = "fcm_token") val fcmToken: String? = null,
)

@JsonClass(generateAdapter = true)
data class AuthRefreshRequest(
    @Json(name = "refresh_token") val refreshToken: String,
    @Json(name = "device_id") val deviceId: String,
)

@JsonClass(generateAdapter = true)
data class AuthLogoutRequest(
    @Json(name = "refresh_token") val refreshToken: String? = null,
    @Json(name = "device_id") val deviceId: String? = null,
    @Json(name = "revoke_all_devices") val revokeAllDevices: Boolean = false,
)

@JsonClass(generateAdapter = true)
data class StaffSummaryDto(
    val id: Long? = null,
    val email: String? = null,
    @Json(name = "first_name") val firstName: String? = null,
    val surname: String? = null,
    @Json(name = "profile_complete") val profileComplete: Boolean? = null,
    @Json(name = "profile_reverify_required") val profileReverifyRequired: Boolean? = null,
    @Json(name = "profile_gate_blocked") val profileGateBlocked: Boolean? = null,
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
data class TokenResponse(
    val ok: Boolean? = null,
    @Json(name = "access_token") val accessToken: String? = null,
    @Json(name = "refresh_token") val refreshToken: String? = null,
    @Json(name = "expires_in") val expiresIn: Int? = null,
    @Json(name = "token_type") val tokenType: String? = null,
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
data class ChangePasswordRequest(
    @Json(name = "otp_code") val otpCode: String? = null,
    @Json(name = "current_password") val currentPassword: String? = null,
    @Json(name = "new_password") val newPassword: String,
    @Json(name = "send_code") val sendCode: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class PatchMeRequest(
    val mobile: String? = null,
    @Json(name = "full_address") val fullAddress: String? = null,
    val eircode: String? = null,
)

@JsonClass(generateAdapter = true)
data class OkMessageResponse(
    val ok: Boolean? = null,
    val message: String? = null,
)
