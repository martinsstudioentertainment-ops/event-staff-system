package com.olasentra.staff.data.remote

import com.olasentra.staff.data.remote.dto.AuthOtpSendRequest
import com.olasentra.staff.data.remote.dto.AuthOtpVerifyRequest
import com.olasentra.staff.data.remote.dto.AuthSuccessResponse
import com.olasentra.staff.data.remote.dto.ConfigResponse
import com.olasentra.staff.data.remote.dto.DashboardResponse
import com.olasentra.staff.data.remote.dto.OtpSendResponse
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

interface MobileApiService {
    @GET("config")
    suspend fun getConfig(
        @Query("app_version") appVersion: String? = null,
        @Query("platform") platform: String = "android",
    ): ConfigResponse

    @POST("auth/otp/send")
    suspend fun authOtpSend(@Body body: AuthOtpSendRequest): OtpSendResponse

    @POST("auth/otp/verify")
    suspend fun authOtpVerify(@Body body: AuthOtpVerifyRequest): AuthSuccessResponse

    @GET("dashboard")
    suspend fun getDashboard(): DashboardResponse
}
