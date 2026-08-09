package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class StaffPersonalDto(
    @Json(name = "first_name") val firstName: String? = null,
    val surname: String? = null,
    @Json(name = "display_name") val displayName: String? = null,
    val email: String? = null,
    @Json(name = "date_of_birth") val dateOfBirth: String? = null,
    val gender: String? = null,
    @Json(name = "staff_role") val staffRole: String? = null,
    @Json(name = "pps_masked") val ppsMasked: String? = null,
)

@JsonClass(generateAdapter = true)
data class StaffContactDto(
    val mobile: String? = null,
    @Json(name = "full_address") val fullAddress: String? = null,
    val eircode: String? = null,
    @Json(name = "location_lat") val locationLat: Double? = null,
    @Json(name = "location_lng") val locationLng: Double? = null,
)

@JsonClass(generateAdapter = true)
data class StaffApprovalDto(
    @Json(name = "total_registrations") val totalRegistrations: Int? = null,
    val approved: Int? = null,
    val pending: Int? = null,
    val rejected: Int? = null,
    @Json(name = "upcoming_shifts") val upcomingShifts: Int? = null,
    @Json(name = "completed_shifts") val completedShifts: Int? = null,
    @Json(name = "has_registrations") val hasRegistrations: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class StaffDocumentSummaryItemDto(
    val label: String? = null,
    val expiry: String? = null,
    val status: String? = null,
    @Json(name = "approval_status") val approvalStatus: String? = null,
    @Json(name = "has_file") val hasFile: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class StaffDocumentsSummaryDto(
    val total: Int? = null,
    val valid: Int? = null,
    val expiring: Int? = null,
    val expired: Int? = null,
    val items: List<StaffDocumentSummaryItemDto>? = null,
)

@JsonClass(generateAdapter = true)
data class StaffProfileMetaDto(
    @Json(name = "profile_complete") val profileComplete: Boolean? = null,
    @Json(name = "profile_gate_blocked") val profileGateBlocked: Boolean? = null,
    @Json(name = "can_edit_limited_fields") val canEditLimitedFields: Boolean? = null,
    @Json(name = "must_use_web_profile") val mustUseWebProfile: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class StaffProfileDto(
    val id: Long? = null,
    val personal: StaffPersonalDto? = null,
    val contact: StaffContactDto? = null,
    val approval: StaffApprovalDto? = null,
    val documents: StaffDocumentsSummaryDto? = null,
    val profile: StaffProfileMetaDto? = null,
)

@JsonClass(generateAdapter = true)
data class DashboardApprovalStatusDto(
    val approved: Int? = null,
    val pending: Int? = null,
    val rejected: Int? = null,
    @Json(name = "upcoming_shifts") val upcomingShifts: Int? = null,
    val total: Int? = null,
    val overall: String? = null,
)

@JsonClass(generateAdapter = true)
data class DashboardUnreadDto(
    val messages: Int? = null,
    val notifications: Int? = null,
)

@JsonClass(generateAdapter = true)
data class ShiftVenueDto(
    val name: String? = null,
    val eircode: String? = null,
)
