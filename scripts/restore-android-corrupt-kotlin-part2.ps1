$ErrorActionPreference = 'Stop'
$Root = Join-Path (Split-Path -Parent $PSScriptRoot) 'android\olasentra-staff'

function Write-TextFile([string]$RelativePath, [string]$Content) {
    $path = Join-Path $Root $RelativePath
    $dir = Split-Path $path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    [IO.File]::WriteAllText($path, $Content.Replace("`n", "`r`n"), [Text.UTF8Encoding]::new($false))
    Write-Host "  restored $RelativePath"
}

Write-Host 'Restoring remaining Android Kotlin sources...' -ForegroundColor Cyan

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\model\AvailableEventModels.kt' @'
package com.olasentra.staff.domain.model

data class AvailableEvent(
    val eventId: Long,
    val label: String,
    val venueName: String,
    val eventDate: String,
    val timeLabel: String,
    val isFull: Boolean,
)

data class AvailableEventsOverview(
    val events: List<AvailableEvent>,
)
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\model\MessagesModels.kt' @'
package com.olasentra.staff.domain.model

data class StaffMessage(
    val id: Long,
    val folder: String,
    val subject: String,
    val body: String,
    val isRead: Boolean,
    val createdAt: String,
    val senderLabel: String,
)

data class MessagesOverview(
    val inbox: List<StaffMessage>,
    val sent: List<StaffMessage>,
    val unreadCount: Int,
)
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\model\NotificationsModels.kt' @'
package com.olasentra.staff.domain.model

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

data class NotificationCategory(
    val category: String,
    val label: String,
)

data class NotificationsOverview(
    val notifications: List<StaffNotification>,
    val categories: List<NotificationCategory>,
    val unreadCount: Int,
)
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\model\OfflineSyncItem.kt' @'
package com.olasentra.staff.domain.model

data class OfflineSyncItem(
    val id: Long,
    val clientId: String,
    val action: String,
    val payloadJson: String,
    val status: String,
    val createdAt: Long,
    val lastAttemptAt: Long?,
)

data class OfflineSyncBatchResult(
    val processed: Int,
    val succeeded: Int,
    val failed: Int,
)
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\ConfigRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.MobileConfig
import kotlinx.coroutines.flow.Flow

