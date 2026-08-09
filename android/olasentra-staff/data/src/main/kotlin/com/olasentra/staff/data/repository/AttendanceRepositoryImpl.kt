package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.dao.OfflineSyncQueueDao
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.CheckinRequest
import com.olasentra.staff.core.network.dto.CheckinResponse
import com.olasentra.staff.core.network.dto.CheckoutRequest
import com.olasentra.staff.core.network.dto.CheckoutResponse
import com.olasentra.staff.core.network.dto.GpsPingRequest
import com.olasentra.staff.core.network.dto.GpsPingResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.data.remote.mapper.GpsMapper
import com.olasentra.staff.domain.model.AttendanceActionResult
import com.olasentra.staff.domain.repository.AttendanceRepository
import com.olasentra.staff.domain.repository.GpsRepository
import com.olasentra.staff.domain.repository.OfflineSyncRepository
import com.squareup.moshi.Moshi
import com.squareup.moshi.Types
import java.io.IOException
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.math.roundToInt

@Singleton
class AttendanceRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCallHandler: ApiCallHandler,
    private val offlineSyncRepository: OfflineSyncRepository,
    private val offlineSyncQueueDao: OfflineSyncQueueDao,
    private val gpsRepository: GpsRepository,
    private val gpsMapper: GpsMapper,
    private val moshi: Moshi,
) : AttendanceRepository {

    private val payloadAdapter by lazy {
        val type = Types.newParameterizedType(Map::class.java, String::class.java, Any::class.java)
        moshi.adapter<Map<String, Any?>>(type)
    }

    override suspend fun checkIn(
        registrationId: Long,
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
    ): AttendanceActionResult {
        val clientId = checkInClientId(registrationId)
        if (offlineSyncQueueDao.hasPendingClientId(clientId)) {
            return AttendanceActionResult(
                success = true,
                message = "Check-in already queued for sync",
                queuedOffline = true,
            )
        }

        val request = CheckinRequest(
            registrationId = registrationId,
            signLat = latitude,
            signLng = longitude,
            signAccuracyM = accuracyMeters.roundToInt(),
        )

        val result = apiCallHandler.safeApiCall { api.postCheckin(request) }
        return when (result) {
            is ApiResult.Success -> handleCheckInSuccess(result.data)
            is ApiResult.Error -> {
                if (result.throwable is IOException) {
                    enqueueOfflineAction(
                        clientId = clientId,
                        action = ACTION_CHECKIN,
                        payload = request.toPayloadMap(),
                        humanMessage = "Check-in saved offline and will sync when connected",
                    )
                } else {
                    mapHttpError(result.message, result.code)
                }
            }
            ApiResult.Loading -> errorResult("Unexpected state")
        }
    }

    override suspend fun checkOut(
        registrationId: Long,
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
    ): AttendanceActionResult {
        val clientId = checkOutClientId(registrationId)
        if (offlineSyncQueueDao.hasPendingClientId(clientId)) {
            return AttendanceActionResult(
                success = true,
                message = "Check-out already queued for sync",
                queuedOffline = true,
            )
        }

        val request = CheckoutRequest(
            registrationId = registrationId,
            signLat = latitude,
            signLng = longitude,
            signAccuracyM = accuracyMeters.roundToInt(),
        )

        val result = apiCallHandler.safeApiCall { api.postCheckout(request) }
        return when (result) {
            is ApiResult.Success -> handleCheckOutSuccess(result.data)
            is ApiResult.Error -> {
                if (result.throwable is IOException) {
                    enqueueOfflineAction(
                        clientId = clientId,
                        action = ACTION_CHECKOUT,
                        payload = request.toPayloadMap(),
                        humanMessage = "Check-out saved offline and will sync when connected",
                    )
                } else {
                    mapHttpError(result.message, result.code)
                }
            }
            ApiResult.Loading -> errorResult("Unexpected state")
        }
    }

    override suspend fun sendGpsPing(
        latitude: Double,
        longitude: Double,
        accuracyMeters: Float,
        registrationId: Long?,
        queueIfOffline: Boolean,
    ): AttendanceActionResult {
        val request = GpsPingRequest(
            signLat = latitude,
            signLng = longitude,
            signAccuracyM = accuracyMeters.roundToInt(),
            registrationId = registrationId,
        )

        val result = apiCallHandler.safeApiCall { api.postGpsPing(request) }
        return when (result) {
            is ApiResult.Success -> {
                val response = result.data
                AttendanceActionResult(
                    success = response.ok == true,
                    message = response.message ?: "GPS ping sent",
                    venueDistance = gpsMapper.toVenueDistanceInfo(response.venueDistance),
                )
            }
            is ApiResult.Error -> {
                if (queueIfOffline && result.throwable is IOException) {
                    enqueueOfflineAction(
                        clientId = pingClientId(),
                        action = ACTION_GPS_PING,
                        payload = request.toPayloadMap(),
                        humanMessage = "GPS ping queued offline",
                    )
                } else {
                    mapHttpError(result.message, result.code)
                }
            }
            ApiResult.Loading -> errorResult("Unexpected state")
        }
    }

    override suspend fun syncPendingOfflineActions(): Result<Unit> {
        return try {
            offlineSyncRepository.syncPendingBatch(batchSize = 25)
            gpsRepository.refreshGpsStatus(null)
            Result.success(Unit)
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }

    override suspend fun hasPendingCheckIn(registrationId: Long): Boolean {
        return offlineSyncQueueDao.hasPendingClientId(checkInClientId(registrationId))
    }

    override suspend fun hasPendingCheckOut(registrationId: Long): Boolean {
        return offlineSyncQueueDao.hasPendingClientId(checkOutClientId(registrationId))
    }

    private suspend fun handleCheckInSuccess(response: CheckinResponse): AttendanceActionResult {
        gpsRepository.refreshGpsStatus(response.registrationId)
        val already = response.already == true ||
            response.checkInStatus == "already_checked_in"
        return AttendanceActionResult(
            success = response.ok == true,
            message = response.message ?: if (already) "Already checked in" else "Checked in successfully",
            alreadySubmitted = already,
            monitoringRequired = response.monitoringRequired == true,
            venueDistance = gpsMapper.toVenueDistanceInfo(response.venueDistance),
        )
    }

    private suspend fun handleCheckOutSuccess(response: CheckoutResponse): AttendanceActionResult {
        gpsRepository.refreshGpsStatus(response.registrationId)
        val already = response.already == true ||
            response.checkOutStatus == "already_checked_out"
        return AttendanceActionResult(
            success = response.ok == true,
            message = response.message ?: if (already) "Already checked out" else "Checked out successfully",
            alreadySubmitted = already,
            hoursWorked = response.hoursWorked,
            venueDistance = gpsMapper.toVenueDistanceInfo(response.venueDistance),
        )
    }

    private suspend fun enqueueOfflineAction(
        clientId: String,
        action: String,
        payload: Map<String, Any?>,
        humanMessage: String,
    ): AttendanceActionResult {
        val payloadJson = payloadAdapter.toJson(payload)
        offlineSyncRepository.enqueue(clientId, action, payloadJson)
        return AttendanceActionResult(
            success = true,
            message = humanMessage,
            queuedOffline = true,
        )
    }

    private fun mapHttpError(message: String, code: Int?): AttendanceActionResult {
        val normalized = message.lowercase()
        val duplicate = code == 409 ||
            normalized.contains("already") ||
            normalized.contains("duplicate")
        return AttendanceActionResult(
            success = duplicate,
            message = message,
            alreadySubmitted = duplicate,
        )
    }

    private fun errorResult(message: String): AttendanceActionResult {
        return AttendanceActionResult(success = false, message = message)
    }

    private fun checkInClientId(registrationId: Long): String = "checkin-reg-$registrationId"

    private fun checkOutClientId(registrationId: Long): String = "checkout-reg-$registrationId"

    private fun pingClientId(): String = "gps-ping-${UUID.randomUUID()}"

    private fun CheckinRequest.toPayloadMap(): Map<String, Any?> = mapOf(
        "registration_id" to registrationId,
        "sign_lat" to signLat,
        "sign_lng" to signLng,
        "sign_accuracy_m" to signAccuracyM,
    )

    private fun CheckoutRequest.toPayloadMap(): Map<String, Any?> = mapOf(
        "registration_id" to registrationId,
        "sign_lat" to signLat,
        "sign_lng" to signLng,
        "sign_accuracy_m" to signAccuracyM,
    )

    private fun GpsPingRequest.toPayloadMap(): Map<String, Any?> = mapOf(
        "sign_lat" to signLat,
        "sign_lng" to signLng,
        "sign_accuracy_m" to signAccuracyM,
        "registration_id" to registrationId,
    )

    private companion object {
        const val ACTION_CHECKIN = "checkin"
        const val ACTION_CHECKOUT = "checkout"
        const val ACTION_GPS_PING = "gps_ping"
    }
}
