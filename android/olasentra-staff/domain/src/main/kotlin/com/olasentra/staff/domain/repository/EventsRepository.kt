package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.AvailableEventsOverview
import com.olasentra.staff.domain.model.CachedResource
import kotlinx.coroutines.flow.Flow

interface EventsRepository {
    fun observeAvailableEvents(): Flow<CachedResource<AvailableEventsOverview>>

    suspend fun refreshAvailableEvents()

    suspend fun registerForEvents(eventIds: List<Long>): Result<String>
}
