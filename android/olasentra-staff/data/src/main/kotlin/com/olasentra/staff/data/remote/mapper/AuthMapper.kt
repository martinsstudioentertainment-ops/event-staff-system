package com.olasentra.staff.data.remote.mapper

import com.olasentra.staff.core.network.dto.AuthSuccessResponse
import com.olasentra.staff.core.network.dto.StaffSummaryDto
import com.olasentra.staff.core.network.dto.TokenResponse
import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.model.StaffSummary
import javax.inject.Inject

class AuthMapper @Inject constructor() {

    fun toAuthSession(response: AuthSuccessResponse): AuthSession {
        val accessToken = response.accessToken?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing access token in auth response")
        val refreshToken = response.refreshToken?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing refresh token in auth response")
        val staff = response.staff?.let(::toStaffSummary)
            ?: throw IllegalStateException("Missing staff in auth response")

        return AuthSession(
            accessToken = accessToken,
            refreshToken = refreshToken,
            expiresInSeconds = response.expiresIn?.toLong() ?: 0L,
            staff = staff,
        )
    }

    fun toAuthSession(
        tokenResponse: TokenResponse,
        existingStaff: StaffSummary,
    ): AuthSession {
        val accessToken = tokenResponse.accessToken?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing access token in refresh response")
        val refreshToken = tokenResponse.refreshToken?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing refresh token in refresh response")

        return AuthSession(
            accessToken = accessToken,
            refreshToken = refreshToken,
            expiresInSeconds = tokenResponse.expiresIn?.toLong() ?: 0L,
            staff = existingStaff,
        )
    }

    fun toStaffSummary(dto: StaffSummaryDto): StaffSummary {
        val id = dto.id ?: throw IllegalStateException("Missing staff id in auth response")
        val email = dto.email?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing staff email in auth response")

        return StaffSummary(
            id = id,
            email = email,
            firstName = dto.firstName,
            surname = dto.surname,
            profileComplete = dto.profileComplete ?: false,
            profileReverifyRequired = dto.profileReverifyRequired ?: false,
            profileGateBlocked = dto.profileGateBlocked ?: false,
        )
    }
}
