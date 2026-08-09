package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.dao.OfflineSyncQueueDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.AvailabilityResponse
import com.olasentra.staff.core.network.dto.AvailabilitySetRequest
import com.olasentra.staff.core.network.dto.LeaveRequest
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.AvailabilityMapper
import com.olasentra.staff.domain.model.AvailabilityActionResult
import com.olasentra.staff.domain.model.AvailabilityOverview
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.LeaveActionResult
import com.olasentra.staff.domain.repository.AvailabilityRepository
import com.olasentra.staff.domain.repository.OfflineSyncRepository
import com.squareup.moshi.Moshi
import com.squareup.moshi.Types
import java.io.IOException
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class AvailabilityRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val availabilityMapper: AvailabilityMapper,
    private val offlineSyncRepository: OfflineSyncRepository,
    private val offlineSyncQueueDao: OfflineSyncQueueDao,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : AvailabilityRepository {

    private val refreshState = MutableStateFlow<Map<String, Boolean>>(emptyMap())
    private val errorState = MutableStateFlow<Map<String, String?>>(emptyMap())

    private val payloadAdapter by lazy {
        val type = Types.newParameterizedType(Map::class.java, String::class.java, Any::class.java)
        moshi.adapter<Map<String, Any?>>(type)
    }

    override fun observeAvailability(month: String): Flow<CachedResource<AvailabilityOverview>> {
        val cacheKey = ApiCacheKeys.availability(month)
        return combine(
            apiCacheDao.observe(cacheKey),
            refreshState,
            errorState,
        ) { cacheEntity, refreshingMap, errorMap ->
            val overview = cacheEntity?.payloadJson?.let(::decodeAvailability)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = refreshingMap[month] == true,
                errorMessage = errorMap[month],
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshAvailability(month: String) {
        setRefreshing(month, true)
        setError(month, null)

        when (val result = apiCallHandler.safeApiCall { api.getAvailability(month) }) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    setError(month, "Availability unavailable")
                } else {
                    persistAvailability(month, response)
                    setError(month, null)
                }
            }
            is ApiResult.Error -> setError(month, result.message)
            ApiResult.Loading -> Unit
        }

        setRefreshing(month, false)
    }

    override suspend fun setDayStatus(
        date: String,
        status: String,
        notes: String?,
        month: String,
    ): AvailabilityActionResult {
        val clientId = "availability-$date-$status"
        if (offlineSyncQueueDao.hasPendingClientId(clientId)) {
            return AvailabilityActionResult(
                success = true,
                message = "Availability change already queued for sync",
                queuedOffline = true,
            )
        }

        val request = AvailabilitySetRequest(status = status, notes = notes?.takeIf { it.isNotBlank() })
        when (val result = apiCallHandler.safeApiCall { api.putAvailability(date, request) }) {
            is ApiResult.Success -> {
                val response = result.data
                val day = response.day?.let { availabilityMapper.toAvailabilityDay(it) }
                if (response.ok == true) {
                    refreshAvailability(month)
                }
                return AvailabilityActionResult(
                    success = response.ok == true,
                    message = response.message ?: if (response.ok == true) "Availability updated" else "Update failed",
                    day = day,
                )
            }
            is ApiResult.Error -> {
                if (result.throwable is IOException) {
                    val payload = mapOf(
                        "date" to date,
                        "status" to status,
                        "notes" to notes,
                    )
                    offlineSyncRepository.enqueue(
                        clientId = clientId,
                        action = ACTION_AVAILABILITY_SET,
                        payloadJson = payloadAdapter.toJson(payload),
                    )
                    return AvailabilityActionResult(
                        success = true,
                        message = "Saved offline and will sync when connected",
                        queuedOffline = true,
                    )
                }
                return AvailabilityActionResult(success = false, message = result.message)
            }
            ApiResult.Loading -> return AvailabilityActionResult(success = false, message = "Unexpected state")
        }
    }

    override suspend fun submitLeave(
        date: String,
        type: String,
        notes: String?,
        month: String,
    ): LeaveActionResult {
        val clientId = "leave-$date-$type"
        if (offlineSyncQueueDao.hasPendingClientId(clientId)) {
            return LeaveActionResult(
                success = true,
                message = "Leave request already queued for sync",
                queuedOffline = true,
            )
        }

        val request = LeaveRequest(date = date, type = type, notes = notes?.takeIf { it.isNotBlank() })
        when (val result = apiCallHandler.safeApiCall { api.postLeave(request) }) {
            is ApiResult.Success -> {
                val response = result.data
                val day = response.day?.let { availabilityMapper.toAvailabilityDay(it) }
                if (response.ok == true) {
                    refreshAvailability(month)
                }
                return LeaveActionResult(
                    success = response.ok == true,
                    message = response.message ?: if (response.ok == true) "Leave request submitted" else "Request failed",
                    day = day,
                )
            }
            is ApiResult.Error -> {
                if (result.throwable is IOException) {
                    val payload = mapOf(
                        "date" to date,
                        "type" to type,
                        "notes" to notes,
                    )
                    offlineSyncRepository.enqueue(
                        clientId = clientId,
                        action = ACTION_LEAVE_REQUEST,
                        payloadJson = payloadAdapter.toJson(payload),
                    )
                    return LeaveActionResult(
                        success = true,
                        message = "Leave request saved offline and will sync when connected",
                        queuedOffline = true,
                    )
                }
                return LeaveActionResult(success = false, message = result.message)
            }
            ApiResult.Loading -> return LeaveActionResult(success = false, message = "Unexpected state")
        }
    }

    private suspend fun persistAvailability(month: String, response: AvailabilityResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.availability(month),
                    payloadJson = moshi.adapter(AvailabilityResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeAvailability(payloadJson: String): AvailabilityOverview? {
        return runCatching {
            val response = moshi.adapter(AvailabilityResponse::class.java).fromJson(payloadJson)
                ?: return null
            availabilityMapper.toAvailabilityOverview(response)
        }.getOrNull()
    }

    private fun setRefreshing(month: String, refreshing: Boolean) {
        refreshState.value = refreshState.value.toMutableMap().apply { put(month, refreshing) }
    }

    private fun setError(month: String, message: String?) {
        errorState.value = errorState.value.toMutableMap().apply { put(month, message) }
    }

    private companion object {
        const val ACTION_AVAILABILITY_SET = "availability_set"
        const val ACTION_LEAVE_REQUEST = "leave_request"
    }
}
