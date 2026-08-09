package com.olasentra.staff.core.preferences

import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

@Singleton
class ThemePreferenceStore @Inject constructor(
    private val dataStore: DataStore<Preferences>,
) {
    val darkThemeEnabled: Flow<Boolean> = dataStore.data.map { preferences ->
        preferences[KEY_DARK_THEME] ?: false
    }

    suspend fun setDarkThemeEnabled(enabled: Boolean) {
        dataStore.edit { preferences ->
            preferences[KEY_DARK_THEME] = enabled
        }
    }

    companion object {
        private val KEY_DARK_THEME = booleanPreferencesKey("dark_theme_enabled")
    }
}
