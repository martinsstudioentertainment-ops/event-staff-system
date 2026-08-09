package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class MessageItemDto(
    val id: Long? = null,
    val direction: String? = null,
    val folder: String? = null,
    val subject: String? = null,
    val body: String? = null,
    @Json(name = "is_read") val isRead: Boolean? = null,
    @Json(name = "delivery_status") val deliveryStatus: String? = null,
    @Json(name = "created_at") val createdAt: String? = null,
    @Json(name = "sender_label") val senderLabel: String? = null,
)

@JsonClass(generateAdapter = true)
data class MessagesSendRequest(
    val body: String,
    val subject: String = "",
)

@JsonClass(generateAdapter = true)
data class MessagesSendResponse(
    val ok: Boolean? = null,
    val id: Long? = null,
    val sent: MessageItemDto? = null,
    val message: String? = null,
    val code: String? = null,
)

@JsonClass(generateAdapter = true)
data class MessagesResponse(
    val ok: Boolean? = null,
    val thread: List<MessageItemDto>? = null,
    val inbox: List<MessageItemDto>? = null,
    val sent: List<MessageItemDto>? = null,
    @Json(name = "unread_count") val unreadCount: Int? = null,
)
