package com.olasentra.staff.feature.shifts.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.AvailableEvent
import com.olasentra.staff.domain.repository.EventsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class AvailableEventsUiState(
    val events: List<AvailableEvent> = emptyList(),
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = true,
    val applyingEventId: Long? = null,
    val actionMessage: String? = null,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)

@HiltViewModel
class AvailableEventsViewModel @Inject constructor(
    private val eventsRepository: EventsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(AvailableEventsUiState())
    val uiState: StateFlow<AvailableEventsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            eventsRepository.observeAvailableEvents().collect { cached ->
                _uiState.update { current ->
                    current.copy(
                        events = cached.data?.events ?: emptyList(),
                        lastSyncedAtEpochMs = cached.lastSyncedAtEpochMs,
                        isRefreshing = cached.isRefreshing,
                        isInitialLoading = cached.data == null && cached.isRefreshing,
                        errorMessage = if (cached.data == null) cached.errorMessage else null,
                        showOfflineBanner = cached.isFromCache && cached.errorMessage != null,
                    )
                }
            }
        }
        refresh()
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            eventsRepository.refreshAvailableEvents()
        }
    }

    fun applyForEvent(eventId: Long) {
        viewModelScope.launch(dispatchers.io) {
            _uiState.update {
                it.copy(
                    applyingEventId = eventId,
                    actionMessage = null,
                    errorMessage = null,
                )
            }

            eventsRepository.registerForEvents(listOf(eventId))
                .onSuccess { message ->
                    _uiState.update {
                        it.copy(
                            applyingEventId = null,
                            actionMessage = message,
                            errorMessage = null,
                        )
                    }
                }
                .onFailure { error ->
                    _uiState.update {
                        it.copy(
                            applyingEventId = null,
                            actionMessage = null,
                            errorMessage = error.message ?: "Registration failed.",
                        )
                    }
                }
        }
    }
}
