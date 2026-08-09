package com.olasentra.staff.domain.model

data class CachedResource<T>(
    val data: T?,
    val lastSyncedAtEpochMs: Long?,
    val isRefreshing: Boolean,
    val errorMessage: String?,
    val isFromCache: Boolean,
)
