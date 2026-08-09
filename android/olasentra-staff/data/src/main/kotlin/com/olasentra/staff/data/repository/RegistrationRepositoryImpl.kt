package com.olasentra.staff.data.repository

import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.registration.RegistrationMultipartPayload
import com.olasentra.staff.data.registration.RegistrationSiteClient
import com.olasentra.staff.data.registration.UploadPart
import com.olasentra.staff.domain.model.RegistrationOptions
import com.olasentra.staff.domain.model.RegistrationSession
import com.olasentra.staff.domain.model.RegistrationSubmitPayload
import com.olasentra.staff.domain.model.RegistrationSubmitResult
import com.olasentra.staff.domain.model.RegistrationEventOption
import com.olasentra.staff.domain.repository.RegistrationRepository
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.withContext

@Singleton
class RegistrationRepositoryImpl @Inject constructor(
    private val client: RegistrationSiteClient,
    private val dispatchers: DispatcherProvider,
) : RegistrationRepository {

    override suspend fun sendRegistrationOtp(siteUrl: String, email: String): Result<Unit> {
        return withContext(dispatchers.io) {
            runCatching {
                val result = client.sendRegistrationOtp(siteUrl, email)
                if (result.error != null) {
                    throw IllegalStateException(result.error)
                }
            }
        }
    }

    override suspend fun verifyRegistrationEmail(
        siteUrl: String,
        email: String,
        code: String,
    ): Result<RegistrationSession> {
        return withContext(dispatchers.io) {
            runCatching {
                val result = client.verifyRegistrationOtp(siteUrl, email, code)
                if (result.error != null) {
                    throw IllegalStateException(result.error)
                }
                if (result.email.isBlank() || result.csrfToken.isBlank()) {
                    throw IllegalStateException("Incomplete email verification response.")
                }
                RegistrationSession(
                    email = result.email,
                    csrfToken = result.csrfToken,
                )
            }
        }
    }

    override suspend fun verifyGoogle(siteUrl: String, idToken: String): Result<RegistrationSession> {
        return withContext(dispatchers.io) {
            runCatching {
                val result = client.verifyGoogle(siteUrl, idToken)
                if (result.error != null) {
                    throw IllegalStateException(result.error)
                }
                if (result.email.isBlank() || result.csrfToken.isBlank()) {
                    throw IllegalStateException("Incomplete Google verification response.")
                }
                RegistrationSession(
                    email = result.email,
                    csrfToken = result.csrfToken,
                )
            }
        }
    }

    override suspend fun loadOptions(siteUrl: String, formSlug: String): Result<RegistrationOptions> {
        return withContext(dispatchers.io) {
            runCatching {
                val json = client.loadOptions(siteUrl, formSlug)
                RegistrationOptions(
                    formSlug = json.formSlug,
                    staffRole = json.staffRole,
                    events = json.events.map { event ->
                        RegistrationEventOption(
                            eventId = event.eventId,
                            label = event.label,
                            venueName = event.venueName,
                            eventDate = event.eventDate,
                            timeLabel = event.timeLabel,
                            isFull = event.isFull,
                        )
                    },
                )
            }
        }
    }

    override suspend fun submitRegistration(
        siteUrl: String,
        payload: RegistrationSubmitPayload,
    ): Result<RegistrationSubmitResult> {
        return withContext(dispatchers.io) {
            runCatching {
                val csrf = payload.csrfToken.ifBlank {
                    client.refreshCsrf(siteUrl, payload.formSlug)
                }
                val response = client.submit(
                    siteUrl = siteUrl,
                    parts = RegistrationMultipartPayload(
                        csrfToken = csrf,
                        formSlug = payload.formSlug,
                        staffRole = payload.staffRole,
                        verifiedGoogleEmail = payload.verifiedGoogleEmail,
                        surname = payload.surname,
                        firstName = payload.firstName,
                        fullAddress = payload.fullAddress,
                        eircode = payload.eircode,
                        email = payload.email,
                        mobile = payload.mobile,
                        dateOfBirth = payload.dateOfBirth,
                        gender = payload.gender,
                        ppsNumber = payload.ppsNumber,
                        bankIban = payload.bankIban,
                        psaLicence = payload.psaLicence,
                        psaExpiryDate = payload.psaExpiryDate,
                        eventIds = payload.eventIds,
                        psaFrontImage = payload.psaFrontImage?.toUploadPart(),
                        psaBackImage = payload.psaBackImage?.toUploadPart(),
                    ),
                )
                if (!response.success) {
                    val detail = response.errors.firstOrNull()?.takeIf { it.isNotBlank() }
                    throw IllegalStateException(detail ?: response.message)
                }
                RegistrationSubmitResult(
                    success = true,
                    message = response.message,
                    registrationCount = response.count,
                )
            }
        }
    }

    private fun com.olasentra.staff.domain.model.RegistrationUploadFile.toUploadPart(): UploadPart {
        return UploadPart(
            fileName = fileName,
            mimeType = mimeType,
            bytes = bytes,
        )
    }
}
