package com.olasentra.staff.feature.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.ProfileRepository
import com.olasentra.staff.domain.usecase.ObserveProfileUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@HiltViewModel
class EditProfileViewModel @Inject constructor(
    profileRepository: ProfileRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val observeProfile = ObserveProfileUseCase(profileRepository)
    private val profileRepository = profileRepository

    private val _uiState = MutableStateFlow(EditProfileUiState(isInitialLoading = true))
    val uiState: StateFlow<EditProfileUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            observeProfile().collect { resource ->
                val profile = resource.data
                _uiState.update { current ->
                    if (current.hasUserEdited) {
                        current.copy(
                            mustUseWebProfile = profile?.mustUseWebProfile == true,
                            isInitialLoading = profile == null && resource.isRefreshing,
                        )
                    } else {
                        current.copy(
                            mobile = profile?.phone?.takeIf { it != "—" }.orEmpty(),
                            fullAddress = profile?.address?.takeIf { it != "—" }.orEmpty(),
                            eircode = profile?.eircode?.takeIf { it != "—" }.orEmpty(),
                            mustUseWebProfile = profile?.mustUseWebProfile == true,
                            isInitialLoading = profile == null && resource.isRefreshing,
                        )
                    }
                }
            }
        }
    }

    fun updateMobile(mobile: String) {
        _uiState.update { it.copy(mobile = mobile, hasUserEdited = true, errorMessage = null) }
    }

    fun updateFullAddress(fullAddress: String) {
        _uiState.update { it.copy(fullAddress = fullAddress, hasUserEdited = true, errorMessage = null) }
    }

    fun updateEircode(eircode: String) {
        _uiState.update { it.copy(eircode = eircode, hasUserEdited = true, errorMessage = null) }
    }

    fun save() {
        val state = _uiState.value
        if (state.isSaving) {
            return
        }
        if (state.mustUseWebProfile) {
            _uiState.update {
                it.copy(errorMessage = "Profile updates must be completed on the web portal.")
            }
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null) }
            try {
                withContext(dispatchers.io) {
                    profileRepository.updateProfile(
                        mobile = state.mobile,
                        fullAddress = state.fullAddress,
                        eircode = state.eircode,
                    )
                }
                _uiState.update { it.copy(isSaving = false) }
                _events.emit(Event.Saved)
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isSaving = false,
                        errorMessage = exception.message ?: "Could not save profile.",
                    )
                }
            }
        }
    }

    data class EditProfileUiState(
        val mobile: String = "",
        val fullAddress: String = "",
        val eircode: String = "",
        val mustUseWebProfile: Boolean = false,
        val hasUserEdited: Boolean = false,
        val isInitialLoading: Boolean = false,
        val isSaving: Boolean = false,
        val errorMessage: String? = null,
    )

    sealed interface Event {
        data object Saved : Event
    }
}
