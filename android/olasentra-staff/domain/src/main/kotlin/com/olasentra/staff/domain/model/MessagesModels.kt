package com.olasentra.staff.domain.model

data class StaffMessage(
    val id: Long,
    val folder: String,
    val subject: String,
    val body: String,
    val isRead: Boolean,
    val createdAt: String,
    val senderLabel: String,
    val isFromStaff: Boolean,
)

data class MessagesOverview(
    val inbox: List<StaffMessage>,
    val sent: List<StaffMessage>,
    val unreadCount: Int,
) {
    val thread: List<StaffMessage>
        get() = (inbox + sent).sortedByDescending { it.id }
}