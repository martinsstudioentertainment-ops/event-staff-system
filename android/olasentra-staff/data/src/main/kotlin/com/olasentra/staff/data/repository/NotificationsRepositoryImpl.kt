package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.NotificationsListResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.NotificationsMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.NotificationsOverview
import com.olasentra.staff.domain.repository.NotificationsRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class NotificationsRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val notificationsMapper: NotificationsMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : NotificationsRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)
    private var lastCategoryFilter: String? = null

    override fun observeNotifications(category: String?): Flow<CachedResource<NotificationsOverview>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.NOTIFICATIONS),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val overview = cacheEntity?.payloadJson?.let(::decodeNotifications)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshNotifications(category: String?) {
        lastCategoryFilter = category
        refreshState.value = true
        errorState.value = null

        val result = apiCallHandler.safeApiCall {
            api.getNotifications(category = category?.takeIf { it.isNotBlank() })
        }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "Notifications unavailable"
                } else {
                    if (category.isNullOrBlank()) {
                        persistNotifications(response)
                    } else {
                        mergeCategoryRefresh(response, category)
                    }
                    errorState.value = null
                }
            }

            is ApiResult.Error -> errorState.value = result.message
            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    override suspend fun markNotificationRead(notificationId: Long): Result<Unit> {
        val result = apiCallHandler.safeApiCall {
            api.postNotificationMarkRead(notificationId)
        }
        return when (result) {
            is ApiResult.Success -> {
                if (result.data.ok == true) {
                    refreshNotifications(lastCategoryFilter)
                    Result.success(Unit)
                } else {
                    Result.failure(IllegalStateException("Could not mark notification read"))
                }
            }
            is ApiResult.Error -> Result.failure(result.throwable ?: IllegalStateException(result.message))
            ApiResult.Loading -> Result.failure(IllegalStateException("Unexpected state"))
        }
    }

    override suspend fun markAllNotificationsRead(): Result<Unit> {
        val result = apiCallHandler.safeApiCall { api.postNotificationsMarkAllRead() }
        return when (result) {
            is ApiResult.Success -> {
                if (result.data.ok == true) {
                    refreshNotifications(lastCategoryFilter)
                    Result.success(Unit)
                } else {
                    Result.failure(IllegalStateException("Could not mark all read"))
                }
            }
            is ApiResult.Error -> Result.failure(result.throwable ?: IllegalStateException(result.message))
            ApiResult.Loading -> Result.failure(IllegalStateException("Unexpected state"))
        }
    }

    private suspend fun persistNotifications(response: NotificationsListResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.NOTIFICATIONS,
                    payloadJson = moshi.adapter(NotificationsListResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private suspend fun mergeCategoryRefresh(response: NotificationsListResponse, category: String) {
        withContext(dispatchers.io) {
            val cached = apiCacheDao.get(ApiCacheKeys.NOTIFICATIONS)?.payloadJson
                ?.let { moshi.adapter(NotificationsListResponse::class.java).fromJson(it) }
            if (cached == null) {
                persistNotifications(response)
                return@withContext
            }
            val refreshedIds = response.notifications.orEmpty().mapNotNull { it.id }.toSet()
            val mergedNotifications = cached.notifications.orEmpty().map { item ->
                if (item.id in refreshedIds) {
                    response.notifications.orEmpty().firstOrNull { it.id == item.id } ?: item
                } else {
                    item
                }
            }
            persistNotifications(
                cached.copy(
                    notifications = mergedNotifications,
                    unreadCount = response.unreadCount ?: cached.unreadCount,
                ),
            )
        }
    }

    private fun decodeNotifications(payloadJson: String): NotificationsOverview? {
        return runCatching {
            val response = moshi.adapter(NotificationsListResponse::class.java).fromJson(payloadJson)
                ?: return null
            notificationsMapper.toNotificationsOverview(response)
        }.getOrNull()
    }
}
