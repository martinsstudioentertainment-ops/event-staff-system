package com.olasentra.staff.fcm

import com.google.firebase.messaging.FirebaseMessaging
import com.olasentra.staff.core.preferences.FcmTokenStore
import com.olasentra.staff.core.util.AppLogger
import com.olasentra.staff.domain.repository.PushRepository
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine

@Singleton
class FcmTokenRegistrar @Inject constructor(
    private val pushRepository: PushRepository,
    private val fcmTokenStore: FcmTokenStore,
    private val appLogger: AppLogger,
) {

    suspend fun registerCurrentDeviceToken() {
        runCatching {
            val token = fetchToken()
            if (token.isNotBlank()) {
                fcmTokenStore.savePendingToken(token)
                pushRepository.registerCurrentToken(token)
            }
        }.onFailure { error ->
            appLogger.w(TAG, "FCM token fetch failed: ${error.message}")
            pushRepository.registerPendingTokenIfNeeded()
        }
    }

    private suspend fun fetchToken(): String {
        return suspendCancellableCoroutine { continuation ->
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { token ->
                    if (continuation.isActive) {
                        continuation.resume(token.orEmpty())
                    }
                }
                .addOnFailureListener { error ->
                    if (continuation.isActive) {
                        continuation.resumeWithException(error)
                    }
                }
        }
    }

    private companion object {
        const val TAG = "FcmTokenRegistrar"
    }
}
