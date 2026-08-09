package com.olasentra.staff.core.database.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Update
import com.olasentra.staff.core.database.entity.OfflineSyncQueueEntity
import com.olasentra.staff.core.database.entity.OfflineSyncStatus
import kotlinx.coroutines.flow.Flow

@Dao
interface OfflineSyncQueueDao {

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(entity: OfflineSyncQueueEntity): Long

    @Update
    suspend fun update(entity: OfflineSyncQueueEntity)

    @Query("SELECT * FROM offline_sync_queue WHERE id = :id LIMIT 1")
    suspend fun getById(id: Long): OfflineSyncQueueEntity?

    @Query("SELECT * FROM offline_sync_queue WHERE client_id = :clientId LIMIT 1")
    suspend fun getByClientId(clientId: String): OfflineSyncQueueEntity?

    @Query(
        """
        SELECT * FROM offline_sync_queue
        WHERE status IN (:statuses)
        ORDER BY created_at ASC
        """,
    )
    suspend fun getByStatuses(statuses: List<String>): List<OfflineSyncQueueEntity>

    @Query(
        """
        SELECT * FROM offline_sync_queue
        WHERE status IN (:statuses)
        ORDER BY created_at ASC
        """,
    )
    fun observeByStatuses(statuses: List<String>): Flow<List<OfflineSyncQueueEntity>>

    @Query(
        """
        SELECT * FROM offline_sync_queue
        WHERE status = :status
        ORDER BY created_at ASC
        LIMIT :limit
        """,
    )
    suspend fun getOldestByStatus(status: String, limit: Int): List<OfflineSyncQueueEntity>

    @Query(
        """
        UPDATE offline_sync_queue
        SET status = :status, last_attempt_at = :lastAttemptAt
        WHERE id = :id
        """,
    )
    suspend fun updateStatus(
        id: Long,
        status: String,
        lastAttemptAt: Long?,
    )

    @Query("DELETE FROM offline_sync_queue WHERE status = :status")
    suspend fun deleteByStatus(status: String)

    @Query("DELETE FROM offline_sync_queue WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("SELECT COUNT(*) FROM offline_sync_queue WHERE status = :status")
    suspend fun countByStatus(status: String): Int

    @Query("SELECT COUNT(*) FROM offline_sync_queue WHERE status = :status")
    fun observeCountByStatus(status: String): Flow<Int>

    suspend fun getPendingBatch(limit: Int): List<OfflineSyncQueueEntity> =
        getOldestByStatus(OfflineSyncStatus.PENDING, limit)

    suspend fun markInProgress(id: Long, timestamp: Long) {
        updateStatus(id, OfflineSyncStatus.IN_PROGRESS, timestamp)
    }

    suspend fun markCompleted(id: Long, timestamp: Long) {
        updateStatus(id, OfflineSyncStatus.COMPLETED, timestamp)
    }

    suspend fun markFailed(id: Long, timestamp: Long) {
        updateStatus(id, OfflineSyncStatus.FAILED, timestamp)
    }

    @Query("SELECT COUNT(*) > 0 FROM offline_sync_queue WHERE client_id = :clientId AND status IN (:statuses)")
    suspend fun hasPendingClientId(clientId: String, statuses: List<String>): Boolean

    suspend fun hasPendingClientId(clientId: String): Boolean =
        hasPendingClientId(
            clientId = clientId,
            statuses = listOf(
                OfflineSyncStatus.PENDING,
                OfflineSyncStatus.IN_PROGRESS,
            ),
        )
}
