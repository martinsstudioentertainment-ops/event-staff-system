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
class LoginViewModel @Inject constructor(
    private val authRepository: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(LoginUiState())
    val state: StateFlow<LoginUiState> = _state.asStateFlow()

    fun onEmailChanged(value: String) {
        _state.update { it.copy(email = value, errorMessage = null) }
    }

    fun sendOtp(onSent: (String) -> Unit) {
        val email = _state.value.email.trim().lowercase()
        if (email.isBlank() || !email.contains('@')) {
            _state.update { it.copy(errorMessage = "Enter a valid email address.") }
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(isLoading = true, errorMessage = null) }
            runCatching { authRepository.sendLoginOtp(email) }
                .onSuccess {
                    _state.update { it.copy(isLoading = false) }
                    onSent(email)
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

    private fun Throwable.toUserMessage(): String {
        return when (this) {
            is AuthException -> message ?: "Sign-in failed."
            else -> message ?: "Sign-in failed."
        }
    }
}

data class LoginUiState(
    val email: String = "",
    val isLoading: Boolean = false,
    val errorMessage: String? = null,
)
