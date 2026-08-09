package com.olasentra.staff.domain.model

data class MobileConfig(
    val apiVersion: String,
    val minAppVersion: String?,
    val mobileApiEnabled: Boolean,
    val googleSigninEnabled: Boolean,
    val googleSigninRequired: Boolean,
    val ppsSigninEnabled: Boolean,
    val emailOtpEnabled: Boolean,
    val gpsAttendanceV2Enabled: Boolean,
    val gpsMaxAccuracyM: Int?,
    val features: MobileConfigFeatures,
    val registrationSiteUrl: String?,
    val privacyUrl: String?,
    val termsUrl: String?,
    val portal: MobilePortalConfig,
)

data class MobileConfigFeatures(
    val availability: Boolean,
    val shiftResponse: Boolean,
    val offlineSync: Boolean,
)
