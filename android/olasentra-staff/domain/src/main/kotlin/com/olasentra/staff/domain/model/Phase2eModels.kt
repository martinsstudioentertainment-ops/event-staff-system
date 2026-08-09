package com.olasentra.staff.domain.model

data class StaffDocument(
    val key: String,
    val label: String,
    val category: String,
    val type: String,
    val expiry: String?,
    val status: String,
    val approvalStatus: String,
    val hasFile: Boolean,
    val licenceNumber: String?,
)

data class DocumentsSummary(
    val total: Int,
    val valid: Int,
    val expiring: Int,
    val expired: Int,
    val missing: Int,
)

data class DocumentsOverview(
    val documents: List<StaffDocument>,
    val summary: DocumentsSummary,
)

data class AvailabilityDay(
    val date: String,
    val status: String,
    val approvalStatus: String,
    val notes: String,
    val adminApproved: Boolean,
    val updatedAt: String,
)

data class AvailabilityOverview(
    val month: String,
    val days: List<AvailabilityDay>,
    val settableStatuses: List<String>,
)

data class AvailabilityActionResult(
    val success: Boolean,
    val message: String,
    val day: AvailabilityDay? = null,
    val queuedOffline: Boolean = false,
)

data class LeaveActionResult(
    val success: Boolean,
    val message: String,
    val day: AvailabilityDay? = null,
    val queuedOffline: Boolean = false,
)

data class DocumentFileResult(
    val success: Boolean,
    val localFilePath: String? = null,
    val mimeType: String? = null,
    val message: String? = null,
)

data class PushRegistrationResult(
    val success: Boolean,
    val message: String? = null,
)
