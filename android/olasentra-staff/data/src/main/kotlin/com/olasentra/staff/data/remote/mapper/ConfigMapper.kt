package com.olasentra.staff.data.remote.mapper

import com.olasentra.staff.core.network.dto.ConfigResponse
import com.olasentra.staff.core.network.dto.MobilePortalAnnouncementDto
import com.olasentra.staff.core.network.dto.MobilePortalHelpLinkDto
import com.olasentra.staff.domain.model.MobileConfig
import com.olasentra.staff.domain.model.MobileConfigFeatures
import com.olasentra.staff.domain.model.MobilePortalAnnouncement
import com.olasentra.staff.domain.model.MobilePortalBanner
import com.olasentra.staff.domain.model.MobilePortalBranding
import com.olasentra.staff.domain.model.MobilePortalConfig
import com.olasentra.staff.domain.model.MobilePortalContact
import com.olasentra.staff.domain.model.MobilePortalHelpLink
import com.olasentra.staff.domain.model.MobilePortalMaintenance
import com.olasentra.staff.domain.model.MobilePortalVersion
import javax.inject.Inject

class ConfigMapper @Inject constructor() {

    fun toDomain(response: ConfigResponse): MobileConfig {
        val features = response.features
        val registrationSiteUrl = response.registrationSiteUrl
        val privacyUrl = response.privacyUrl?.takeIf { it.isNotBlank() }
            ?: registrationSiteUrl?.trimEnd('/')?.plus("/privacy.php")
        val termsUrl = response.termsUrl?.takeIf { it.isNotBlank() }
            ?: registrationSiteUrl?.trimEnd('/')?.plus("/terms.php")

        return MobileConfig(
            apiVersion = response.apiVersion?.takeIf { it.isNotBlank() } ?: "1",
            minAppVersion = response.minAppVersion,
            mobileApiEnabled = response.mobileApiEnabled ?: false,
            googleSigninEnabled = response.googleSigninEnabled ?: false,
            googleSigninRequired = response.googleSigninRequired ?: false,
            ppsSigninEnabled = response.ppsSigninEnabled ?: false,
            emailOtpEnabled = response.emailOtpEnabled ?: true,
            gpsAttendanceV2Enabled = response.gpsAttendanceV2Enabled ?: false,
            gpsMaxAccuracyM = response.gpsMaxAccuracyM,
            features = MobileConfigFeatures(
                availability = features?.availability ?: false,
                shiftResponse = features?.shiftResponse ?: false,
                offlineSync = features?.offlineSync ?: false,
            ),
            registrationSiteUrl = registrationSiteUrl,
            privacyUrl = privacyUrl,
            termsUrl = termsUrl,
            portal = mapPortal(response),
        )
    }

    private fun mapPortal(response: ConfigResponse): MobilePortalConfig {
        val portal = response.portal
        val branding = portal?.branding

        return MobilePortalConfig(
            appName = portal?.appName?.takeIf { it.isNotBlank() } ?: "Olasentra",
            branding = MobilePortalBranding(
                logoUrl = branding?.logoUrl?.takeIf { it.isNotBlank() },
                splashLogoUrl = branding?.splashLogoUrl?.takeIf { it.isNotBlank() },
                loginLogoUrl = branding?.loginLogoUrl?.takeIf { it.isNotBlank() },
                dashboardLogoUrl = branding?.dashboardLogoUrl?.takeIf { it.isNotBlank() },
                welcomeImageUrl = branding?.welcomeImageUrl?.takeIf { it.isNotBlank() },
                primaryColor = branding?.primaryColor?.takeIf { it.isNotBlank() },
                accentColor = branding?.accentColor?.takeIf { it.isNotBlank() },
            ),
            banner = MobilePortalBanner(
                title = portal?.banner?.title?.takeIf { it.isNotBlank() },
                body = portal?.banner?.body?.takeIf { it.isNotBlank() },
                imageUrl = portal?.banner?.imageUrl?.takeIf { it.isNotBlank() },
            ),
            announcements = portal?.announcements.orEmpty().mapNotNull(::mapAnnouncement),
            helpLinks = portal?.helpLinks.orEmpty().mapNotNull(::mapHelpLink),
            contact = MobilePortalContact(
                email = portal?.contact?.email?.takeIf { it.isNotBlank() },
                phone = portal?.contact?.phone?.takeIf { it.isNotBlank() },
            ),
            version = MobilePortalVersion(
                label = portal?.version?.label?.takeIf { it.isNotBlank() },
                notes = portal?.version?.notes?.takeIf { it.isNotBlank() },
                forceUpdateMessage = portal?.version?.forceUpdateMessage?.takeIf { it.isNotBlank() },
            ),
            maintenance = MobilePortalMaintenance(
                enabled = portal?.maintenance?.enabled == true,
                message = portal?.maintenance?.message?.takeIf { it.isNotBlank() },
            ),
        )
    }

    private fun mapAnnouncement(dto: MobilePortalAnnouncementDto): MobilePortalAnnouncement? {
        val title = dto.title?.trim().orEmpty()
        if (title.isBlank()) {
            return null
        }
        return MobilePortalAnnouncement(
            title = title,
            body = dto.body?.takeIf { it.isNotBlank() },
        )
    }

    private fun mapHelpLink(dto: MobilePortalHelpLinkDto): MobilePortalHelpLink? {
        val label = dto.label?.trim().orEmpty()
        val url = dto.url?.trim().orEmpty()
        if (label.isBlank() || url.isBlank()) {
            return null
        }
        return MobilePortalHelpLink(label = label, url = url)
    }
}
