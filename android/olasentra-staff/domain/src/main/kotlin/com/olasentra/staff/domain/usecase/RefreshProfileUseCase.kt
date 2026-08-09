package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.ProfileRepository

class RefreshProfileUseCase(
    private val profileRepository: ProfileRepository,
) {
    suspend operator fun invoke() = profileRepository.refreshProfile()
}
