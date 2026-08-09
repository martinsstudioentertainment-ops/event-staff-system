package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.dao.OfflineSyncQueueDao
import com.olasentra.staff.core.database.entity.OfflineSyncQueueEntity
import com.olasentra.staff.core.database.entity.OfflineSyncStatus
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.OfflineSyncRequest
import com.olasentra.staff.core.network.dto.OfflineSyncRequestItem
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.domain.model.OfflineSyncBatchResult
import com.olasentra.staff.domain.model.OfflineSyncItem
import com.olasentra.staff.domain.repository.OfflineSyncRepository
import com.squareup.moshi.Moshi
import com.squareup.moshi.Types
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

@Singleton
class OfflineSyncRepositoryImpl @Inject constructor(
    private val dao: OfflineSyncQueueDao,
    private val api: MobileApiService,
    private val apiCallHandler: ApiCallHandler,
    private val moshi: Moshi,
) : OfflineSyncRepository {

    private val payloadMapAdapter by lazy {
        val type = Types.newParameterizedType(Map::class.java, String::class.java, Any::class.java)
        moshi.adapter<Map<String, Any?>>(type)
    }

    override suspend fun enqueue(clientId: String, action: String, payloadJson: String): Long {
        val entity = OfflineSyncQueueEntity(
            clientId = clientId,
            action = action,
            payloadJson = payloadJson,
            status = OfflineSyncStatus.PENDING,
            createdAt = System.currentTimeMillis(),
        )
        return dao.insert(entity)
    }

    override suspend fun syncPendingBatch(batchSize: Int): OfflineSyncBatchResult {
        val pending = dao.getPendingBatch(batchSize)
        if (pending.isEmpty()) {
            return OfflineSyncBatchResult(
                synced = 0,
                failed = 0,
                conflicts = 0,
                duplicates = 0,
            )
        }

        val now = System.currentTimeMillis()
        pending.forEach { entity ->
            dao.markInProgress(entity.id, now)
        }

        val requestItems = pending.map { entity ->
            OfflineSyncRequestItem(
                clientId = entity.clientId,
                action = entity.action,
                payload = parsePayload(entity.payloadJson),
            )
        }

        val result = apiCallHandler.safeApiCall {
            api.postOfflineSync(OfflineSyncRequest(items = requestItems))
        }

        return when (result) {
            is ApiResult.Success -> {
                applySyncResults(pending, result.data.results.orEmpty(), now)
                OfflineSyncBatchResult(
                    synced = result.data.synced ?: 0,
                    failed = result.data.failed ?: 0,
                    conflicts = result.data.conflicts ?: 0,
                    duplicates = result.data.duplicates ?: 0,
                )
            }
            is ApiResult.Error -> {
                pending.forEach { entity ->
                    dao.markFailed(entity.id, now)
                }
                throw OfflineSyncRepositoryException(result.message, result.code)
            }
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }
    }

    override fun observePendingItems(): Flow<List<OfflineSyncItem>> {
        return dao.observeByStatuses(
            listOf(
                OfflineSyncStatus.PENDING,
                OfflineSyncStatus.IN_PROGRESS,
                OfflineSyncStatus.FAILED,
            ),
        ).map { entities -> entities.map(::toDomainItem) }
    }

    override fun observePendingCount(): Flow<Int> {
        return dao.observeCountByStatus(OfflineSyncStatus.PENDING)
    }

    private suspend fun applySyncResults(
        pending: List<OfflineSyncQueueEntity>,
        results: List<com.olasentra.staff.core.network.dto.OfflineSyncResultItemDto>,
        timestamp: Long,
    ) {
        val resultsByClientId = results.associateBy { it.clientId.orEmpty() }

        pending.forEach { entity ->
            val itemResult = resultsByClientId[entity.clientId]
            when (itemResult?.status) {
                "synced", "duplicate" -> dao.markCompleted(entity.id, timestamp)
                "conflict", "failed", null -> dao.markFailed(entity.id, timestamp)
                else -> dao.markFailed(entity.id, timestamp)
            }
        }
    }

    private fun parsePayload(payloadJson: String): Map<String, Any?> {
        return payloadMapAdapter.fromJson(payloadJson) ?: emptyMap()
    }

    private fun toDomainItem(entity: OfflineSyncQueueEntity): OfflineSyncItem {
        return OfflineSyncItem(
            id = entity.id,
            clientId = entity.clientId,
            action = entity.action,
            payloadJson = entity.payloadJson,
            status = entity.status,
            createdAtEpochMillis = entity.createdAt,
            lastAttemptAtEpochMillis = entity.lastAttemptAt,
        )
    }
}

class OfflineSyncRepositoryException(
    override val message: String,
    val code: Int? = null,
) : Exception(message)
