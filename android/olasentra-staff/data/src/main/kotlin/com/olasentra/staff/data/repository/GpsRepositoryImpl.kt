package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.GpsStatusResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.GpsMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.GpsStatusSummary
import com.olasentra.staff.domain.repository.GpsRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class GpsRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val gpsMapper: GpsMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : GpsRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeGpsStatus(): Flow<CachedResource<GpsStatusSummary>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.GPS_STATUS),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val summary = cacheEntity?.payloadJson?.let(::decodeGpsStatus)
            CachedResource(
                data = summary,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshGpsStatus(registrationId: Long?) {
        refreshState.value = true
        errorState.value = null

        val result = apiCallHandler.safeApiCall { api.getGpsStatus(registrationId) }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "GPS status unavailable"
                } else {
                    persistGpsStatus(response)
                    errorState.value = null
                }
            }

            is ApiResult.Error -> errorState.value = result.message
            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    private suspend fun persistGpsStatus(response: GpsStatusResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.GPS_STATUS,
                    payloadJson = moshi.adapter(GpsStatusResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeGpsStatus(payloadJson: String): GpsStatusSummary? {
        return runCatching {
            val response = moshi.adapter(GpsStatusResponse::class.java).fromJson(payloadJson)
                ?: return null
            gpsMapper.toGpsStatusSummary(response)
        }.getOrNull()
    }
}
