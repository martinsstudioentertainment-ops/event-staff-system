package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class DocumentItemDto(
    val key: String? = null,
    val label: String? = null,
    val category: String? = null,
    val type: String? = null,
    val expiry: String? = null,
    val status: String? = null,
    @Json(name = "approval_status") val approvalStatus: String? = null,
    @Json(name = "has_file") val hasFile: Boolean? = null,
    @Json(name = "view_url") val viewUrl: String? = null,
    @Json(name = "licence_number") val licenceNumber: String? = null,
)

@JsonClass(generateAdapter = true)
data class DocumentsSummaryDto(
    val total: Int? = null,
    val valid: Int? = null,
    val expiring: Int? = null,
    val expired: Int? = null,
    val missing: Int? = null,
)

@JsonClass(generateAdapter = true)
data class DocumentsListResponse(
    val ok: Boolean? = null,
    val documents: List<DocumentItemDto>? = null,
    val summary: DocumentsSummaryDto? = null,
)
