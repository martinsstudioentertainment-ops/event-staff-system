package com.olasentra.staff.feature.auth.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.AuthException
import com.olasentra.staff.domain.repository.AuthRepository
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
class EmailSignInViewModel @Inject constructor(
    private val authRepository: AuthRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(EmailSignInUiState())
    val uiState: StateFlow<EmailSignInUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    fun updateEmail(email: String) {
        _uiState.update { it.copy(email = email, errorMessage = null) }
    }

    fun sendVerificationCode() {
        val email = _uiState.value.email.trim().lowercase()
        if (email.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Enter your email address.", successMessage = null) }
            return
        }
        if (!email.contains('@') || !email.contains('.')) {
            _uiState.update { it.copy(errorMessage = "Enter a valid email address.", successMessage = null) }
            return
        }
        if (_uiState.value.isSending) {
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isSending = true, errorMessage = null, successMessage = null) }
            try {
                withContext(dispatchers.io) {
                    authRepository.sendLoginOtp(email)
                }
                _uiState.update {
                    it.copy(
                        isSending = false,
                        email = email,
                        successMessage = "Verification code sent. Check your inbox.",
                    )
                }
                kotlinx.coroutines.delay(400)
                _events.emit(Event.CodeSent(email))
            } catch (exception: AuthException) {
                _uiState.update {
                    it.copy(isSending = false, errorMessage = exception.message, successMessage = null)
                }
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isSending = false,
                        errorMessage = exception.message ?: "Could not send verification code.",
                        successMessage = null,
                    )
                }
            }
        }
    }

    data class EmailSignInUiState(
        val email: String = "",
        val isSending: Boolean = false,
        val errorMessage: String? = null,
        val successMessage: String? = null,
    )

    sealed interface Event {
        data class CodeSent(val email: String) : Event
    }
}
