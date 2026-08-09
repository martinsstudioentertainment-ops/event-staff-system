package com.olasentra.staff.core.network.dto

import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class MeResponse(
    val ok: Boolean? = null,
    val staff: StaffProfileDto? = null,
)

@JsonClass(generateAdapter = true)
data class DashboardResponse(
    val ok: Boolean? = null,
    val profile: StaffProfileDto? = null,
    @com.squareup.moshi.Json(name = "approval_status") val approvalStatus: DashboardApprovalStatusDto? = null,
    @com.squareup.moshi.Json(name = "upcoming_shifts") val upcomingShifts: List<ShiftObjectDto>? = null,
    val unread: DashboardUnreadDto? = null,
    @com.squareup.moshi.Json(name = "check_in_status") val checkInStatus: Map<String, Any?>? = null,
    @com.squareup.moshi.Json(name = "available_events_count") val availableEventsCount: Int? = null,
    val monthly: Map<String, Any?>? = null,
    @com.squareup.moshi.Json(name = "today_shift") val todayShift: Map<String, Any?>? = null,
    @com.squareup.moshi.Json(name = "profile_gate") val profileGate: Map<String, Any?>? = null,
)

@JsonClass(generateAdapter = true)
data class OfflineSyncRequestItem(
    @com.squareup.moshi.Json(name = "client_id") val clientId: String,
    val action: String,
    val payload: Map<String, Any?>,
)

@JsonClass(generateAdapter = true)
data class OfflineSyncRequest(
    val items: List<OfflineSyncRequestItem>,
)

@JsonClass(generateAdapter = true)
data class OfflineSyncResultItemDto(
    val index: Int? = null,
    @com.squareup.moshi.Json(name = "client_id") val clientId: String? = null,
    val action: String? = null,
    val status: String? = null,
    val result: Map<String, Any?>? = null,
    @com.squareup.moshi.Json(name = "conflict_reason") val conflictReason: String? = null,
)

@JsonClass(generateAdapter = true)
data class OfflineSyncResponse(
    val ok: Boolean? = null,
    val synced: Int? = null,
    val failed: Int? = null,
    val conflicts: Int? = null,
    val duplicates: Int? = null,
    val results: List<OfflineSyncResultItemDto>? = null,
)

@JsonClass(generateAdapter = true)
data class PushRegisterRequest(
    @com.squareup.moshi.Json(name = "fcm_token") val fcmToken: String,
    @com.squareup.moshi.Json(name = "device_id") val deviceId: String,
    val platform: String = "android",
)

@JsonClass(generateAdapter = true)
data class PushRegisterResponse(
    val ok: Boolean? = null,
    @com.squareup.moshi.Json(name = "device_id") val deviceId: String? = null,
    val platform: String? = null,
    val registered: Boolean? = null,
)
