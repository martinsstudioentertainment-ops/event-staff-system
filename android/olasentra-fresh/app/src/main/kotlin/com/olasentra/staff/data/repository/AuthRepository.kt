package com.olasentra.staff.data.repository

import com.olasentra.staff.data.local.DeviceIdProvider
import com.olasentra.staff.data.local.TokenStorage
import com.olasentra.staff.data.model.AuthSession
import com.olasentra.staff.data.model.StaffSummary
import com.olasentra.staff.data.remote.ApiCallHandler
import com.olasentra.staff.data.remote.ApiResult
import com.olasentra.staff.data.remote.MobileApiService
import com.olasentra.staff.data.remote.dto.AuthOtpSendRequest
import com.olasentra.staff.data.remote.dto.AuthOtpVerifyRequest
import com.olasentra.staff.data.remote.dto.AuthSuccessResponse
import com.olasentra.staff.data.remote.dto.DashboardResponse
import com.olasentra.staff.data.remote.dto.StaffSummaryDto
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

class AuthException(
    message: String,
    val httpCode: Int? = null,
    val errorCode: String? = null,
) : Exception(message)

@Singleton
class AuthRepository @Inject constructor(
    private val api: MobileApiService,
    private val tokenStorage: TokenStorage,
    private val deviceIdProvider: DeviceIdProvider,
    private val apiCallHandler: ApiCallHandler,
) {
    private val sessionState = MutableStateFlow<AuthSession?>(null)

    fun observeSession(): Flow<AuthSession?> = sessionState.asStateFlow()

    suspend fun hydrateSessionFromStorage(): AuthSession? {
        if (!tokenStorage.hasValidSession()) {
            sessionState.value = null
            return null
        }

        val accessToken = tokenStorage.getAccessToken() ?: return null
        val refreshToken = tokenStorage.getRefreshToken() ?: return null
        val staffId = tokenStorage.getStaffId() ?: return null
        val staffEmail = tokenStorage.getStaffEmail() ?: return null
        val firstName = tokenStorage.getStaffFirstName()
        val surname = tokenStorage.getStaffSurname()

        val session = AuthSession(
            accessToken = accessToken,
            refreshToken = refreshToken,
            expiresInSeconds = 0,
            staff = StaffSummary(
                id = staffId,
                email = staffEmail,
                firstName = firstName,
                surname = surname,
                displayName = buildDisplayName(firstName, surname, staffEmail),
            ),
        )
        sessionState.value = session
        return session
    }

    suspend fun sendLoginOtp(email: String) {
        val normalizedEmail = email.trim().lowercase()
        val deviceId = deviceIdProvider.getDeviceId()
        when (val result = apiCallHandler.safeApiCall {
            api.authOtpSend(
                AuthOtpSendRequest(
                    email = normalizedEmail,
                    deviceId = deviceId,
                ),
            )
        }) {
            is ApiResult.Success -> {
                if (result.data.ok == false) {
                    throw AuthException("Could not send verification code.")
                }
            }
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.httpCode,
                errorCode = result.errorCode,
            )
        }
    }

    suspend fun verifyLoginOtp(email: String, code: String): AuthSession {
        val normalizedEmail = email.trim().lowercase()
        val normalizedCode = code.trim()
        val deviceId = deviceIdProvider.getDeviceId()

        val session = when (val result = apiCallHandler.safeApiCall {
            api.authOtpVerify(
                AuthOtpVerifyRequest(
                    email = normalizedEmail,
                    code = normalizedCode,
                    deviceId = deviceId,
                ),
            )
        }) {
            is ApiResult.Success -> mapAuthResponse(result.data)
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.httpCode,
                errorCode = result.errorCode,
            )
        }

        persistSession(session)
        sessionState.value = session
        return session
    }

    suspend fun logout() {
        tokenStorage.clearSession()
        sessionState.value = null
    }

    suspend fun fetchDashboard(): DashboardResponse {
        return when (val result = apiCallHandler.safeApiCall { api.getDashboard() }) {
            is ApiResult.Success -> result.data
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.httpCode,
                errorCode = result.errorCode,
            )
        }
    }

    private suspend fun persistSession(session: AuthSession) {
        val expiresAtEpochSeconds = System.currentTimeMillis() / 1000 + session.expiresInSeconds
        tokenStorage.saveSession(
            accessToken = session.accessToken,
            refreshToken = session.refreshToken,
            expiresAtEpochSeconds = expiresAtEpochSeconds,
            staffId = session.staff.id,
            staffEmail = session.staff.email,
            staffFirstName = session.staff.firstName,
            staffSurname = session.staff.surname,
        )
    }

    private fun mapAuthResponse(response: AuthSuccessResponse): AuthSession {
        val accessToken = response.accessToken?.takeIf { it.isNotBlank() }
            ?: throw AuthException("Missing access token.")
        val refreshToken = response.refreshToken?.takeIf { it.isNotBlank() }
            ?: throw AuthException("Missing refresh token.")
        val staff = mapStaff(response.staff)
        return AuthSession(
            accessToken = accessToken,
            refreshToken = refreshToken,
            expiresInSeconds = response.expiresIn ?: 3600,
            staff = staff,
        )
    }

    private fun mapStaff(dto: StaffSummaryDto?): StaffSummary {
        val id = dto?.id ?: throw AuthException("Missing staff profile.")
        val email = dto.email?.takeIf { it.isNotBlank() } ?: throw AuthException("Missing staff email.")
        return StaffSummary(
            id = id,
            email = email,
            firstName = dto.firstName,
            surname = dto.surname,
            displayName = buildDisplayName(dto.firstName, dto.surname, email),
        )
    }

    private fun buildDisplayName(firstName: String?, surname: String?, email: String): String {
        val parts = listOfNotNull(
            firstName?.trim()?.takeIf { it.isNotEmpty() },
            surname?.trim()?.takeIf { it.isNotEmpty() },
        )
        return if (parts.isNotEmpty()) parts.joinToString(" ") else email
    }
}
