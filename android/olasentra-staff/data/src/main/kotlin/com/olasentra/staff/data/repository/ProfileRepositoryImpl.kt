package com.olasentra.staff.data.repository

import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.ChangePasswordRequest
import com.olasentra.staff.core.network.dto.MeResponse
import com.olasentra.staff.core.network.dto.PatchMeRequest
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.ProfileMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.StaffProfile
import com.olasentra.staff.domain.repository.ProfileRepository
import com.squareup.moshi.Moshi
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext

@Singleton
class ProfileRepositoryImpl @Inject constructor(
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val profileMapper: ProfileMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : ProfileRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeProfile(): Flow<CachedResource<StaffProfile>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.PROFILE),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val profile = cacheEntity?.payloadJson?.let(::decodeProfile)
            CachedResource(
                data = profile,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshProfile() {
        refreshState.value = true
        errorState.value = null

        val result = apiCallHandler.safeApiCall { api.getMe() }
        when (result) {
            is ApiResult.Success -> {
                val response = result.data
                val staff = response.staff
                if (response.ok != true || staff == null) {
                    errorState.value = "Profile unavailable"
                } else {
                    profileMapper.toStaffProfile(staff)
                    persistProfile(response)
                    errorState.value = null
                }
            }

            is ApiResult.Error -> {
                errorState.value = result.message
            }

            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    override suspend fun updateProfile(mobile: String, fullAddress: String, eircode: String): StaffProfile {
        val result = apiCallHandler.safeApiCall {
            api.patchMe(
                PatchMeRequest(
                    mobile = mobile.trim().ifBlank { null },
                    fullAddress = fullAddress.trim().ifBlank { null },
                    eircode = eircode.trim().ifBlank { null },
                ),
            )
        }
        return when (result) {
            is ApiResult.Success -> {
                val response = result.data
                val staff = response.staff ?: throw IllegalStateException("Profile update failed")
                if (response.ok != true) {
                    throw IllegalStateException("Profile update failed")
                }
                val profile = profileMapper.toStaffProfile(staff)
                persistProfile(response)
                profile
            }
            is ApiResult.Error -> throw IllegalStateException(result.message)
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }
    }

    override suspend fun sendPasswordOtp() {
        val result = apiCallHandler.safeApiCall {
            api.changePassword(ChangePasswordRequest(newPassword = "", sendCode = true))
        }
        when (result) {
            is ApiResult.Success -> {
                if (result.data.ok != true) {
                    throw IllegalStateException(result.data.message ?: "Could not send code")
                }
            }
            is ApiResult.Error -> throw IllegalStateException(result.message)
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }
    }

    override suspend fun changePassword(newPassword: String, otpCode: String?, currentPassword: String?) {
        val result = apiCallHandler.safeApiCall {
            api.changePassword(
                ChangePasswordRequest(
                    newPassword = newPassword,
                    otpCode = otpCode?.trim()?.ifBlank { null },
                    currentPassword = currentPassword?.ifBlank { null },
                ),
            )
        }
        when (result) {
            is ApiResult.Success -> {
                if (result.data.ok != true) {
                    throw IllegalStateException(result.data.message ?: "Could not update password")
                }
            }
            is ApiResult.Error -> throw IllegalStateException(result.message)
            ApiResult.Loading -> throw IllegalStateException("Unexpected loading state")
        }
    }

    private suspend fun persistProfile(response: MeResponse) {
        withContext(dispatchers.io) {
            val payloadJson = moshi.adapter(MeResponse::class.java).toJson(response)
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.PROFILE,
                    payloadJson = payloadJson,
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeProfile(payloadJson: String): StaffProfile? {
        return runCatching {
            val response = moshi.adapter(MeResponse::class.java).fromJson(payloadJson)
                ?: return null
            val staff = response.staff ?: return null
            profileMapper.toStaffProfile(staff)
        }.getOrNull()
    }
}
