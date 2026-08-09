package com.olasentra.staff.data.remote.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class ConfigResponse(
    val ok: Boolean? = null,
    @Json(name = "app_name") val appName: String? = null,
    @Json(name = "min_app_version") val minAppVersion: String? = null,
    @Json(name = "privacy_url") val privacyUrl: String? = null,
    @Json(name = "terms_url") val termsUrl: String? = null,
    @Json(name = "email_otp_enabled") val emailOtpEnabled: Boolean? = null,
)
