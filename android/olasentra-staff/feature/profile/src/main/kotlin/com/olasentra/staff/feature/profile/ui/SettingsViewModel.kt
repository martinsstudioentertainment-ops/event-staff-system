package com.olasentra.staff.feature.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.preferences.ThemePreferenceStore
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.ProfileRepository
import com.olasentra.staff.domain.usecase.LogoutUseCase
import com.olasentra.staff.domain.usecase.ObserveProfileUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class SettingsViewModel @Inject constructor(
    profileRepository: ProfileRepository,
    authRepository: AuthRepository,
    private val themePreferenceStore: ThemePreferenceStore,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val observeProfile = ObserveProfileUseCase(profileRepository)
    private val logoutUseCase = LogoutUseCase(authRepository)

    val darkThemeEnabled: StateFlow<Boolean> = themePreferenceStore.darkThemeEnabled.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = false,
    )

    private val _uiState = MutableStateFlow(SettingsUiState())
    val uiState: StateFlow<SettingsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            observeProfile().collect { resource ->
                _uiState.update {
                    it.copy(
                        displayName = resource.data?.displayName,
                        email = resource.data?.email,
                    )
                }
            }
        }
    }

    fun setDarkThemeEnabled(enabled: Boolean) {
        viewModelScope.launch(dispatchers.io) {
            themePreferenceStore.setDarkThemeEnabled(enabled)
        }
    }

    fun signOut() {
        if (_uiState.value.isSigningOut) {
            return
        }

        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isSigningOut = true) }
            logoutUseCase()
            _uiState.update { it.copy(isSigningOut = false, signedOut = true) }
        }
    }
}

data class SettingsUiState(
    val displayName: String? = null,
    val email: String? = null,
    val isSigningOut: Boolean = false,
    val signedOut: Boolean = false,
)
