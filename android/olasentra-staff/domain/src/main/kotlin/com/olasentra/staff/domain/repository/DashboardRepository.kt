package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DashboardSummary
import kotlinx.coroutines.flow.Flow

interface DashboardRepository {
    fun observeDashboard(): Flow<CachedResource<DashboardSummary>>

    suspend fun refreshDashboard()
}
