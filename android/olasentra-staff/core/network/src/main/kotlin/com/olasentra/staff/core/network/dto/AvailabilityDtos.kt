package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class AvailabilityDayDto(
    val date: String? = null,
    val status: String? = null,
    @Json(name = "approval_status") val approvalStatus: String? = null,
    val notes: String? = null,
    @Json(name = "admin_approved") val adminApproved: Boolean? = null,
    @Json(name = "updated_at") val updatedAt: String? = null,
)

@JsonClass(generateAdapter = true)
data class AvailabilityResponse(
    val ok: Boolean? = null,
    val month: String? = null,
    val days: List<AvailabilityDayDto>? = null,
    val statuses: List<String>? = null,
)

@JsonClass(generateAdapter = true)
data class AvailabilitySetRequest(
    val status: String,
    val notes: String? = null,
)

@JsonClass(generateAdapter = true)
data class AvailabilitySetResponse(
    val ok: Boolean? = null,
    val message: String? = null,
    val day: AvailabilityDayDto? = null,
)

@JsonClass(generateAdapter = true)
data class LeaveRequest(
    val date: String,
    val type: String,
    val notes: String? = null,
)

@JsonClass(generateAdapter = true)
data class LeaveResponse(
    val ok: Boolean? = null,
    val message: String? = null,
    val day: AvailabilityDayDto? = null,
)
