package com.olasentra.staff.core.database.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "offline_sync_queue",
    indices = [
        Index(value = ["client_id"], unique = true),
        Index(value = ["status"]),
        Index(value = ["created_at"]),
    ],
)
data class OfflineSyncQueueEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    @ColumnInfo(name = "client_id")
    val clientId: String,
    val action: String,
    @ColumnInfo(name = "payload_json")
    val payloadJson: String,
    val status: String,
    @ColumnInfo(name = "created_at")
    val createdAt: Long,
    @ColumnInfo(name = "last_attempt_at")
    val lastAttemptAt: Long? = null,
)

object OfflineSyncStatus {
    const val PENDING = "PENDING"
    const val IN_PROGRESS = "IN_PROGRESS"
    const val COMPLETED = "COMPLETED"
    const val FAILED = "FAILED"
}
