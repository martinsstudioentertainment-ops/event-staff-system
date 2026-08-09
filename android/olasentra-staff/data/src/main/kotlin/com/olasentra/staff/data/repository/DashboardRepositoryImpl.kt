package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.DashboardResponse
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.DashboardMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DashboardSummary
import com.olasentra.staff.domain.repository.DashboardRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class DashboardRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val dashboardMapper: DashboardMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : DashboardRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeDashboard(): Flow<CachedResource<DashboardSummary>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.DASHBOARD),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val summary = cacheEntity?.payloadJson?.let(::decodeDashboardSummary)
            CachedResource(
                data = summary,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshDashboard() {
        refreshState.value = true
        errorState.value = null

        val result = apiCallHandler.safeApiCall { api.getDashboard() }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "Dashboard unavailable"
                } else {
                    val summary = dashboardMapper.toDashboardSummary(response)
                    persistDashboard(response)
                    errorState.value = null
                }
            }

            is ApiResult.Error -> {
                if (apiCacheDao.get(ApiCacheKeys.DASHBOARD) == null) {
                    errorState.value = result.message
                } else {
                    errorState.value = result.message
                }
            }

            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    private suspend fun persistDashboard(response: DashboardResponse) {
        withContext(dispatchers.io) {
            val payloadJson = moshi.adapter(DashboardResponse::class.java).toJson(response)
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.DASHBOARD,
                    payloadJson = payloadJson,
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeDashboardSummary(payloadJson: String): DashboardSummary? {
        return runCatching {
            val response = moshi.adapter(DashboardResponse::class.java).fromJson(payloadJson)
                ?: return null
            dashboardMapper.toDashboardSummary(response)
        }.getOrNull()
    }
}
