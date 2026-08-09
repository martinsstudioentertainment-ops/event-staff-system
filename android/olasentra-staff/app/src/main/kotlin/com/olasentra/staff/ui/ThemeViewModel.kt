package com.olasentra.staff.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.preferences.ThemePreferenceStore
import com.olasentra.staff.core.util.DispatcherProvider
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

@HiltViewModel
class ThemeViewModel @Inject constructor(
    private val themePreferenceStore: ThemePreferenceStore,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    val darkThemeEnabled = themePreferenceStore.darkThemeEnabled.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = false,
    )

    fun setDarkThemeEnabled(enabled: Boolean) {
        viewModelScope.launch(dispatchers.io) {
            themePreferenceStore.setDarkThemeEnabled(enabled)
        }
    }
}
