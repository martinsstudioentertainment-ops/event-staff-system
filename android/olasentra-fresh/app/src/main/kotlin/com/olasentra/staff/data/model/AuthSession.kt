package com.olasentra.staff.data.model

data class StaffSummary(
    val id: Long,
    val email: String,
    val firstName: String? = null,
    val surname: String? = null,
    val displayName: String,
)

data class AuthSession(
    val accessToken: String,
    val refreshToken: String,
    val expiresInSeconds: Int,
    val staff: StaffSummary,
)
