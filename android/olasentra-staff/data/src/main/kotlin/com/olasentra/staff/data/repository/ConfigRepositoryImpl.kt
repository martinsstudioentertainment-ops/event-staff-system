package com.olasentra.staff.data.repository

import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.data.remote.mapper.ConfigMapper
import com.olasentra.staff.domain.model.MobileConfig
import com.olasentra.staff.domain.repository.ConfigRepository
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

@Singleton
class ConfigRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCallHandler: ApiCallHandler,
    private val configMapper: ConfigMapper,
) : ConfigRepository {

    private val cachedConfig = MutableStateFlow<MobileConfig?>(null)

    override suspend fun fetchConfig(appVersion: String?): MobileConfig {
        val result = apiCallHandler.safeApiCall {
            api.getConfig(appVersion = appVersion)
        }

        val config = when (result) {
            is ApiResult.Success -> configMapper.toDomain(result.data)
            is ApiResult.Error -> throw ConfigRepositoryException(result.message, result.code)
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }

        cachedConfig.value = config
        return config
    }

    override fun observeCachedConfig(): Flow<MobileConfig?> = cachedConfig.asStateFlow()
}

class ConfigRepositoryException(
    override val message: String,
    val code: Int? = null,
) : Exception(message)
