package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.AvailabilityActionResult
import com.olasentra.staff.domain.model.AvailabilityOverview
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.LeaveActionResult
import kotlinx.coroutines.flow.Flow

interface AvailabilityRepository {
    fun observeAvailability(month: String): Flow<CachedResource<AvailabilityOverview>>

    suspend fun refreshAvailability(month: String)

    suspend fun setDayStatus(
        date: String,
        status: String,
        notes: String?,
        month: String,
    ): AvailabilityActionResult

    suspend fun submitLeave(
        date: String,
        type: String,
        notes: String?,
        month: String,
    ): LeaveActionResult
}
