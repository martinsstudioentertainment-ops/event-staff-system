package com.olasentra.staff.core.preferences

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

@Singleton
class FcmTokenStore @Inject constructor(
    private val dataStore: DataStore<Preferences>,
) {
    suspend fun savePendingToken(token: String) {
        dataStore.edit { preferences ->
            preferences[KEY_PENDING_FCM_TOKEN] = token
        }
    }

    suspend fun getPendingToken(): String? {
        return dataStore.data
            .map { preferences -> preferences[KEY_PENDING_FCM_TOKEN] }
            .first()
            ?.takeIf { it.isNotBlank() }
    }

    suspend fun clearPendingToken() {
        dataStore.edit { preferences ->
            preferences.remove(KEY_PENDING_FCM_TOKEN)
        }
    }

    suspend fun saveRegisteredToken(token: String) {
        dataStore.edit { preferences ->
            preferences[KEY_REGISTERED_FCM_TOKEN] = token
        }
    }

    suspend fun getRegisteredToken(): String? {
        return dataStore.data
            .map { preferences -> preferences[KEY_REGISTERED_FCM_TOKEN] }
            .first()
            ?.takeIf { it.isNotBlank() }
    }

    suspend fun clearRegisteredToken() {
        dataStore.edit { preferences ->
            preferences.remove(KEY_REGISTERED_FCM_TOKEN)
            preferences.remove(KEY_PENDING_FCM_TOKEN)
        }
    }

    companion object {
        private val KEY_PENDING_FCM_TOKEN = stringPreferencesKey("pending_fcm_token")
        private val KEY_REGISTERED_FCM_TOKEN = stringPreferencesKey("registered_fcm_token")
    }
}
