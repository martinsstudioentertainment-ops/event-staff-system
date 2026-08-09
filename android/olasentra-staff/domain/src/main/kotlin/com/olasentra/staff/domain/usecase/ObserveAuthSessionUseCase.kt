package com.olasentra.staff.domain.usecase



import com.olasentra.staff.domain.model.AuthSession

import com.olasentra.staff.domain.repository.AuthRepository

import kotlinx.coroutines.flow.Flow



class ObserveAuthSessionUseCase(

    private val authRepository: AuthRepository,

) {

    operator fun invoke(): Flow<AuthSession?> = authRepository.observeSession()

}