package com.olasentra.staff.core.security

interface TokenStorage {
    suspend fun saveAccessToken(token: String)

    suspend fun getAccessToken(): String?

    suspend fun saveRefreshToken(token: String)

    suspend fun getRefreshToken(): String?

    suspend fun saveExpiresAtEpochSeconds(epochSeconds: Long)

    suspend fun getExpiresAtEpochSeconds(): Long?

    suspend fun saveStaffId(staffId: Long)

    suspend fun getStaffId(): Long?

    suspend fun saveStaffEmail(email: String)

    suspend fun getStaffEmail(): String?

    suspend fun saveSession(
        accessToken: String,
        refreshToken: String,
        expiresAtEpochSeconds: Long,
        staffId: Long,
        staffEmail: String,
    )

    suspend fun clearSession()

    suspend fun hasValidSession(): Boolean
}
