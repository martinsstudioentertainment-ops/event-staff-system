package com.olasentra.staff.data.remote.mapper

import com.olasentra.staff.core.network.dto.DashboardApprovalStatusDto
import com.olasentra.staff.core.network.dto.DashboardResponse
import com.olasentra.staff.core.network.dto.ShiftObjectDto
import com.olasentra.staff.core.network.dto.StaffApprovalDto
import com.olasentra.staff.core.network.dto.StaffDocumentSummaryItemDto
import com.olasentra.staff.core.network.dto.StaffProfileDto
import com.olasentra.staff.core.util.formatStaffRoleLabel
import com.olasentra.staff.domain.model.DashboardSummary
import com.olasentra.staff.domain.model.ProfileDocumentItem
import com.olasentra.staff.domain.model.StaffProfile
import com.olasentra.staff.domain.model.UpcomingShiftSummary
import javax.inject.Inject

class ProfileMapper @Inject constructor() {

    fun toStaffProfile(dto: StaffProfileDto): StaffProfile {
        val id = dto.id ?: throw IllegalStateException("Missing staff id in profile response")
        val personal = dto.personal
        val contact = dto.contact
        val displayName = personal?.displayName?.takeIf { it.isNotBlank() }
            ?: listOfNotNull(personal?.firstName, personal?.surname)
                .joinToString(" ")
                .trim()
                .ifBlank { personal?.email.orEmpty() }
        val email = personal?.email?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing staff email in profile response")
        val approval = dto.approval
        val profileMeta = dto.profile

        return StaffProfile(
            id = id,
            displayName = displayName,
            email = email,
            phone = contact?.mobile?.takeIf { it.isNotBlank() } ?: "—",
            address = contact?.fullAddress?.takeIf { it.isNotBlank() } ?: "—",
            eircode = contact?.eircode?.takeIf { it.isNotBlank() } ?: "—",
            staffRole = formatStaffRoleLabel(personal?.staffRole),
            approvalLabel = formatApprovalLabel(approval),
            approvalDetail = formatApprovalDetail(approval),
            documentItems = dto.documents?.items.orEmpty().map(::toProfileDocumentItem),
            profileComplete = profileMeta?.profileComplete == true,
            canEditLimitedFields = profileMeta?.canEditLimitedFields == true,
            mustUseWebProfile = profileMeta?.mustUseWebProfile == true,
        )
    }

    fun toStaffProfileFromDashboard(response: DashboardResponse): StaffProfile {
        val profile = response.profile
            ?: throw IllegalStateException("Missing profile in dashboard response")
        return toStaffProfile(profile)
    }

    private fun toProfileDocumentItem(dto: StaffDocumentSummaryItemDto): ProfileDocumentItem {
        return ProfileDocumentItem(
            label = dto.label?.takeIf { it.isNotBlank() } ?: "Document",
            status = dto.status?.takeIf { it.isNotBlank() } ?: "valid",
            expiry = dto.expiry?.takeIf { it.isNotBlank() },
            approvalStatus = dto.approvalStatus?.takeIf { it.isNotBlank() },
            hasFile = dto.hasFile == true,
        )
    }
}

class DashboardMapper @Inject constructor(
    private val profileMapper: ProfileMapper,
) {

    fun toDashboardSummary(response: DashboardResponse): DashboardSummary {
        val profile = response.profile
            ?: throw IllegalStateException("Missing profile in dashboard response")
        val personal = profile.personal
        val displayName = personal?.displayName?.takeIf { it.isNotBlank() }
            ?: listOfNotNull(personal?.firstName, personal?.surname)
                .joinToString(" ")
                .trim()
                .ifBlank { "Staff" }
        val approvalStatus = response.approvalStatus
        val unread = response.unread

        return DashboardSummary(
            staffDisplayName = displayName,
            approvalLabel = formatDashboardApprovalLabel(approvalStatus),
            approvalDetail = formatDashboardApprovalDetail(approvalStatus),
            upcomingShifts = response.upcomingShifts.orEmpty().map(::toUpcomingShift),
            unreadMessages = unread?.messages ?: 0,
            unreadNotifications = unread?.notifications ?: 0,
            availableEventsCount = response.availableEventsCount ?: 0,
        )
    }

    private fun toUpcomingShift(dto: ShiftObjectDto): UpcomingShiftSummary {
        return UpcomingShiftSummary(
            eventName = dto.eventName?.takeIf { it.isNotBlank() } ?: "Event",
            eventDate = dto.eventDate?.takeIf { it.isNotBlank() } ?: "—",
            venueName = dto.venue?.name?.takeIf { it.isNotBlank() } ?: "—",
            shiftStatus = dto.shiftStatus?.takeIf { it.isNotBlank() } ?: "—",
        )
    }
}

private fun formatApprovalLabel(approval: StaffApprovalDto?): String {
    if (approval == null || approval.hasRegistrations != true) {
        return "No registrations"
    }
    val approved = approval.approved ?: 0
    val pending = approval.pending ?: 0
    return when {
        approved > 0 && pending == 0 -> "Approved"
        pending > 0 -> "Pending approval"
        approved > 0 -> "Mixed status"
        else -> "Not approved"
    }
}

private fun formatApprovalDetail(approval: StaffApprovalDto?): String {
    if (approval == null) {
        return "No registration data"
    }
    return buildString {
        append("${approval.approved ?: 0} approved")
        append(" · ${approval.pending ?: 0} pending")
        append(" · ${approval.rejected ?: 0} rejected")
    }
}

private fun formatDashboardApprovalLabel(status: DashboardApprovalStatusDto?): String {
    val overall = status?.overall?.lowercase()
    return when (overall) {
        "approved" -> "Approved"
        "pending" -> "Pending approval"
        "mixed" -> "Mixed status"
        "no_registrations", null -> "No registrations"
        else -> overall.replace('_', ' ').replaceFirstChar { it.uppercase() }
    }
}

private fun formatDashboardApprovalDetail(status: DashboardApprovalStatusDto?): String {
    if (status == null) {
        return "No registration data"
    }
    return buildString {
        append("${status.approved ?: 0} approved")
        append(" · ${status.pending ?: 0} pending")
        append(" · ${status.rejected ?: 0} rejected")
    }
}
