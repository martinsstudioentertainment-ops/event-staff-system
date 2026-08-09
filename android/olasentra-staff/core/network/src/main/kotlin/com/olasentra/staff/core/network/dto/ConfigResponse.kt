package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class ConfigFeaturesDto(
    val availability: Boolean? = null,
    @Json(name = "shift_response") val shiftResponse: Boolean? = null,
    @Json(name = "offline_sync") val offlineSync: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class ConfigResponse(
    val ok: Boolean? = null,
    @Json(name = "api_version") val apiVersion: String? = null,
    @Json(name = "min_app_version") val minAppVersion: String? = null,
    @Json(name = "mobile_api_enabled") val mobileApiEnabled: Boolean? = null,
    @Json(name = "google_signin_enabled") val googleSigninEnabled: Boolean? = null,
    @Json(name = "google_signin_required") val googleSigninRequired: Boolean? = null,
    @Json(name = "pps_signin_enabled") val ppsSigninEnabled: Boolean? = null,
    @Json(name = "email_otp_enabled") val emailOtpEnabled: Boolean? = null,
    @Json(name = "gps_attendance_v2_enabled") val gpsAttendanceV2Enabled: Boolean? = null,
    @Json(name = "gps_max_accuracy_m") val gpsMaxAccuracyM: Int? = null,
    val features: ConfigFeaturesDto? = null,
    @Json(name = "registration_site_url") val registrationSiteUrl: String? = null,
    @Json(name = "privacy_url") val privacyUrl: String? = null,
    @Json(name = "terms_url") val termsUrl: String? = null,
    val portal: MobilePortalConfigDto? = null,
)