interface ConfigRepository {
    suspend fun fetchConfig(appVersion: String?): MobileConfig
    fun observeCachedConfig(): Flow<MobileConfig?>
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\DashboardRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DashboardSummary
import kotlinx.coroutines.flow.Flow

interface DashboardRepository {
    fun observeDashboard(): Flow<CachedResource<DashboardSummary>>
    suspend fun refreshDashboard()
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\ProfileRepository.kt' @'
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
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\ShiftsRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.ShiftDetail
import com.olasentra.staff.domain.model.ShiftFilter
import com.olasentra.staff.domain.model.ShiftsOverview
import kotlinx.coroutines.flow.Flow

interface ShiftsRepository {
    fun observeShifts(filter: ShiftFilter): Flow<CachedResource<ShiftsOverview>>
    suspend fun refreshShifts(filter: ShiftFilter)
    fun observeShiftDetail(registrationId: Long): Flow<CachedResource<ShiftDetail>>
    suspend fun refreshShiftDetail(registrationId: Long)
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\GpsRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.GpsStatusSummary
import kotlinx.coroutines.flow.Flow

interface GpsRepository {
    fun observeGpsStatus(): Flow<CachedResource<GpsStatusSummary>>
    suspend fun refreshGpsStatus(registrationId: Long?)
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\MessagesRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.MessagesOverview
import kotlinx.coroutines.flow.Flow

interface MessagesRepository {
    fun observeMessages(): Flow<CachedResource<MessagesOverview>>
    suspend fun refreshMessages()
    suspend fun sendMessage(body: String, subject: String): Result<Unit>
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\NotificationsRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.NotificationsOverview
import kotlinx.coroutines.flow.Flow

interface NotificationsRepository {
    fun observeNotifications(category: String?): Flow<CachedResource<NotificationsOverview>>
    suspend fun refreshNotifications(category: String?)
    suspend fun markNotificationRead(notificationId: Long): Result<Unit>
    suspend fun markAllNotificationsRead(): Result<Unit>
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\DocumentsRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DocumentFileResult
import com.olasentra.staff.domain.model.DocumentsOverview
import kotlinx.coroutines.flow.Flow

interface DocumentsRepository {
    fun observeDocuments(): Flow<CachedResource<DocumentsOverview>>
    suspend fun refreshDocuments()
    suspend fun downloadDocumentFile(key: String): DocumentFileResult
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\EventsRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.AvailableEventsOverview
import com.olasentra.staff.domain.model.CachedResource
import kotlinx.coroutines.flow.Flow

interface EventsRepository {
    fun observeAvailableEvents(): Flow<CachedResource<AvailableEventsOverview>>
    suspend fun refreshAvailableEvents()
    suspend fun registerForEvents(eventIds: List<Long>): Result<String>
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\OfflineSyncRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.OfflineSyncBatchResult
import com.olasentra.staff.domain.model.OfflineSyncItem
import kotlinx.coroutines.flow.Flow

interface OfflineSyncRepository {
    suspend fun enqueue(clientId: String, action: String, payloadJson: String): Long
    suspend fun syncPendingBatch(batchSize: Int): OfflineSyncBatchResult
    fun observePendingItems(): Flow<List<OfflineSyncItem>>
    fun observePendingCount(): Flow<Int>
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\repository\PushRepository.kt' @'
package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.PushRegistrationResult

interface PushRepository {
    suspend fun registerCurrentToken(fcmToken: String): PushRegistrationResult
    suspend fun unregisterCurrentDevice(): PushRegistrationResult
    suspend fun registerPendingTokenIfNeeded()
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\usecase\LoginWithGoogleUseCase.kt' @'
package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.repository.AuthRepository

class LoginWithGoogleUseCase(
    private val authRepository: AuthRepository,
) {
    suspend operator fun invoke(idToken: String): AuthSession {
        return authRepository.loginWithGoogle(idToken)
    }
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\usecase\LogoutUseCase.kt' @'
package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.AuthRepository

class LogoutUseCase(
    private val authRepository: AuthRepository,
) {
    suspend operator fun invoke() {
        authRepository.logout()
    }
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\usecase\ObserveAuthSessionUseCase.kt' @'
package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.repository.AuthRepository
import kotlinx.coroutines.flow.Flow

class ObserveAuthSessionUseCase(
    private val authRepository: AuthRepository,
) {
    operator fun invoke(): Flow<AuthSession?> = authRepository.observeSession()
}
'@

Write-TextFile 'domain\src\main\kotlin\com\olasentra\staff\domain\usecase\RefreshAuthSessionUseCase.kt' @'
package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.model.AuthSession
import com.olasentra.staff.domain.repository.AuthRepository

class RefreshAuthSessionUseCase(
    private val authRepository: AuthRepository,
) {
    suspend operator fun invoke(): AuthSession = authRepository.refreshSession()
}
'@

Write-TextFile 'feature\availability\src\main\kotlin\com\olasentra\staff\feature\availability\navigation\AvailabilityNavigation.kt' @'
package com.olasentra.staff.feature.availability.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.availability.ui.AvailabilityScreen

fun NavGraphBuilder.availabilityGraph() {
    composable(Route.Availability.route) {
        AvailabilityScreen()
    }
}
'@

Write-TextFile 'feature\documents\src\main\kotlin\com\olasentra\staff\feature\documents\navigation\DocumentsNavigation.kt' @'
package com.olasentra.staff.feature.documents.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.documents.ui.DocumentsScreen

fun NavGraphBuilder.documentsGraph() {
    composable(Route.Documents.route) {
        DocumentsScreen()
    }
}
'@

Write-TextFile 'feature\messages\src\main\kotlin\com\olasentra\staff\feature\messages\navigation\MessagesNavigation.kt' @'
package com.olasentra.staff.feature.messages.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.messages.ui.MessagesScreen

fun NavGraphBuilder.messagesGraph() {
    composable(Route.Messages.route) {
        MessagesScreen()
    }
}
'@

Write-TextFile 'feature\notifications\src\main\kotlin\com\olasentra\staff\feature\notifications\navigation\NotificationsNavigation.kt' @'
package com.olasentra.staff.feature.notifications.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.notifications.ui.NotificationsScreen

fun NavGraphBuilder.notificationsGraph(
    onOpenRoute: (String) -> Unit = {},
    onOpenShiftDetail: (Long) -> Unit = {},
) {
    composable(Route.Notifications.route) {
        NotificationsScreen(
            onOpenRoute = onOpenRoute,
            onOpenShiftDetail = onOpenShiftDetail,
        )
    }
}
'@

Write-TextFile 'feature\auth\src\main\kotlin\com\olasentra\staff\feature\auth\ui\LoginPlaceholderScreen.kt' @'
package com.olasentra.staff.feature.auth.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable

@Composable
fun LoginPlaceholderScreen() {
    Text("Login")
}
'@

Write-TextFile 'core\ui\src\main\kotlin\com\olasentra\staff\core\ui\components\PortalMobileContent.kt' @'
package com.olasentra.staff.core.ui.components

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier

@Composable
fun PortalMobileContent(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit,
) {
    content()
}
'@

Write-Host 'Done restoring part 2.' -ForegroundColor Green
