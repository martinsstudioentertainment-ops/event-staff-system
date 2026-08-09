package com.olasentra.staff.feature.notifications.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.NotificationsOverview
import com.olasentra.staff.domain.model.StaffNotification
import com.olasentra.staff.domain.repository.NotificationsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class NotificationsViewModel @Inject constructor(
    private val notificationsRepository: NotificationsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(NotificationsUiState(isInitialLoading = true))
    val uiState: StateFlow<NotificationsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            notificationsRepository.observeNotifications().collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        overview = resource.data,
                        lastSyncedAtEpochMs = resource.lastSyncedAtEpochMs,
                        isRefreshing = resource.isRefreshing,
                        errorMessage = if (resource.data == null) resource.errorMessage else null,
                        isInitialLoading = resource.data == null && resource.isRefreshing,
                        showOfflineBanner = resource.isFromCache && resource.errorMessage != null,
                    )
                }
            }
        }
        refresh()
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            notificationsRepository.refreshNotifications(_uiState.value.selectedCategory)
        }
    }

    fun selectCategory(category: String?) {
        _uiState.update { it.copy(selectedCategory = category) }
        refresh()
    }

    fun markRead(notification: StaffNotification) {
        if (notification.isRead) return
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isUpdating = true) }
            notificationsRepository.markNotificationRead(notification.id)
            _uiState.update { it.copy(isUpdating = false) }
        }
    }

    fun markAllRead() {
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isUpdating = true) }
            notificationsRepository.markAllNotificationsRead()
            _uiState.update { it.copy(isUpdating = false) }
        }
    }

    fun filteredNotifications(): List<StaffNotification> {
        val overview = _uiState.value.overview ?: return emptyList()
        val category = _uiState.value.selectedCategory
        if (category.isNullOrBlank()) return overview.notifications
        return overview.notifications.filter { it.category == category }
    }
}

data class NotificationsUiState(
    val overview: NotificationsOverview? = null,
    val selectedCategory: String? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val isUpdating: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
