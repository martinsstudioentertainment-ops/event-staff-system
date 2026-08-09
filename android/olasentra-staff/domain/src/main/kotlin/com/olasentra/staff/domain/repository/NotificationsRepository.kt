package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.NotificationsOverview
import kotlinx.coroutines.flow.Flow

interface NotificationsRepository {
    fun observeNotifications(category: String? = null): Flow<CachedResource<NotificationsOverview>>

    suspend fun refreshNotifications(category: String? = null)

    suspend fun markNotificationRead(notificationId: Long): Result<Unit>

    suspend fun markAllNotificationsRead(): Result<Unit>
}
