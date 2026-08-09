package com.olasentra.staff.domain.model

data class OfflineSyncItem(
    val id: Long = 0,
    val clientId: String,
    val action: String,
    val payloadJson: String,
    val status: String,
    val createdAtEpochMillis: Long,
    val lastAttemptAtEpochMillis: Long? = null,
)

data class OfflineSyncBatchResult(
    val synced: Int,
    val failed: Int,
    val conflicts: Int,
    val duplicates: Int,
)
