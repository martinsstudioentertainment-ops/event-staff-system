package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.RegistrationOptions
import com.olasentra.staff.domain.model.RegistrationSession
import com.olasentra.staff.domain.model.RegistrationSubmitPayload
import com.olasentra.staff.domain.model.RegistrationSubmitResult

interface RegistrationRepository {
    suspend fun verifyGoogle(siteUrl: String, idToken: String): Result<RegistrationSession>

    suspend fun sendRegistrationOtp(siteUrl: String, email: String): Result<Unit>

    suspend fun verifyRegistrationEmail(
        siteUrl: String,
        email: String,
        code: String,
    ): Result<RegistrationSession>

    suspend fun loadOptions(siteUrl: String, formSlug: String): Result<RegistrationOptions>

    suspend fun submitRegistration(
        siteUrl: String,
        payload: RegistrationSubmitPayload,
    ): Result<RegistrationSubmitResult>
}
