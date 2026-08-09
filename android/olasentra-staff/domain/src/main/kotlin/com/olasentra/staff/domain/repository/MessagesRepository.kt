package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.MessagesOverview
import kotlinx.coroutines.flow.Flow

interface MessagesRepository {
    fun observeMessages(): Flow<CachedResource<MessagesOverview>>

    suspend fun refreshMessages()
}
