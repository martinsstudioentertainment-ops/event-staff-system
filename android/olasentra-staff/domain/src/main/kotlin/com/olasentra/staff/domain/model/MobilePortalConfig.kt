package com.olasentra.staff.domain.model

data class MobilePortalConfig(
    val appName: String = "Olasentra",
    val branding: MobilePortalBranding = MobilePortalBranding(),
    val banner: MobilePortalBanner = MobilePortalBanner(),
    val announcements: List<MobilePortalAnnouncement> = emptyList(),
    val helpLinks: List<MobilePortalHelpLink> = emptyList(),
    val contact: MobilePortalContact = MobilePortalContact(),
    val version: MobilePortalVersion = MobilePortalVersion(),
    val maintenance: MobilePortalMaintenance = MobilePortalMaintenance(),
)

data class MobilePortalBranding(
    val logoUrl: String? = null,
    val splashLogoUrl: String? = null,
    val loginLogoUrl: String? = null,
    val dashboardLogoUrl: String? = null,
    val welcomeImageUrl: String? = null,
    val primaryColor: String? = null,
    val accentColor: String? = null,
)

data class MobilePortalBanner(
    val title: String? = null,
    val body: String? = null,
    val imageUrl: String? = null,
)

data class MobilePortalAnnouncement(
    val title: String,
    val body: String? = null,
)

data class MobilePortalHelpLink(
    val label: String,
    val url: String,
)

data class MobilePortalContact(
    val email: String? = null,
    val phone: String? = null,
)

data class MobilePortalVersion(
    val label: String? = null,
    val notes: String? = null,
    val forceUpdateMessage: String? = null,
)

data class MobilePortalMaintenance(
    val enabled: Boolean = false,
    val message: String? = null,
)
