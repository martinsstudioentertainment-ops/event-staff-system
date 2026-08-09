package com.olasentra.staff.gps

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.os.IBinder
import androidx.core.app.NotificationCompat
import com.olasentra.staff.R
import com.olasentra.staff.core.location.LocationPermissionChecker
import com.olasentra.staff.core.location.LocationProvider
import com.olasentra.staff.domain.repository.AttendanceRepository
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

@AndroidEntryPoint
class GpsPingForegroundService : Service() {

    @Inject lateinit var locationProvider: LocationProvider
    @Inject lateinit var locationPermissionChecker: LocationPermissionChecker
    @Inject lateinit var attendanceRepository: AttendanceRepository

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var pingJob: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val registrationId = intent?.getLongExtra(EXTRA_REGISTRATION_ID, INVALID_REGISTRATION_ID)
            ?.takeIf { it != INVALID_REGISTRATION_ID }

        createNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification())

        pingJob?.cancel()
        pingJob = serviceScope.launch {
            while (isActive) {
                if (!locationPermissionChecker.hasFineLocationPermission()) {
                    break
                }
                val locationResult = locationProvider.getCurrentLocation()
                locationResult.onSuccess { location ->
                    attendanceRepository.sendGpsPing(
                        latitude = location.latitude,
                        longitude = location.longitude,
                        accuracyMeters = location.accuracyMeters,
                        registrationId = registrationId,
                        queueIfOffline = true,
                    )
                }
                delay(PING_INTERVAL_MS)
            }
            stopSelf()
        }

        return START_STICKY
    }

    override fun onDestroy() {
        pingJob?.cancel()
        serviceScope.cancel()
        super.onDestroy()
    }

    private fun buildNotification(): Notification {
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.gps_monitoring_notification_title))
            .setContentText(getString(R.string.gps_monitoring_notification_body))
            .setSmallIcon(R.mipmap.ic_launcher)
            .setOngoing(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()
    }

    private fun createNotificationChannel() {
        val manager = getSystemService(NotificationManager::class.java)
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.gps_monitoring_channel_name),
            NotificationManager.IMPORTANCE_LOW,
        )
        manager.createNotificationChannel(channel)
    }

    companion object {
        const val EXTRA_REGISTRATION_ID = "registration_id"
        private const val INVALID_REGISTRATION_ID = -1L
        private const val CHANNEL_ID = "olasentra_gps_monitoring"
        private const val NOTIFICATION_ID = 4101
        private const val PING_INTERVAL_MS = 5 * 60 * 1000L
    }
}
