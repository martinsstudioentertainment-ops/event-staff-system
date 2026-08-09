package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.MobileConfig
import kotlinx.coroutines.flow.Flow

interface ConfigRepository {
    suspend fun fetchConfig(appVersion: String? = null): MobileConfig

    fun observeCachedConfig(): Flow<MobileConfig?>
}
