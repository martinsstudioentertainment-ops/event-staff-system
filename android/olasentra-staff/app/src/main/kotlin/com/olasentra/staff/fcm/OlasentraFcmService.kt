package com.olasentra.staff.fcm

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.olasentra.staff.core.preferences.FcmTokenStore
import com.olasentra.staff.core.util.AppLogger
import com.olasentra.staff.domain.repository.PushRepository
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

@AndroidEntryPoint
class OlasentraFcmService : FirebaseMessagingService() {

    @Inject lateinit var appLogger: AppLogger
    @Inject lateinit var fcmTokenStore: FcmTokenStore
    @Inject lateinit var pushRepository: PushRepository
    @Inject lateinit var notificationDisplayHelper: FcmNotificationDisplayHelper

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        appLogger.i(TAG, "FCM token refreshed (${token.length} chars)")
        serviceScope.launch {
            fcmTokenStore.savePendingToken(token)
            pushRepository.registerCurrentToken(token)
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val title = message.notification?.title ?: message.data["title"] ?: "Olasentra"
        val body = message.notification?.body ?: message.data["body"] ?: ""
        appLogger.i(TAG, "FCM message received: title=$title")

        val data = message.data
        val notificationId = data["notification_id"]?.toIntOrNull()
            ?: data["id"]?.toIntOrNull()
            ?: (System.currentTimeMillis() % Int.MAX_VALUE).toInt()

        notificationDisplayHelper.showNotification(
            notificationId = notificationId,
            title = title,
            body = body,
            data = data,
        )
    }

    private companion object {
        const val TAG = "OlasentraFcmService"
    }
}
