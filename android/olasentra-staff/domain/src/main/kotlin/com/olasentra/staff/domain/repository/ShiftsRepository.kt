package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.ShiftDetail
import com.olasentra.staff.domain.model.ShiftFilter
import com.olasentra.staff.domain.model.ShiftsOverview
import kotlinx.coroutines.flow.Flow

interface ShiftsRepository {
    fun observeShifts(filter: ShiftFilter): Flow<CachedResource<ShiftsOverview>>

    suspend fun refreshShifts(filter: ShiftFilter)

    fun observeShiftDetail(registrationId: Long): Flow<CachedResource<ShiftDetail>>

    suspend fun refreshShiftDetail(registrationId: Long)
}
