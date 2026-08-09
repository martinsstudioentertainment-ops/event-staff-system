package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class MobilePortalConfigDto(
    @Json(name = "app_name") val appName: String? = null,
    val branding: MobilePortalBrandingDto? = null,
    val banner: MobilePortalBannerDto? = null,
    val announcements: List<MobilePortalAnnouncementDto>? = null,
    @Json(name = "help_links") val helpLinks: List<MobilePortalHelpLinkDto>? = null,
    val contact: MobilePortalContactDto? = null,
    val version: MobilePortalVersionDto? = null,
    val maintenance: MobilePortalMaintenanceDto? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalBrandingDto(
    @Json(name = "logo_url") val logoUrl: String? = null,
    @Json(name = "splash_logo_url") val splashLogoUrl: String? = null,
    @Json(name = "login_logo_url") val loginLogoUrl: String? = null,
    @Json(name = "dashboard_logo_url") val dashboardLogoUrl: String? = null,
    @Json(name = "welcome_image_url") val welcomeImageUrl: String? = null,
    @Json(name = "primary_color") val primaryColor: String? = null,
    @Json(name = "accent_color") val accentColor: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalBannerDto(
    val title: String? = null,
    val body: String? = null,
    @Json(name = "image_url") val imageUrl: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalAnnouncementDto(
    val title: String? = null,
    val body: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalHelpLinkDto(
    val label: String? = null,
    val url: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalContactDto(
    val email: String? = null,
    val phone: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalVersionDto(
    val label: String? = null,
    val notes: String? = null,
    @Json(name = "force_update_message") val forceUpdateMessage: String? = null,
)

@JsonClass(generateAdapter = true)
data class MobilePortalMaintenanceDto(
    val enabled: Boolean? = null,
    val message: String? = null,
)
