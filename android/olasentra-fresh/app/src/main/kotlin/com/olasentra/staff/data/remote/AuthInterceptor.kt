package com.olasentra.staff.data.remote

import com.olasentra.staff.data.local.TokenStorage
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.runBlocking
import okhttp3.Interceptor
import okhttp3.Response

@Singleton
class AuthInterceptor @Inject constructor(
    private val tokenStorage: TokenStorage,
) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val original = chain.request()
        if (shouldSkipAuth(original.url.encodedPath)) {
            return chain.proceed(original)
        }

        val accessToken = runBlocking { tokenStorage.getAccessToken() }
        if (accessToken.isNullOrBlank()) {
            return chain.proceed(original)
        }

        val authenticated = original.newBuilder()
            .header("Authorization", "Bearer $accessToken")
            .build()

        return chain.proceed(authenticated)
    }

    private fun shouldSkipAuth(encodedPath: String): Boolean {
        return encodedPath.contains("/config") || encodedPath.contains("/auth/")
    }
}
