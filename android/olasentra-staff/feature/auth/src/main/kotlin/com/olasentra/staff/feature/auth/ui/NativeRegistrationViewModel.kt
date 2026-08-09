package com.olasentra.staff.feature.auth.ui

import android.app.Activity
import android.net.Uri
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.RegistrationEventOption
import com.olasentra.staff.domain.model.RegistrationSubmitPayload
import com.olasentra.staff.domain.model.RegistrationUploadFile
import com.olasentra.staff.domain.repository.RegistrationRepository
import com.olasentra.staff.feature.auth.google.GoogleSignInManager
import com.olasentra.staff.feature.auth.google.GoogleSignInResult
import com.olasentra.staff.feature.auth.registration.RegistrationForms
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class NativeRegistrationViewModel @Inject constructor(
    savedStateHandle: SavedStateHandle,
    private val registrationRepository: RegistrationRepository,
    private val googleSignInManager: GoogleSignInManager,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val formSlug = savedStateHandle.get<String>(Route.NativeRegistration.formSlugArg).orEmpty()

    private val _uiState = MutableStateFlow(
        NativeRegistrationUiState(
            formSlug = formSlug,
            registrationSiteUrl = RegistrationForms.DEFAULT_REGISTRATION_SITE_URL,
        ),
    )
    val uiState: StateFlow<NativeRegistrationUiState> = _uiState.asStateFlow()

    fun initialize(
        registrationSiteUrl: String,
        googleIdToken: String?,
    ) {
        _uiState.update {
            it.copy(
                registrationSiteUrl = registrationSiteUrl.trim().trimEnd('/')
                    .ifBlank { RegistrationForms.DEFAULT_REGISTRATION_SITE_URL },
                googleIdToken = googleIdToken,
            )
        }
        if (!googleIdToken.isNullOrBlank()) {
            verifyGoogle(googleIdToken)
        }
    }

    fun updateField(field: RegistrationField, value: String) {
        _uiState.update { current ->
            when (field) {
                RegistrationField.Surname -> current.copy(surname = value)
                RegistrationField.FirstName -> current.copy(firstName = value)
                RegistrationField.FullAddress -> current.copy(fullAddress = value)
                RegistrationField.Eircode -> current.copy(eircode = value)
                RegistrationField.Mobile -> current.copy(mobile = value)
                RegistrationField.DateOfBirth -> current.copy(dateOfBirth = value)
                RegistrationField.Gender -> current.copy(gender = value)
                RegistrationField.PpsNumber -> current.copy(ppsNumber = value)
                RegistrationField.BankIban -> current.copy(bankIban = value)
                RegistrationField.PsaLicence -> current.copy(psaLicence = value)
                RegistrationField.PsaExpiryDate -> current.copy(psaExpiryDate = value)
            }
        }
    }

    fun toggleEvent(eventId: Long) {
        _uiState.update { current ->
            val selected = current.selectedEventIds.toMutableSet()
            if (!selected.add(eventId)) {
                selected.remove(eventId)
            }
            current.copy(selectedEventIds = selected)
        }
    }

    fun setUpload(slot: RegistrationUploadSlot, fileName: String, mimeType: String, bytes: ByteArray) {
        val upload = RegistrationUploadFile(fileName = fileName, mimeType = mimeType, bytes = bytes)
        _uiState.update { current ->
            when (slot) {
                RegistrationUploadSlot.Front -> current.copy(psaFrontImage = upload)
                RegistrationUploadSlot.Back -> current.copy(psaBackImage = upload)
            }
        }
    }

    fun verifyGoogleWithPicker(activity: Activity) {
        viewModelScope.launch(dispatchers.main) {
            _uiState.update { it.copy(isLoading = true, errorMessage = null) }
            when (val result = googleSignInManager.signIn(activity)) {
                GoogleSignInResult.Cancelled -> {
                    _uiState.update { it.copy(isLoading = false) }
                }
                is GoogleSignInResult.Error -> {
                    _uiState.update { it.copy(isLoading = false, errorMessage = result.message) }
                }
                is GoogleSignInResult.Success -> {
                    _uiState.update { it.copy(googleIdToken = result.idToken) }
                    verifyGoogle(result.idToken)
                }
            }
        }
    }

    private fun verifyGoogle(idToken: String) {
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isLoading = true, errorMessage = null) }
            val siteUrl = _uiState.value.registrationSiteUrl
            registrationRepository.verifyGoogle(siteUrl, idToken)
                .onSuccess { session ->
                    registrationRepository.loadOptions(siteUrl, formSlug)
                        .onSuccess { options ->
                            _uiState.update {
                                it.copy(
                                    isLoading = false,
                                    verifiedEmail = session.email,
                                    csrfToken = session.csrfToken,
                                    staffRole = options.staffRole,
                                    events = options.events,
                                )
                            }
                        }
                        .onFailure { error ->
                            _uiState.update {
                                it.copy(isLoading = false, errorMessage = error.message ?: "Could not load events.")
                            }
                        }
                }
                .onFailure { error ->
                    _uiState.update {
                        it.copy(isLoading = false, errorMessage = error.message ?: "Google verification failed.")
                    }
                }
        }
    }

    fun submit() {
        val state = _uiState.value
        if (state.verifiedEmail.isBlank() || state.csrfToken.isBlank()) {
            _uiState.update { it.copy(errorMessage = "Verify Gmail with Google before submitting.") }
            return
        }
        if (state.selectedEventIds.isEmpty()) {
            _uiState.update { it.copy(errorMessage = "Select at least one event.") }
            return
        }
        if (state.psaFrontImage == null || state.psaBackImage == null) {
            _uiState.update { it.copy(errorMessage = "Upload PSA card front and back photos.") }
            return
        }

        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isSubmitting = true, errorMessage = null) }
            val payload = RegistrationSubmitPayload(
                formSlug = formSlug,
                staffRole = state.staffRole.ifBlank { formSlug },
                csrfToken = state.csrfToken,
                verifiedGoogleEmail = state.verifiedEmail,
                surname = state.surname.trim(),
                firstName = state.firstName.trim(),
                fullAddress = state.fullAddress.trim(),
                eircode = state.eircode.trim(),
                email = state.verifiedEmail,
                mobile = state.mobile.trim(),
                dateOfBirth = state.dateOfBirth.trim(),
                gender = state.gender.trim(),
                ppsNumber = state.ppsNumber.trim(),
                bankIban = state.bankIban.trim(),
                psaLicence = state.psaLicence.trim(),
                psaExpiryDate = state.psaExpiryDate.trim(),
                eventIds = state.selectedEventIds.toList(),
                privacyConsent = true,
                psaFrontImage = state.psaFrontImage,
                psaBackImage = state.psaBackImage,
            )
            registrationRepository.submitRegistration(state.registrationSiteUrl, payload)
                .onSuccess { result ->
                    _uiState.update {
                        it.copy(
                            isSubmitting = false,
                            submitted = true,
                            successMessage = result.message,
                        )
                    }
                }
                .onFailure { error ->
                    _uiState.update {
                        it.copy(isSubmitting = false, errorMessage = error.message ?: "Registration failed.")
                    }
                }
        }
    }
}

