package com.olasentra.staff.core.network

import com.olasentra.staff.core.network.dto.AuthRefreshRequest
import com.olasentra.staff.core.network.dto.TokenResponse
import com.olasentra.staff.core.preferences.DeviceIdProvider
import com.olasentra.staff.core.security.SessionEventPublisher
import com.olasentra.staff.core.security.TokenStorage
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton
import kotlinx.coroutines.runBlocking
import okhttp3.Authenticator
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.Route

@Singleton
class TokenAuthenticator @Inject constructor(
    private val tokenStorage: TokenStorage,
    private val deviceIdProvider: DeviceIdProvider,
    private val moshi: Moshi,
    private val sessionEventPublisher: SessionEventPublisher,
    @MobileApi private val baseUrl: String,
    @Named("auth_refresh") private val refreshClient: OkHttpClient,
) : Authenticator {

    private val refreshLock = Any()

    override fun authenticate(route: Route?, response: Response): Request? {
        if (responseCount(response) >= 2) {
            return null
        }

        synchronized(refreshLock) {
            val requestToken = response.request.header("Authorization")
                ?.removePrefix("Bearer ")
                ?.trim()

            val currentToken = runBlocking { tokenStorage.getAccessToken() }
            if (!currentToken.isNullOrBlank() && currentToken != requestToken) {
                return response.request.newBuilder()
                    .header("Authorization", "Bearer $currentToken")
                    .build()
            }

            val refreshToken = runBlocking { tokenStorage.getRefreshToken() }
            if (refreshToken.isNullOrBlank()) {
                runBlocking { tokenStorage.clearSession() }
                sessionEventPublisher.publishSessionExpired()
                return null
            }

            val deviceId = runBlocking { deviceIdProvider.getDeviceId() }
            val refreshResponse = performRefresh(refreshToken, deviceId)
            if (!refreshResponse.isSuccessful) {
                runBlocking { tokenStorage.clearSession() }
                sessionEventPublisher.publishSessionExpired()
                return null
            }

            val bodyString = refreshResponse.body?.string()
            if (bodyString.isNullOrBlank()) {
                runBlocking { tokenStorage.clearSession() }
                sessionEventPublisher.publishSessionExpired()
                return null
            }

            val tokenResponse = moshi.adapter(TokenResponse::class.java).fromJson(bodyString)
            val accessToken = tokenResponse?.accessToken
            val newRefreshToken = tokenResponse?.refreshToken
            if (accessToken.isNullOrBlank() || newRefreshToken.isNullOrBlank()) {
                runBlocking { tokenStorage.clearSession() }
                sessionEventPublisher.publishSessionExpired()
                return null
            }

            val expiresAtEpochSeconds = System.currentTimeMillis() / 1000 + (tokenResponse.expiresIn ?: 0)
            runBlocking {
                tokenStorage.saveAccessToken(accessToken)
                tokenStorage.saveRefreshToken(newRefreshToken)
                tokenStorage.saveExpiresAtEpochSeconds(expiresAtEpochSeconds)
            }

            return response.request.newBuilder()
                .header("Authorization", "Bearer $accessToken")
                .build()
        }
    }

    private fun performRefresh(refreshToken: String, deviceId: String): Response {
        val requestBody = moshi.adapter(AuthRefreshRequest::class.java)
            .toJson(AuthRefreshRequest(refreshToken = refreshToken, deviceId = deviceId))
            .toRequestBody(JSON_MEDIA_TYPE)

        val request = Request.Builder()
            .url(normalizeBaseUrl(baseUrl) + "auth/refresh")
            .post(requestBody)
            .header("Content-Type", "application/json")
            .build()

        return refreshClient.newCall(request).execute()
    }

    private fun responseCount(response: Response): Int {
        var count = 1
        var prior = response.priorResponse
        while (prior != null) {
            count++
            prior = prior.priorResponse
        }
        return count
    }

    private fun normalizeBaseUrl(url: String): String {
        return if (url.endsWith("/")) url else "$url/"
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
