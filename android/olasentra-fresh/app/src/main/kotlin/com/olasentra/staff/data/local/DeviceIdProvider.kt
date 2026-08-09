package com.olasentra.staff.data.local

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import java.security.SecureRandom
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

@Singleton
class DeviceIdProvider @Inject constructor(
    private val dataStore: DataStore<Preferences>,
) {
    suspend fun getDeviceId(): String {
        val existing = dataStore.data
            .map { preferences -> preferences[KEY_DEVICE_ID] }
            .first()

        if (!existing.isNullOrBlank() && isValidDeviceId(existing)) {
            return existing
        }

        val generated = generateDeviceId()
        dataStore.edit { preferences ->
            preferences[KEY_DEVICE_ID] = generated
        }
        return generated
    }

    private fun generateDeviceId(): String {
        val random = SecureRandom()
        val chars = CharArray(DEVICE_ID_LENGTH) {
            ALPHANUMERIC[random.nextInt(ALPHANUMERIC.length)]
        }
        return String(chars)
    }

    private fun isValidDeviceId(value: String): Boolean {
        if (value.length !in MIN_DEVICE_ID_LENGTH..MAX_DEVICE_ID_LENGTH) {
            return false
        }
        return value.all { character -> character in ALPHANUMERIC }
    }

    companion object {
        val KEY_DEVICE_ID = stringPreferencesKey("device_id")
        const val MIN_DEVICE_ID_LENGTH = 8
        const val MAX_DEVICE_ID_LENGTH = 64
        const val DEVICE_ID_LENGTH = 32
        const val ALPHANUMERIC = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
    }
}
