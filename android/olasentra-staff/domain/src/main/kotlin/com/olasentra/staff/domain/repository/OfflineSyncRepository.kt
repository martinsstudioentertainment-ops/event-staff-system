package com.olasentra.staff.domain.repository



import com.olasentra.staff.domain.model.OfflineSyncBatchResult

import com.olasentra.staff.domain.model.OfflineSyncItem

import kotlinx.coroutines.flow.Flow



interface OfflineSyncRepository {

    suspend fun enqueue(clientId: String, action: String, payloadJson: String): Long

    suspend fun syncPendingBatch(batchSize: Int): OfflineSyncBatchResult

    fun observePendingItems(): Flow<List<OfflineSyncItem>>

    fun observePendingCount(): Flow<Int>

}