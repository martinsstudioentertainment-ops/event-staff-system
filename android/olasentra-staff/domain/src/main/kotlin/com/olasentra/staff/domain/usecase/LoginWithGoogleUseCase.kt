package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.AuthRepository

class LoginWithGoogleUseCase(
    private val authRepository: AuthRepository,
) {
    suspend operator fun invoke(idToken: String) = authRepository.loginWithGoogle(idToken)
}
