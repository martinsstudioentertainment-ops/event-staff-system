package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.AuthSession
import kotlinx.coroutines.flow.Flow

interface AuthRepository {
    suspend fun loginWithGoogle(idToken: String): AuthSession

    suspend fun sendLoginOtp(email: String)

    suspend fun verifyLoginOtp(email: String, code: String): AuthSession

    suspend fun refreshSession(): AuthSession

    suspend fun logout()

    fun observeSession(): Flow<AuthSession?>
}
