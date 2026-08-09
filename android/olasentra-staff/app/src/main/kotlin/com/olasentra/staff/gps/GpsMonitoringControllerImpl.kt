package com.olasentra.staff.gps

import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat
import com.olasentra.staff.domain.repository.GpsMonitoringController
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class GpsMonitoringControllerImpl @Inject constructor(
    @ApplicationContext private val context: Context,
) : GpsMonitoringController {

    override fun startMonitoring(registrationId: Long) {
        val intent = Intent(context, GpsPingForegroundService::class.java).apply {
            putExtra(GpsPingForegroundService.EXTRA_REGISTRATION_ID, registrationId)
        }
        ContextCompat.startForegroundService(context, intent)
    }

    override fun stopMonitoring() {
        context.stopService(Intent(context, GpsPingForegroundService::class.java))
    }
}
