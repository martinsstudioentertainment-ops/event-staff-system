package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.AttendanceActionResult

interface AttendanceRepository {
    suspend fun checkIn(
        registrationId: Long,
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
    ): AttendanceActionResult

    suspend fun checkOut(
        registrationId: Long,
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
    ): AttendanceActionResult

    suspend fun sendGpsPing(
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
        registrationId: Long?,
        queueIfOffline: Boolean = true,
    ): AttendanceActionResult

    suspend fun syncPendingOfflineActions(): Result<Unit>

    suspend fun hasPendingCheckIn(registrationId: Long): Boolean

    suspend fun hasPendingCheckOut(registrationId: Long): Boolean
}
