package com.olasentra.staff.domain.repository



import com.olasentra.staff.domain.model.PushRegistrationResult



interface PushRepository {

    suspend fun registerCurrentToken(fcmToken: String): PushRegistrationResult

    suspend fun unregisterCurrentDevice(): PushRegistrationResult

    suspend fun registerPendingTokenIfNeeded()

}