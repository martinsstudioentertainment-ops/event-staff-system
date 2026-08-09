package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.MessagesResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.MessagesMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.MessagesOverview
import com.olasentra.staff.domain.repository.MessagesRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class MessagesRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val messagesMapper: MessagesMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : MessagesRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeMessages(): Flow<CachedResource<MessagesOverview>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.MESSAGES),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val overview = cacheEntity?.payloadJson?.let(::decodeMessages)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshMessages() {
        refreshState.value = true
        errorState.value = null

        val result = apiCallHandler.safeApiCall { api.getMessages() }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "Messages unavailable"
                } else {
                    persistMessages(response)
                    errorState.value = null
                }
            }

            is ApiResult.Error -> errorState.value = result.message
            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    private suspend fun persistMessages(response: MessagesResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.MESSAGES,
                    payloadJson = moshi.adapter(MessagesResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeMessages(payloadJson: String): MessagesOverview? {
        return runCatching {
            val response = moshi.adapter(MessagesResponse::class.java).fromJson(payloadJson)
                ?: return null
            messagesMapper.toMessagesOverview(response)
        }.getOrNull()
    }
}
