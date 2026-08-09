package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.ProfileRepository

class ObserveProfileUseCase(
    private val profileRepository: ProfileRepository,
) {
    operator fun invoke() = profileRepository.observeProfile()
}
