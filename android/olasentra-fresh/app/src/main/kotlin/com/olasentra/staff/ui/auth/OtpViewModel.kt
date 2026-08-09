package com.olasentra.staff.ui.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.data.repository.AuthException
import com.olasentra.staff.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class OtpViewModel @Inject constructor(
    private val authRepository: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(OtpUiState())
    val state: StateFlow<OtpUiState> = _state.asStateFlow()

    fun bindEmail(email: String) {
        _state.update { it.copy(email = email.trim().lowercase()) }
    }

    fun onCodeChanged(value: String) {
        val digitsOnly = value.filter { it.isDigit() }.take(6)
        _state.update { it.copy(code = digitsOnly, errorMessage = null) }
    }

    fun verify(onVerified: () -> Unit) {
        val email = _state.value.email
        val code = _state.value.code.trim()
        if (code.length < 4) {
            _state.update { it.copy(errorMessage = "Enter the verification code from your email.") }
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(isLoading = true, errorMessage = null) }
            runCatching { authRepository.verifyLoginOtp(email, code) }
                .onSuccess {
                    _state.update { it.copy(isLoading = false) }
                    onVerified()
                }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            isLoading = false,
                            errorMessage = error.toUserMessage(),
                        )
                    }
                }
        }
    }

    fun resend() {
        val email = _state.value.email
        if (email.isBlank()) {
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(isResending = true, errorMessage = null) }
            runCatching { authRepository.sendLoginOtp(email) }
                .onSuccess {
                    _state.update { it.copy(isResending = false, infoMessage = "A new code was sent.") }
                }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            isResending = false,
                            errorMessage = error.toUserMessage(),
                        )
                    }
                }
        }
    }

    fun clearInfoMessage() {
        _state.update { it.copy(infoMessage = null) }
    }

    private fun Throwable.toUserMessage(): String {
        return when (this) {
            is AuthException -> message ?: "Verification failed."
            else -> message ?: "Verification failed."
        }
    }
}

data class OtpUiState(
    val email: String = "",
    val code: String = "",
    val isLoading: Boolean = false,
    val isResending: Boolean = false,
    val errorMessage: String? = null,
    val infoMessage: String? = null,
)
