package com.olasentra.staff.core.network

import com.olasentra.staff.core.network.dto.AuthGoogleRequest
import com.olasentra.staff.core.network.dto.AuthLogoutRequest
import com.olasentra.staff.core.network.dto.AuthOtpSendRequest
import com.olasentra.staff.core.network.dto.AuthOtpVerifyRequest
import com.olasentra.staff.core.network.dto.AuthRefreshRequest
import com.olasentra.staff.core.network.dto.ChangePasswordRequest
import com.olasentra.staff.core.network.dto.OtpSendResponse
import com.olasentra.staff.core.network.dto.PatchMeRequest
import com.olasentra.staff.core.network.dto.AuthSuccessResponse
import com.olasentra.staff.core.network.dto.AvailabilityResponse
import com.olasentra.staff.core.network.dto.AvailabilitySetRequest
import com.olasentra.staff.core.network.dto.AvailabilitySetResponse
import com.olasentra.staff.core.network.dto.ConfigResponse
import com.olasentra.staff.core.network.dto.DashboardResponse
import com.olasentra.staff.core.network.dto.DocumentsListResponse
import com.olasentra.staff.core.network.dto.EventsListResponse
import com.olasentra.staff.core.network.dto.EventsRegisterRequest
import com.olasentra.staff.core.network.dto.EventsRegisterResponse
import com.olasentra.staff.core.network.dto.LeaveRequest
import com.olasentra.staff.core.network.dto.LeaveResponse
import com.olasentra.staff.core.network.dto.NotificationMarkReadResponse
import com.olasentra.staff.core.network.dto.NotificationsMarkAllReadResponse
import com.olasentra.staff.core.network.dto.CheckinRequest
import com.olasentra.staff.core.network.dto.CheckinResponse
import com.olasentra.staff.core.network.dto.CheckoutRequest
import com.olasentra.staff.core.network.dto.CheckoutResponse
import com.olasentra.staff.core.network.dto.GpsPingRequest
import com.olasentra.staff.core.network.dto.GpsPingResponse
import com.olasentra.staff.core.network.dto.GpsStatusResponse
import com.olasentra.staff.core.network.dto.MeResponse
import com.olasentra.staff.core.network.dto.MessagesResponse
import com.olasentra.staff.core.network.dto.MessagesSendRequest
import com.olasentra.staff.core.network.dto.MessagesSendResponse
import com.olasentra.staff.core.network.dto.NotificationsListResponse
import com.olasentra.staff.core.network.dto.OfflineSyncRequest
import com.olasentra.staff.core.network.dto.OfflineSyncResponse
import com.olasentra.staff.core.network.dto.OkMessageResponse
import com.olasentra.staff.core.network.dto.PushRegisterRequest
import com.olasentra.staff.core.network.dto.PushRegisterResponse
import com.olasentra.staff.core.network.dto.ShiftDetailResponse
import com.olasentra.staff.core.network.dto.ShiftTodayResponse
import com.olasentra.staff.core.network.dto.ShiftsListResponse
import com.olasentra.staff.core.network.dto.TokenResponse
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming
import okhttp3.ResponseBody

interface MobileApiService {
    @GET("config")
    suspend fun getConfig(
        @Query("app_version") appVersion: String? = null,
        @Query("platform") platform: String = "android",
    ): ConfigResponse

    @POST("auth/google")
    suspend fun authGoogle(@Body body: AuthGoogleRequest): AuthSuccessResponse

    @POST("auth/otp/send")
    suspend fun authOtpSend(@Body body: AuthOtpSendRequest): OtpSendResponse

    @POST("auth/otp/verify")
    suspend fun authOtpVerify(@Body body: AuthOtpVerifyRequest): AuthSuccessResponse

    @POST("auth/refresh")
    suspend fun authRefresh(@Body body: AuthRefreshRequest): TokenResponse

    @POST("auth/logout")
    suspend fun authLogout(@Body body: AuthLogoutRequest? = null): OkMessageResponse

    @GET("me")
    suspend fun getMe(): MeResponse

    @PATCH("me")
    suspend fun patchMe(@Body body: PatchMeRequest): MeResponse

    @POST("me/password")
    suspend fun changePassword(@Body body: ChangePasswordRequest): OkMessageResponse

    @GET("dashboard")
    suspend fun getDashboard(): DashboardResponse

    @GET("events")
    suspend fun getEvents(): EventsListResponse

    @POST("events/register")
    suspend fun postEventsRegister(@Body body: EventsRegisterRequest): EventsRegisterResponse

    @GET("shifts")
    suspend fun getShifts(
        @Query("filter") filter: String? = null,
        @Query("employer") employer: String? = null,
        @Query("q") query: String? = null,
        @Query("page") page: Int? = null,
        @Query("per_page") perPage: Int? = null,
    ): ShiftsListResponse

    @GET("shifts/today")
    suspend fun getShiftsToday(): ShiftTodayResponse

    @GET("shifts/{registrationId}")
    suspend fun getShiftDetail(
        @Path("registrationId") registrationId: Long,
    ): ShiftDetailResponse

    @GET("gps/status")
    suspend fun getGpsStatus(
        @Query("registration_id") registrationId: Long? = null,
    ): GpsStatusResponse

    @POST("checkin")
    suspend fun postCheckin(@Body body: CheckinRequest): CheckinResponse

    @POST("gps/ping")
    suspend fun postGpsPing(@Body body: GpsPingRequest): GpsPingResponse

    @POST("checkout")
    suspend fun postCheckout(@Body body: CheckoutRequest): CheckoutResponse

    @GET("messages")
    suspend fun getMessages(
        @Query("limit") limit: Int? = null,
    ): MessagesResponse

    @POST("messages")
    suspend fun postMessage(@Body body: MessagesSendRequest): MessagesSendResponse

    @GET("notifications")
    suspend fun getNotifications(
        @Query("page") page: Int? = null,
        @Query("per_page") perPage: Int? = null,
        @Query("unread_only") unreadOnly: Boolean? = null,
        @Query("category") category: String? = null,
    ): NotificationsListResponse

    @POST("notifications/{notificationId}/read")
    suspend fun postNotificationMarkRead(
        @Path("notificationId") notificationId: Long,
    ): NotificationMarkReadResponse

    @POST("notifications/read-all")
    suspend fun postNotificationsMarkAllRead(): NotificationsMarkAllReadResponse

    @GET("documents")
    suspend fun getDocuments(): DocumentsListResponse

    @Streaming
    @GET("documents/{key}/file")
    suspend fun getDocumentFile(
        @Path("key") key: String,
    ): ResponseBody

    @GET("availability")
    suspend fun getAvailability(
        @Query("month") month: String,
    ): AvailabilityResponse

    @PUT("availability/{date}")
    suspend fun putAvailability(
        @Path("date") date: String,
        @Body body: AvailabilitySetRequest,
    ): AvailabilitySetResponse

    @POST("leave")
    suspend fun postLeave(@Body body: LeaveRequest): LeaveResponse

    @POST("sync/offline")
    suspend fun postOfflineSync(@Body body: OfflineSyncRequest): OfflineSyncResponse

    @POST("push/register")
    suspend fun postPushRegister(@Body body: PushRegisterRequest): PushRegisterResponse

    @DELETE("push/register")
    suspend fun deletePushRegister(
        @Query("device_id") deviceId: String,
    ): OkMessageResponse
}
