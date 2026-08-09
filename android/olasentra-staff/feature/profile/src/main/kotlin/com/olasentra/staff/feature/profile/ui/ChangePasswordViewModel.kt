package com.olasentra.staff.feature.profile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.ProfileRepository
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
class ChangePasswordViewModel @Inject constructor(
    private val profileRepository: ProfileRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(ChangePasswordUiState())
    val uiState: StateFlow<ChangePasswordUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    fun updateOtpCode(code: String) {
        val digitsOnly = code.filter { it.isDigit() }.take(OTP_LENGTH)
        _uiState.update { it.copy(otpCode = digitsOnly, errorMessage = null) }
    }

    fun updateCurrentPassword(password: String) {
        _uiState.update { it.copy(currentPassword = password, errorMessage = null) }
    }

    fun updateNewPassword(password: String) {
        _uiState.update { it.copy(newPassword = password, errorMessage = null) }
    }

    fun updateConfirmPassword(password: String) {
        _uiState.update { it.copy(confirmPassword = password, errorMessage = null) }
    }

    fun sendOtp() {
        if (_uiState.value.isSendingOtp) {
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isSendingOtp = true, errorMessage = null) }
            try {
                withContext(dispatchers.io) {
                    profileRepository.sendPasswordOtp()
                }
                _uiState.update { it.copy(isSendingOtp = false, otpSent = true) }
                _events.emit(Event.OtpSent)
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isSendingOtp = false,
                        errorMessage = exception.message ?: "Could not send verification code.",
                    )
                }
            }
        }
    }

    fun save() {
        val state = _uiState.value
        if (state.isSaving) {
            return
        }
        if (state.newPassword.length < MIN_PASSWORD_LENGTH) {
            _uiState.update {
                it.copy(errorMessage = "New password must be at least $MIN_PASSWORD_LENGTH characters.")
            }
            return
        }
        if (state.newPassword != state.confirmPassword) {
            _uiState.update { it.copy(errorMessage = "New passwords do not match.") }
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isSaving = true, errorMessage = null) }
            try {
                withContext(dispatchers.io) {
                    profileRepository.changePassword(
                        newPassword = state.newPassword,
                        otpCode = state.otpCode.takeIf { it.isNotBlank() },
                        currentPassword = state.currentPassword.takeIf { it.isNotBlank() },
                    )
                }
                _uiState.update { it.copy(isSaving = false) }
                _events.emit(Event.PasswordChanged)
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isSaving = false,
                        errorMessage = exception.message ?: "Could not change password.",
                    )
                }
            }
        }
    }

    data class ChangePasswordUiState(
        val otpCode: String = "",
        val currentPassword: String = "",
        val newPassword: String = "",
        val confirmPassword: String = "",
        val otpSent: Boolean = false,
        val isSendingOtp: Boolean = false,
        val isSaving: Boolean = false,
        val errorMessage: String? = null,
    )

    sealed interface Event {
        data object OtpSent : Event

        data object PasswordChanged : Event
    }

    companion object {
        private const val OTP_LENGTH = 6
        private const val MIN_PASSWORD_LENGTH = 8
    }
}
