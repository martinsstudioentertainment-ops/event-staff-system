package com.olasentra.staff.feature.messages.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.MessagesOverview
import com.olasentra.staff.domain.repository.MessagesRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class MessagesTab {
    INBOX,
    SENT,
}

@HiltViewModel
class MessagesViewModel @Inject constructor(
    private val messagesRepository: MessagesRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(MessagesUiState(isInitialLoading = true))
    val uiState: StateFlow<MessagesUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            messagesRepository.observeMessages().collect { resource ->
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

    fun onTabSelected(tab: MessagesTab) {
        _uiState.update { it.copy(activeTab = tab) }
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            messagesRepository.refreshMessages()
        }
    }
}

data class MessagesUiState(
    val overview: MessagesOverview? = null,
    val activeTab: MessagesTab = MessagesTab.INBOX,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
