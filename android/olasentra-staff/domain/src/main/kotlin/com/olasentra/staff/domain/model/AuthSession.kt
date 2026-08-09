package com.olasentra.staff.domain.model

data class AuthSession(
    val accessToken: String,
    val refreshToken: String,
    val expiresInSeconds: Long,
    val staff: StaffSummary,
)
