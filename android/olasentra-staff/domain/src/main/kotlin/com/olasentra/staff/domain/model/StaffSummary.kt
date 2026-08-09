package com.olasentra.staff.domain.model

data class StaffSummary(
    val id: Long,
    val email: String,
    val firstName: String?,
    val surname: String?,
    val profileComplete: Boolean,
    val profileReverifyRequired: Boolean,
    val profileGateBlocked: Boolean,
)
