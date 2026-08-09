package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.AuthGoogleRequest
import com.olasentra.staff.core.network.dto.AuthLogoutRequest
import com.olasentra.staff.core.network.dto.AuthOtpSendRequest
import com.olasentra.staff.core.network.dto.AuthOtpVerifyRequest
import com.olasentra.staff.core.network.dto.AuthRefreshRequest
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.preferences.DeviceIdProvider
import com.olasentra.staff.core.security.TokenStorage
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.data.remote.mapper.AuthMapper
import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.model.StaffSummary
import com.olasentra.staff.domain.repository.AuthException
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.PushRepository
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.onSubscription
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

@Singleton
class AuthRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val tokenStorage: TokenStorage,
    private val deviceIdProvider: DeviceIdProvider,
    private val apiCallHandler: ApiCallHandler,
    private val authMapper: AuthMapper,
    private val apiCacheDao: ApiCacheDao,
    private val pushRepository: PushRepository,
) : AuthRepository {

    private val sessionState = MutableStateFlow<AuthSession?>(null)
    private val sessionMutex = Mutex()
    private var storageHydrated = false

    override suspend fun loginWithGoogle(idToken: String): AuthSession {
        val deviceId = deviceIdProvider.getDeviceId()
        val result = apiCallHandler.safeApiCall {
            api.authGoogle(
                AuthGoogleRequest(
                    idToken = idToken,
                    deviceId = deviceId,
                ),
            )
        }

        val session = when (result) {
            is ApiResult.Success -> authMapper.toAuthSession(result.data)
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.code,
                errorCode = result.errorCode,
            )
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }

        persistSession(session)
        sessionState.value = session
        pushRepository.registerPendingTokenIfNeeded()
        return session
    }

    override suspend fun sendLoginOtp(email: String) {
        val normalizedEmail = email.trim().lowercase()
        val deviceId = deviceIdProvider.getDeviceId()
        val result = apiCallHandler.safeApiCall {
            api.authOtpSend(
                AuthOtpSendRequest(
                    email = normalizedEmail,
                    deviceId = deviceId,
                ),
            )
        }

        when (result) {
            is ApiResult.Success -> {
                if (result.data.ok == false) {
                    throw AuthException("Could not send verification code.")
                }
            }
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.code,
                errorCode = result.errorCode,
            )
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }
    }

    override suspend fun verifyLoginOtp(email: String, code: String): AuthSession {
        val normalizedEmail = email.trim().lowercase()
        val normalizedCode = code.trim()
        val deviceId = deviceIdProvider.getDeviceId()
        val result = apiCallHandler.safeApiCall {
            api.authOtpVerify(
                AuthOtpVerifyRequest(
                    email = normalizedEmail,
                    code = normalizedCode,
                    deviceId = deviceId,
                ),
            )
        }

        val session = when (result) {
            is ApiResult.Success -> authMapper.toAuthSession(result.data)
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.code,
                errorCode = result.errorCode,
            )
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }

        persistSession(session)
        sessionState.value = session
        pushRepository.registerPendingTokenIfNeeded()
        return session
    }

    override suspend fun refreshSession(): AuthSession {
        val refreshToken = tokenStorage.getRefreshToken()
            ?: throw AuthException("No refresh token available", httpCode = 401)
        val deviceId = deviceIdProvider.getDeviceId()
        val existingStaff = sessionState.value?.staff ?: loadStaffSummaryFromStorage()
            ?: throw AuthException("No active session to refresh", httpCode = 401)

        val result = apiCallHandler.safeApiCall {
            api.authRefresh(
                AuthRefreshRequest(
                    refreshToken = refreshToken,
                    deviceId = deviceId,
                ),
            )
        }

        val session = when (result) {
            is ApiResult.Success -> authMapper.toAuthSession(result.data, existingStaff)
            is ApiResult.Error -> throw AuthException(
                message = result.message,
                httpCode = result.code,
                errorCode = result.errorCode,
            )
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }

        persistSession(session)
        sessionState.value = session
        pushRepository.registerPendingTokenIfNeeded()
        return session
    }

    override suspend fun logout() {
        val refreshToken = tokenStorage.getRefreshToken()
        val deviceId = runCatching { deviceIdProvider.getDeviceIdOrNull() }.getOrNull()

        if (!refreshToken.isNullOrBlank()) {
            apiCallHandler.safeApiCall {
                api.authLogout(
                    AuthLogoutRequest(
                        refreshToken = refreshToken,
                        deviceId = deviceId,
                    ),
                )
            }
        }

        pushRepository.unregisterCurrentDevice()
        tokenStorage.clearSession()
        apiCacheDao.deleteAll()
        sessionState.value = null
        storageHydrated = true
    }

    override fun observeSession(): Flow<AuthSession?> {
        return sessionState.onSubscription {
            sessionMutex.withLock {
                if (!storageHydrated && sessionState.value == null) {
                    sessionState.value = loadSessionFromStorage()
                    storageHydrated = true
                }
            }
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
        )
        storageHydrated = true
    }

    private suspend fun loadSessionFromStorage(): AuthSession? {
        if (!tokenStorage.hasValidSession()) {
            return null
        }

        val accessToken = tokenStorage.getAccessToken() ?: return null
        val refreshToken = tokenStorage.getRefreshToken() ?: return null
        val expiresAtEpochSeconds = tokenStorage.getExpiresAtEpochSeconds() ?: return null
        val staff = loadStaffSummaryFromStorage() ?: return null
        val expiresInSeconds = (expiresAtEpochSeconds - System.currentTimeMillis() / 1000).coerceAtLeast(0)

        return AuthSession(
            accessToken = accessToken,
            refreshToken = refreshToken,
            expiresInSeconds = expiresInSeconds,
            staff = staff,
        )
    }

    private suspend fun loadStaffSummaryFromStorage(): StaffSummary? {
        val staffId = tokenStorage.getStaffId() ?: return null
        val staffEmail = tokenStorage.getStaffEmail() ?: return null

        return StaffSummary(
            id = staffId,
            email = staffEmail,
            firstName = null,
            surname = null,
            profileComplete = false,
            profileReverifyRequired = false,
            profileGateBlocked = false,
        )
    }
}
