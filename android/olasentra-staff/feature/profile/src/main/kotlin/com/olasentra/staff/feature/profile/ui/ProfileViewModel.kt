package com.olasentra.staff.feature.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.StaffProfile
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.ProfileRepository
import com.olasentra.staff.domain.usecase.LogoutUseCase
import com.olasentra.staff.domain.usecase.ObserveProfileUseCase
import com.olasentra.staff.domain.usecase.RefreshProfileUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class ProfileViewModel @Inject constructor(
    profileRepository: ProfileRepository,
    authRepository: AuthRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val observeProfile = ObserveProfileUseCase(profileRepository)
    private val refreshProfile = RefreshProfileUseCase(profileRepository)
    private val logoutUseCase = LogoutUseCase(authRepository)

    private val _uiState = MutableStateFlow(ProfileUiState(isInitialLoading = true))
    val uiState: StateFlow<ProfileUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            observeProfile().collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        profile = resource.data,
                        lastSyncedAtEpochMs = resource.lastSyncedAtEpochMs,
                        isRefreshing = resource.isRefreshing,
                        errorMessage = if (resource.data == null) resource.errorMessage else null,
                        isInitialLoading = resource.data == null && resource.isRefreshing,
                        showOfflineBanner = resource.isFromCache && resource.errorMessage != null,
                    )
                }
            }
        }

        refresh()
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            refreshProfile()
        }
    }

    fun logout() {
        if (_uiState.value.isLoggingOut) {
            return
        }

        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isLoggingOut = true) }
            logoutUseCase()
            _uiState.update { it.copy(isLoggingOut = false, signedOut = true) }
        }
    }
}

data class ProfileUiState(
    val profile: StaffProfile? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val isLoggingOut: Boolean = false,
    val signedOut: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
