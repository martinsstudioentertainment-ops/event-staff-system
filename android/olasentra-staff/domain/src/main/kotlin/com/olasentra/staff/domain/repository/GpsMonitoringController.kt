package com.olasentra.staff.domain.repository



interface GpsMonitoringController {

    fun startMonitoring(registrationId: Long)

    fun stopMonitoring()

}