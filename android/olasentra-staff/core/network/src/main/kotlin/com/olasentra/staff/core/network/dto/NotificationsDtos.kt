package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class NotificationItemDto(
    val id: Long? = null,
    val type: String? = null,
    val category: String? = null,
    @Json(name = "category_label") val categoryLabel: String? = null,
    val title: String? = null,
    val body: String? = null,
    @Json(name = "action_url") val actionUrl: String? = null,
    @Json(name = "action_label") val actionLabel: String? = null,
    @Json(name = "related_id") val relatedId: Long? = null,
    @Json(name = "is_read") val isRead: Boolean? = null,
    @Json(name = "created_at") val createdAt: String? = null,
)

@JsonClass(generateAdapter = true)
data class NotificationCategoryDto(
    val category: String? = null,
    val label: String? = null,
)

@JsonClass(generateAdapter = true)
data class NotificationsPaginationDto(
    val page: Int? = null,
    @Json(name = "per_page") val perPage: Int? = null,
    val total: Int? = null,
    @Json(name = "total_pages") val totalPages: Int? = null,
)

@JsonClass(generateAdapter = true)
data class NotificationsListResponse(
    val ok: Boolean? = null,
    val notifications: List<NotificationItemDto>? = null,
    @Json(name = "unread_count") val unreadCount: Int? = null,
    val pagination: NotificationsPaginationDto? = null,
    val categories: List<NotificationCategoryDto>? = null,
)
