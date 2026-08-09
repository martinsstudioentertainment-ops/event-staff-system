package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.EventsListResponse
import com.olasentra.staff.core.network.dto.EventsRegisterRequest
import com.olasentra.staff.core.network.dto.EventsRegisterResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.EventsMapper
import com.olasentra.staff.domain.model.AvailableEventsOverview
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.repository.EventsRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class EventsRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val eventsMapper: EventsMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : EventsRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeAvailableEvents(): Flow<CachedResource<AvailableEventsOverview>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.AVAILABLE_EVENTS),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val overview = cacheEntity?.payloadJson?.let(::decodeEvents)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = if (overview == null) errorMessage else null,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshAvailableEvents() {
        refreshState.value = true
        errorState.value = null

        when (val result = apiCallHandler.safeApiCall { api.getEvents() }) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "Available events unavailable"
                } else {
                    persistEvents(response)
                    errorState.value = null
                }
            }

            is ApiResult.Error -> errorState.value = result.message
            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    override suspend fun registerForEvents(eventIds: List<Long>): Result<String> {
        if (eventIds.isEmpty()) {
            return Result.failure(IllegalArgumentException("Select at least one event."))
        }

        return when (val result = apiCallHandler.safeApiCall {
            api.postEventsRegister(EventsRegisterRequest(eventIds = eventIds))
        }) {
            is ApiResult.Success -> {
                val response: EventsRegisterResponse = result.data
                if (response.ok == true) {
                    refreshAvailableEvents()
                    Result.success(response.message ?: "Registration submitted.")
                } else {
                    Result.failure(
                        IllegalStateException(response.message ?: "Registration failed."),
                    )
                }
            }

            is ApiResult.Error -> {
                Result.failure(result.throwable ?: IllegalStateException(result.message))
            }

            ApiResult.Loading -> {
                Result.failure(IllegalStateException("Unexpected state"))
            }
        }
    }

    private suspend fun persistEvents(response: EventsListResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.AVAILABLE_EVENTS,
                    payloadJson = moshi.adapter(EventsListResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeEvents(payloadJson: String): AvailableEventsOverview? {
        return runCatching {
            val response = moshi.adapter(EventsListResponse::class.java).fromJson(payloadJson)
                ?: return null
            eventsMapper.toAvailableEventsOverview(response)
        }.getOrNull()
    }
}
