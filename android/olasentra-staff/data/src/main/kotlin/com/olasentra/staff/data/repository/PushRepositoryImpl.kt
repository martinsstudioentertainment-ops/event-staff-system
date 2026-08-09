package com.olasentra.staff.data.repository

import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.PushRegisterRequest
import com.olasentra.staff.core.preferences.DeviceIdProvider
import com.olasentra.staff.core.preferences.FcmTokenStore
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.domain.model.PushRegistrationResult
import com.olasentra.staff.domain.repository.PushRepository
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PushRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCallHandler: ApiCallHandler,
    private val deviceIdProvider: DeviceIdProvider,
    private val fcmTokenStore: FcmTokenStore,
) : PushRepository {

    override suspend fun registerCurrentToken(fcmToken: String): PushRegistrationResult {
        val deviceId = deviceIdProvider.getDeviceId()
        val result = apiCallHandler.safeApiCall {
            api.postPushRegister(
                PushRegisterRequest(
                    fcmToken = fcmToken,
                    deviceId = deviceId,
                    platform = "android",
                ),
            )
        }

        return when (result) {
            is ApiResult.Success -> {
                if (result.data.ok == true) {
                    fcmTokenStore.saveRegisteredToken(fcmToken)
                    fcmTokenStore.clearPendingToken()
                }
                PushRegistrationResult(
                    success = result.data.ok == true,
                    message = if (result.data.ok == true) "Push registered" else "Push registration failed",
                )
            }
            is ApiResult.Error -> PushRegistrationResult(success = false, message = result.message)
            ApiResult.Loading -> PushRegistrationResult(success = false, message = "Unexpected state")
        }
    }

    override suspend fun unregisterCurrentDevice(): PushRegistrationResult {
        val deviceId = deviceIdProvider.getDeviceIdOrNull()
            ?: return PushRegistrationResult(success = true, message = "No device id")

        val result = apiCallHandler.safeApiCall {
            api.deletePushRegister(deviceId = deviceId)
        }

        fcmTokenStore.clearRegisteredToken()

        return when (result) {
            is ApiResult.Success -> PushRegistrationResult(
                success = result.data.ok != false,
                message = result.data.message ?: "Push unregistered",
            )
            is ApiResult.Error -> PushRegistrationResult(success = false, message = result.message)
            ApiResult.Loading -> PushRegistrationResult(success = false, message = "Unexpected state")
        }
    }

    override suspend fun registerPendingTokenIfNeeded() {
        val pending = fcmTokenStore.getPendingToken() ?: return
        val registered = fcmTokenStore.getRegisteredToken()
        if (pending == registered) {
            fcmTokenStore.clearPendingToken()
            return
        }
        registerCurrentToken(pending)
    }
}
