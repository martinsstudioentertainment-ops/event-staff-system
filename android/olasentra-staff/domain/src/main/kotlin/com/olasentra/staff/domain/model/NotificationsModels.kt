package com.olasentra.staff.domain.model

data class NotificationCategory(
    val category: String,
    val label: String,
)

data class StaffNotification(
    val id: Long,
    val type: String,
    val category: String,
    val categoryLabel: String,
    val title: String,
    val body: String,
    val actionUrl: String?,
    val actionLabel: String?,
    val relatedId: Long?,
    val isRead: Boolean,
    val createdAt: String,
)

data class NotificationsOverview(
    val notifications: List<StaffNotification>,
    val categories: List<NotificationCategory>,
    val unreadCount: Int,
)
