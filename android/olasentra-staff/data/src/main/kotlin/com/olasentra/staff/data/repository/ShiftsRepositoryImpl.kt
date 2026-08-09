package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.ShiftDetailResponse
import com.olasentra.staff.core.network.dto.ShiftTodayResponse
import com.olasentra.staff.core.network.dto.ShiftsListResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.ShiftMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.ShiftDetail
import com.olasentra.staff.domain.model.ShiftFilter
import com.olasentra.staff.domain.model.ShiftsOverview
import com.olasentra.staff.domain.repository.ShiftsRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class ShiftsRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val shiftMapper: ShiftMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : ShiftsRepository {

    private val listRefreshState = MutableStateFlow<Map<ShiftFilter, Boolean>>(emptyMap())
    private val listErrorState = MutableStateFlow<Map<ShiftFilter, String?>>(emptyMap())
    private val detailRefreshState = MutableStateFlow<Map<Long, Boolean>>(emptyMap())
    private val detailErrorState = MutableStateFlow<Map<Long, String?>>(emptyMap())

    override fun observeShifts(filter: ShiftFilter): Flow<CachedResource<ShiftsOverview>> {
        val cacheKey = ApiCacheKeys.shiftsList(filter.apiValue)
        return combine(
            apiCacheDao.observe(cacheKey),
            apiCacheDao.observe(ApiCacheKeys.SHIFTS_TODAY),
            listRefreshState,
            listErrorState,
        ) { listCache, todayCache, refreshMap, errorMap ->
            val overview = decodeShiftsOverview(listCache?.payloadJson, todayCache?.payloadJson, filter)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = listCache?.syncedAtEpochMs,
                isRefreshing = refreshMap[filter] == true,
                errorMessage = errorMap[filter],
                isFromCache = listCache != null,
            )
        }
    }

    override suspend fun refreshShifts(filter: ShiftFilter) {
        setListRefreshing(filter, true)
        setListError(filter, null)

        val listResult = apiCallHandler.safeApiCall {
            api.getShifts(filter = filter.apiValue)
        }
        val todayResult = apiCallHandler.safeApiCall { api.getShiftsToday() }

        when (listResult) {
            is ApiResult.Success -> {
                val listResponse = listResult.data
                if (listResponse.ok != true) {
                    setListError(filter, "Shifts unavailable")
                } else {
                    persistShiftsList(filter, listResponse)
                    setListError(filter, null)
                }
            }

            is ApiResult.Error -> setListError(filter, listResult.message)
            ApiResult.Loading -> Unit
        }

        if (todayResult is ApiResult.Success && todayResult.data.ok == true) {
            persistShiftsToday(todayResult.data)
        }

        setListRefreshing(filter, false)
    }

    override fun observeShiftDetail(registrationId: Long): Flow<CachedResource<ShiftDetail>> {
        val cacheKey = ApiCacheKeys.shiftDetail(registrationId)
        return combine(
            apiCacheDao.observe(cacheKey),
            detailRefreshState,
            detailErrorState,
        ) { cacheEntity, refreshMap, errorMap ->
            val detail = cacheEntity?.payloadJson?.let(::decodeShiftDetail)
            CachedResource(
                data = detail,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = refreshMap[registrationId] == true,
                errorMessage = errorMap[registrationId],
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshShiftDetail(registrationId: Long) {
        setDetailRefreshing(registrationId, true)
        setDetailError(registrationId, null)

        val result = apiCallHandler.safeApiCall { api.getShiftDetail(registrationId) }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true || response.shift == null) {
                    setDetailError(registrationId, "Shift not found")
                } else {
                    persistShiftDetail(registrationId, response)
                    setDetailError(registrationId, null)
                }
            }

            is ApiResult.Error -> setDetailError(registrationId, result.message)
            ApiResult.Loading -> Unit
        }

        setDetailRefreshing(registrationId, false)
    }

    private suspend fun persistShiftsList(filter: ShiftFilter, response: ShiftsListResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.shiftsList(filter.apiValue),
                    payloadJson = moshi.adapter(ShiftsListResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private suspend fun persistShiftsToday(response: ShiftTodayResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.SHIFTS_TODAY,
                    payloadJson = moshi.adapter(ShiftTodayResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private suspend fun persistShiftDetail(registrationId: Long, response: ShiftDetailResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.shiftDetail(registrationId),
                    payloadJson = moshi.adapter(ShiftDetailResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeShiftsOverview(
        listJson: String?,
        todayJson: String?,
        filter: ShiftFilter,
    ): ShiftsOverview? {
        if (listJson.isNullOrBlank()) return null
        return runCatching {
            val listResponse = moshi.adapter(ShiftsListResponse::class.java).fromJson(listJson)
                ?: return null
            val todayResponse = todayJson?.let {
                moshi.adapter(ShiftTodayResponse::class.java).fromJson(it)
            }
            shiftMapper.toShiftsOverview(listResponse, todayResponse, filter)
        }.getOrNull()
    }

    private fun decodeShiftDetail(payloadJson: String): ShiftDetail? {
        return runCatching {
            val response = moshi.adapter(ShiftDetailResponse::class.java).fromJson(payloadJson)
                ?: return null
            val shift = response.shift ?: return null
            shiftMapper.toShiftDetail(shift)
        }.getOrNull()
    }

    private fun setListRefreshing(filter: ShiftFilter, refreshing: Boolean) {
        listRefreshState.value = listRefreshState.value.toMutableMap().apply {
            put(filter, refreshing)
        }
    }

    private fun setListError(filter: ShiftFilter, message: String?) {
        listErrorState.value = listErrorState.value.toMutableMap().apply {
            put(filter, message)
        }
    }

    private fun setDetailRefreshing(registrationId: Long, refreshing: Boolean) {
        detailRefreshState.value = detailRefreshState.value.toMutableMap().apply {
            put(registrationId, refreshing)
        }
    }

    private fun setDetailError(registrationId: Long, message: String?) {
        detailErrorState.value = detailErrorState.value.toMutableMap().apply {
            put(registrationId, message)
        }
    }
}
