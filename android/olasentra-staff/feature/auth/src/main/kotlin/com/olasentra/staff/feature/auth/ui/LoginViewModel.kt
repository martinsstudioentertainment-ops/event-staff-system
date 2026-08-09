package com.olasentra.staff.feature.auth.ui

import android.app.Activity
import android.content.Context
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.AppVersionUtils
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.AuthException
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.ConfigRepository
import com.olasentra.staff.domain.usecase.LoginWithGoogleUseCase
import com.olasentra.staff.feature.auth.google.GoogleSignInManager
import com.olasentra.staff.feature.auth.google.GoogleSignInResult
import com.olasentra.staff.feature.auth.registration.RegistrationForms
import dagger.hilt.android.lifecycle.HiltViewModel
import dagger.hilt.android.qualifiers.ApplicationContext
import java.util.Locale
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class LoginViewModel @Inject constructor(
    authRepository: AuthRepository,
    private val configRepository: ConfigRepository,
    private val googleSignInManager: GoogleSignInManager,
    private val dispatchers: DispatcherProvider,
    @ApplicationContext private val appContext: Context,
) : ViewModel() {

    private val loginWithGoogle = LoginWithGoogleUseCase(authRepository)

    private val _uiState = MutableStateFlow(LoginUiState())
    val uiState: StateFlow<LoginUiState> = _uiState.asStateFlow()

    private val _events = MutableSharedFlow<Event>(extraBufferCapacity = 1)
    val events: SharedFlow<Event> = _events.asSharedFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            runCatching { configRepository.fetchConfig(appVersion = readAppVersion()) }
                .onSuccess { config ->
                    val forceUpdateRequired = AppVersionUtils.isUpdateRequired(
                        current = readAppVersion(),
                        minimum = config.minAppVersion,
                    )
                    val forceUpdateMessage = config.portal.version.forceUpdateMessage
                        ?.takeIf { it.isNotBlank() }
                        ?: "A newer version of the app is required. Please update from your app store or contact support."
                    val registrationSiteUrl = config.registrationSiteUrl
                        ?.trim()
                        ?.trimEnd('/')
                        ?.takeIf { it.isNotBlank() }
                        ?: RegistrationForms.DEFAULT_REGISTRATION_SITE_URL

                    _uiState.update {
                        it.copy(
                            appName = config.portal.appName,
                            loginLogoUrl = config.portal.branding.loginLogoUrl,
                            maintenanceEnabled = config.portal.maintenance.enabled,
                            maintenanceMessage = config.portal.maintenance.message,
                            forceUpdateRequired = forceUpdateRequired,
                            forceUpdateMessage = forceUpdateMessage,
                            emailOtpEnabled = config.emailOtpEnabled,
                            registrationSiteUrl = registrationSiteUrl,
                            privacyUrl = config.privacyUrl,
                            termsUrl = config.termsUrl,
                        )
                    }
                }
        }
    }

    fun onGoogleSignInClicked(activity: Activity) {
        val state = _uiState.value
        if (state.isLoading || state.maintenanceEnabled || state.forceUpdateRequired) {
            return
        }

        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true) }

            when (val signInResult = googleSignInManager.signIn(activity)) {
                GoogleSignInResult.Cancelled -> {
                    _uiState.update { it.copy(isLoading = false) }
                }

                is GoogleSignInResult.Error -> {
                    _uiState.update { it.copy(isLoading = false) }
                    _events.emit(Event.ShowError(signInResult.message))
                }

                is GoogleSignInResult.Success -> {
                    try {
                        loginWithGoogle(signInResult.idToken)
                        _uiState.update { it.copy(isLoading = false) }
                        _events.emit(Event.LoginSucceeded)
                    } catch (exception: AuthException) {
                        handleAuthFailure(exception, signInResult.idToken)
                    } catch (exception: Exception) {
                        _uiState.update { it.copy(isLoading = false) }
                        _events.emit(Event.ShowError(exception.message ?: "Sign-in failed"))
                    }
                }
            }
        }
    }

    fun onTryAnotherGoogleAccountClicked(activity: Activity) {
        _uiState.update {
            it.copy(
                showNoStaffProfile = false,
                pendingGoogleIdToken = null,
            )
        }
        onGoogleSignInClicked(activity)
    }

    fun consumePendingGoogleIdToken(): String? = _uiState.value.pendingGoogleIdToken

    fun clearPostRegistrationMessage() {
        _uiState.update { it.copy(postRegistrationMessage = null) }
    }

    fun loginAfterRegistration(
        idToken: String?,
        onSuccess: () -> Unit,
        onNeedsManualSignIn: () -> Unit,
    ) {
        viewModelScope.launch {
            if (idToken.isNullOrBlank()) {
                _uiState.update {
                    it.copy(postRegistrationMessage = buildPostRegistrationSignInMessage(null))
                }
                onNeedsManualSignIn()
                return@launch
            }

            _uiState.update { it.copy(isLoading = true, postRegistrationMessage = null) }
            try {
                loginWithGoogle(idToken)
                _uiState.update { it.copy(isLoading = false) }
                onSuccess()
            } catch (exception: AuthException) {
                _uiState.update {
                    it.copy(
                        isLoading = false,
                        postRegistrationMessage = buildPostRegistrationSignInMessage(exception.message),
                    )
                }
                onNeedsManualSignIn()
            } catch (exception: Exception) {
                _uiState.update {
                    it.copy(
                        isLoading = false,
                        postRegistrationMessage = buildPostRegistrationSignInMessage(exception.message),
                    )
                }
                onNeedsManualSignIn()
            }
        }
    }

    fun onDismissNoStaffProfile() {
        _uiState.update {
            it.copy(showNoStaffProfile = false, pendingGoogleIdToken = null)
        }
    }

    private suspend fun handleAuthFailure(exception: AuthException, idToken: String?) {
        if (isStaffProfileMissing(exception)) {
            _uiState.update {
                it.copy(
                    isLoading = false,
                    showNoStaffProfile = true,
                    pendingGoogleIdToken = idToken,
                )
            }
        } else {
            _uiState.update { it.copy(isLoading = false) }
            _events.emit(Event.ShowError(exception.message))
        }
    }

    private fun buildPostRegistrationSignInMessage(detail: String?): String {
        val suffix = detail?.takeIf { it.isNotBlank() }?.let { " ($it)" }.orEmpty()
        return "Registration complete. Sign in with Google to open your dashboard.$suffix"
    }

    private fun isStaffProfileMissing(exception: AuthException): Boolean {
        if (exception.errorCode == STAFF_NOT_ELIGIBLE_CODE || exception.errorCode == "STAFF_NOT_FOUND") {
            return true
        }
        val message = exception.message.lowercase(Locale.ROOT)
        return message.contains("not registered") ||
            message.contains("not on our staff list") ||
            message.contains("staff not found")
    }

    private fun readAppVersion(): String? {
        return runCatching {
            appContext.packageManager.getPackageInfo(appContext.packageName, 0).versionName
        }.getOrNull()
    }

    data class LoginUiState(
        val isLoading: Boolean = false,
        val showNoStaffProfile: Boolean = false,
        val pendingGoogleIdToken: String? = null,
        val postRegistrationMessage: String? = null,
        val appName: String = "Olasentra",
        val loginLogoUrl: String? = null,
        val maintenanceEnabled: Boolean = false,
        val maintenanceMessage: String? = null,
        val forceUpdateRequired: Boolean = false,
        val forceUpdateMessage: String? = null,
        val emailOtpEnabled: Boolean = true,
        val registrationSiteUrl: String = RegistrationForms.DEFAULT_REGISTRATION_SITE_URL,
        val privacyUrl: String? = null,
        val termsUrl: String? = null,
    )

    sealed interface Event {
        data object LoginSucceeded : Event

        data class ShowError(val message: String) : Event
    }

    private companion object {
        const val STAFF_NOT_ELIGIBLE_CODE = "STAFF_NOT_ELIGIBLE"
    }
}
