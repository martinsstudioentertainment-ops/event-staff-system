package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.GpsStatusSummary
import kotlinx.coroutines.flow.Flow

interface GpsRepository {
    fun observeGpsStatus(): Flow<CachedResource<GpsStatusSummary>>

    suspend fun refreshGpsStatus(registrationId: Long? = null)
}
