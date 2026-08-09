package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class NotificationMarkReadResponse(
    val ok: Boolean? = null,
    val message: String? = null,
    @Json(name = "notification_id") val notificationId: Long? = null,
    @Json(name = "is_read") val isRead: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class NotificationsMarkAllReadResponse(
    val ok: Boolean? = null,
    val message: String? = null,
    @Json(name = "marked_count") val markedCount: Int? = null,
)