enum class RegistrationField {
    Surname,
    FirstName,
    FullAddress,
    Eircode,
    Mobile,
    DateOfBirth,
    Gender,
    PpsNumber,
    BankIban,
    PsaLicence,
    PsaExpiryDate,
}

enum class RegistrationUploadSlot {
    Front,
    Back,
}

data class NativeRegistrationUiState(
    val formSlug: String,
    val registrationSiteUrl: String,
    val googleIdToken: String? = null,
    val verifiedEmail: String = "",
    val csrfToken: String = "",
    val staffRole: String = "",
    val events: List<RegistrationEventOption> = emptyList(),
    val selectedEventIds: Set<Long> = emptySet(),
    val surname: String = "",
    val firstName: String = "",
    val fullAddress: String = "",
    val eircode: String = "",
    val mobile: String = "",
    val dateOfBirth: String = "",
    val gender: String = "",
    val ppsNumber: String = "",
    val bankIban: String = "",
    val psaLicence: String = "",
    val psaExpiryDate: String = "",
    val psaFrontImage: RegistrationUploadFile? = null,
    val psaBackImage: RegistrationUploadFile? = null,
    val isLoading: Boolean = false,
    val isSubmitting: Boolean = false,
    val submitted: Boolean = false,
    val successMessage: String? = null,
    val errorMessage: String? = null,
)
