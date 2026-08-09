package com.olasentra.staff.fcm

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.olasentra.staff.MainActivity
import com.olasentra.staff.R
import com.olasentra.staff.core.navigation.DeepLinkDestination
import com.olasentra.staff.navigation.NotificationDeepLinkResolver
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class FcmNotificationDisplayHelper @Inject constructor(
    @ApplicationContext private val context: Context,
    private val deepLinkResolver: NotificationDeepLinkResolver,
) {

    fun showNotification(
        notificationId: Int,
        title: String,
        body: String,
        data: Map<String, String>,
    ) {
        createChannelIfNeeded()

        val destination = deepLinkResolver.resolveFromFcmData(data) ?: DeepLinkDestination.Notifications
        val route = deepLinkResolver.routeForDestination(destination)

        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(MainActivity.EXTRA_DEEP_LINK_ROUTE, route)
            data.forEach { (key, value) -> putExtra("fcm_$key", value) }
        }

        val pendingIntent = PendingIntent.getActivity(
            context,
            notificationId,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

        NotificationManagerCompat.from(context).notify(notificationId, notification)
    }

    private fun createChannelIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.fcm_notification_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        )
        manager.createNotificationChannel(channel)
    }

    companion object {
        private const val CHANNEL_ID = "olasentra_staff_alerts"
    }
}
