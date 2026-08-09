package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.repository.AuthRepository

class RefreshAuthSessionUseCase(
    private val authRepository: AuthRepository,
) {
    suspend operator fun invoke(): AuthSession = authRepository.refreshSession()
}
