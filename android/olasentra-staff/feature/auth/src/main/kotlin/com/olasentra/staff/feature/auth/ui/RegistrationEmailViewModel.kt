package com.olasentra.staff.feature.auth.ui

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.core.util.DispatcherProvider
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
class RegistrationEmailViewModel @Inject constructor(
    savedStateHandle: SavedStateHandle,
    private val registrationRepository: RegistrationRepository,
    private val configRepository: ConfigRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val formSlug = savedStateHandle.get<String>(Route.RegistrationEmail.formSlugArg).orEmpty()

    private val _uiState = MutableStateFlow(
        RegistrationEmailUiState(formSlug = formSlug),
    )
    val uiState: StateFlow<RegistrationEmailUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    private var registrationSiteUrl: String = RegistrationForms.DEFAULT_REGISTRATION_SITE_URL

    init {
        viewModelScope.launch(dispatchers.io) {
            runCatching { configRepository.fetchConfig(appVersion = null) }
                .onSuccess { config ->
                    registrationSiteUrl = config.registrationSiteUrl
                        ?.trim()
                        ?.trimEnd('/')
                        ?.ifBlank { null }
                        ?: RegistrationForms.DEFAULT_REGISTRATION_SITE_URL
                    _uiState.update { it.copy(registrationSiteUrl = registrationSiteUrl) }
                }
        }
    }

    fun updateEmail(email: String) {
        _uiState.update { it.copy(email = email, errorMessage = null) }
    }

    fun sendVerificationCode() {
        val email = _uiState.value.email.trim()
        if (email.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Enter your email address.") }
            return
        }
        if (!email.contains('@')) {
            _uiState.update { it.copy(errorMessage = "Enter a valid email address.") }
            return
        }
        if (_uiState.value.isSending) {
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isSending = true, errorMessage = null) }
            val result = withContext(dispatchers.io) {
                registrationRepository.sendRegistrationOtp(
                    siteUrl = registrationSiteUrl,
                    email = email,
                )
            }
            result
                .onSuccess {
                    _uiState.update { it.copy(isSending = false) }
                    _events.emit(Event.CodeSent(email))
                }
                .onFailure { error ->
                    _uiState.update {
                        it.copy(
                            isSending = false,
                            errorMessage = error.message ?: "Could not send verification code.",
                        )
                    }
                }
        }
    }

    data class RegistrationEmailUiState(
        val formSlug: String = "",
        val email: String = "",
        val registrationSiteUrl: String = RegistrationForms.DEFAULT_REGISTRATION_SITE_URL,
        val isSending: Boolean = false,
        val errorMessage: String? = null,
    )

    sealed interface Event {
        data class CodeSent(val email: String) : Event
    }
}
