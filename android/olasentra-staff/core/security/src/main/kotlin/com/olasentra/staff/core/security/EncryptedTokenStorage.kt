package com.olasentra.staff.core.security

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

@Singleton
class EncryptedTokenStorage @Inject constructor(
    @ApplicationContext context: Context,
) : TokenStorage {

    private val preferences: SharedPreferences = createEncryptedPreferences(context)

    override suspend fun saveAccessToken(token: String) = withContext(Dispatchers.IO) {
        preferences.edit().putString(KEY_ACCESS_TOKEN, token).apply()
    }

    override suspend fun getAccessToken(): String? = withContext(Dispatchers.IO) {
        preferences.getString(KEY_ACCESS_TOKEN, null)
    }

    override suspend fun saveRefreshToken(token: String) = withContext(Dispatchers.IO) {
        preferences.edit().putString(KEY_REFRESH_TOKEN, token).apply()
    }

    override suspend fun getRefreshToken(): String? = withContext(Dispatchers.IO) {
        preferences.getString(KEY_REFRESH_TOKEN, null)
    }

    override suspend fun saveExpiresAtEpochSeconds(epochSeconds: Long) = withContext(Dispatchers.IO) {
        preferences.edit().putLong(KEY_EXPIRES_AT_EPOCH_SECONDS, epochSeconds).apply()
    }

    override suspend fun getExpiresAtEpochSeconds(): Long? = withContext(Dispatchers.IO) {
        preferences.getLong(KEY_EXPIRES_AT_EPOCH_SECONDS, INVALID_LONG)
            .takeIf { it != INVALID_LONG }
    }

    override suspend fun saveStaffId(staffId: Long) = withContext(Dispatchers.IO) {
        preferences.edit().putLong(KEY_STAFF_ID, staffId).apply()
    }

    override suspend fun getStaffId(): Long? = withContext(Dispatchers.IO) {
        preferences.getLong(KEY_STAFF_ID, INVALID_LONG)
            .takeIf { it != INVALID_LONG }
    }

    override suspend fun saveStaffEmail(email: String) = withContext(Dispatchers.IO) {
        preferences.edit().putString(KEY_STAFF_EMAIL, email).apply()
    }

    override suspend fun getStaffEmail(): String? = withContext(Dispatchers.IO) {
        preferences.getString(KEY_STAFF_EMAIL, null)
    }

    override suspend fun saveSession(
        accessToken: String,
        refreshToken: String,
        expiresAtEpochSeconds: Long,
        staffId: Long,
        staffEmail: String,
    ) = withContext(Dispatchers.IO) {
        preferences.edit()
            .putString(KEY_ACCESS_TOKEN, accessToken)
            .putString(KEY_REFRESH_TOKEN, refreshToken)
            .putLong(KEY_EXPIRES_AT_EPOCH_SECONDS, expiresAtEpochSeconds)
            .putLong(KEY_STAFF_ID, staffId)
            .putString(KEY_STAFF_EMAIL, staffEmail)
            .apply()
    }

    override suspend fun clearSession() = withContext(Dispatchers.IO) {
        preferences.edit().clear().apply()
    }

    override suspend fun hasValidSession(): Boolean = withContext(Dispatchers.IO) {
        val accessToken = preferences.getString(KEY_ACCESS_TOKEN, null)
        val refreshToken = preferences.getString(KEY_REFRESH_TOKEN, null)
        val staffId = preferences.getLong(KEY_STAFF_ID, INVALID_LONG)
        !accessToken.isNullOrBlank() &&
            !refreshToken.isNullOrBlank() &&
            staffId != INVALID_LONG
    }

    private fun createEncryptedPreferences(context: Context): SharedPreferences {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        return EncryptedSharedPreferences.create(
            context,
            PREFS_FILE_NAME,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    private companion object {
        const val PREFS_FILE_NAME = "olasentra_staff_secure_tokens"
        const val KEY_ACCESS_TOKEN = "access_token"
        const val KEY_REFRESH_TOKEN = "refresh_token"
        const val KEY_EXPIRES_AT_EPOCH_SECONDS = "expires_at_epoch_seconds"
        const val KEY_STAFF_ID = "staff_id"
        const val KEY_STAFF_EMAIL = "staff_email"
        const val INVALID_LONG = -1L
    }
}
