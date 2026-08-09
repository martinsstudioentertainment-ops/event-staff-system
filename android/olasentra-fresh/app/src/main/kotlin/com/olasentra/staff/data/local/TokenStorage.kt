package com.olasentra.staff.data.local

interface TokenStorage {
    suspend fun saveSession(
        accessToken: String,
        refreshToken: String,
        expiresAtEpochSeconds: Long,
        staffId: Long,
        staffEmail: String,
        staffFirstName: String?,
        staffSurname: String?,
    )

    suspend fun getAccessToken(): String?

    suspend fun getRefreshToken(): String?

    suspend fun getStaffId(): Long?

    suspend fun getStaffEmail(): String?

    suspend fun getStaffFirstName(): String?

    suspend fun getStaffSurname(): String?

    suspend fun clearSession()

    suspend fun hasValidSession(): Boolean
}
