package com.olasentra.staff.feature.auth.ui

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.RegistrationSession
import com.olasentra.staff.domain.repository.AuthException
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.ConfigRepository
import com.olasentra.staff.domain.repository.RegistrationRepository
import com.olasentra.staff.feature.auth.registration.RegistrationForms
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
class OtpVerificationViewModel @Inject constructor(
    savedStateHandle: SavedStateHandle,
    private val authRepository: AuthRepository,
    private val registrationRepository: RegistrationRepository,
    private val configRepository: ConfigRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val email = savedStateHandle.get<String>(Route.OtpVerification.emailArg)
        .orEmpty()
        .trim()
        .lowercase()
    private val purpose = savedStateHandle.get<String>(Route.OtpVerification.purposeArg).orEmpty()

    private val _uiState = MutableStateFlow(
        OtpVerificationUiState(
            email = email,
            purpose = purpose,
        ),
    )
    val uiState: StateFlow<OtpVerificationUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    private var registrationSiteUrl: String = RegistrationForms.DEFAULT_REGISTRATION_SITE_URL

    init {
        if (purpose == PURPOSE_REGISTRATION) {
            viewModelScope.launch(dispatchers.io) {
                runCatching { configRepository.fetchConfig(appVersion = null) }
                    .onSuccess { config ->
                        registrationSiteUrl = config.registrationSiteUrl
                            ?.trim()
                            ?.trimEnd('/')
                            ?.ifBlank { null }
                            ?: RegistrationForms.DEFAULT_REGISTRATION_SITE_URL
                    }
            }
        }
    }

    fun updateCode(code: String) {
        val digitsOnly = code.filter { it.isDigit() }.take(OTP_LENGTH)
        _uiState.update { it.copy(code = digitsOnly, errorMessage = null) }
    }

    fun verify() {
        val state = _uiState.value
        if (state.isVerifying) {
            return
        }
        if (state.code.length != OTP_LENGTH) {
            _uiState.update { it.copy(errorMessage = "Enter the 6-digit verification code.") }
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isVerifying = true, errorMessage = null) }
            try {
                when (state.purpose) {
                    PURPOSE_LOGIN -> {
                        withContext(dispatchers.io) {
                            authRepository.verifyLoginOtp(state.email, state.code)
                        }
                        _uiState.update { it.copy(isVerifying = false) }
                        _events.emit(Event.LoginSucceeded)
                    }

                    PURPOSE_REGISTRATION -> {
                        val result = withContext(dispatchers.io) {
                            registrationRepository.verifyRegistrationEmail(
                                siteUrl = registrationSiteUrl,
                                email = state.email,
                                code = state.code,
                            )
                        }
                        result
                            .onSuccess { session ->
                                _uiState.update { it.copy(isVerifying = false) }
                                _events.emit(Event.RegistrationVerified(session))
                            }
                            .onFailure { error ->
                                _uiState.update {
                                    it.copy(
                                        isVerifying = false,
                                        errorMessage = error.message ?: "Verification failed.",
                                    )
                                }
                            }
                    }

                    else -> {
                        _uiState.update {
                            it.copy(
                                isVerifying = false,
                                errorMessage = "Unknown verification purpose.",
                            )
                        }
                    }
                }
            } catch (exception: AuthException) {
                _uiState.update {
                    it.copy(isVerifying = false, errorMessage = exception.message)
                }
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isVerifying = false,
                        errorMessage = exception.message ?: "Verification failed.",
                    )
                }
            }
        }
    }

    fun resendCode() {
        val state = _uiState.value
        if (state.isResending || state.email.isBlank()) {
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isResending = true, errorMessage = null) }
            try {
                when (state.purpose) {
                    PURPOSE_LOGIN -> {
                        withContext(dispatchers.io) {
                            authRepository.sendLoginOtp(state.email)
                        }
                    }

                    PURPOSE_REGISTRATION -> {
                        val result = withContext(dispatchers.io) {
                            registrationRepository.sendRegistrationOtp(
                                siteUrl = registrationSiteUrl,
                                email = state.email,
                            )
                        }
                        result.onFailure { error ->
                            throw IllegalStateException(error.message ?: "Could not resend code.")
                        }
                    }
                }
                _uiState.update { it.copy(isResending = false, code = "") }
                _events.emit(Event.CodeResent)
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isResending = false,
                        errorMessage = exception.message ?: "Could not resend code.",
                    )
                }
            }
        }
    }

    data class OtpVerificationUiState(
        val email: String = "",
        val purpose: String = "",
        val code: String = "",
        val isVerifying: Boolean = false,
        val isResending: Boolean = false,
        val errorMessage: String? = null,
    )

    sealed interface Event {
        data object LoginSucceeded : Event

        data class RegistrationVerified(val session: RegistrationSession) : Event

        data object CodeResent : Event
    }

    companion object {
        const val PURPOSE_LOGIN = "login"
        const val PURPOSE_REGISTRATION = "registration"
        private const val OTP_LENGTH = 6
    }
}
