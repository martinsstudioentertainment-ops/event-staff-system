package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.StaffProfile
import kotlinx.coroutines.flow.Flow

interface ProfileRepository {
    fun observeProfile(): Flow<CachedResource<StaffProfile>>

    suspend fun refreshProfile()

    suspend fun updateProfile(mobile: String, fullAddress: String, eircode: String): StaffProfile

    suspend fun sendPasswordOtp()

    suspend fun changePassword(newPassword: String, otpCode: String?, currentPassword: String?)
}
