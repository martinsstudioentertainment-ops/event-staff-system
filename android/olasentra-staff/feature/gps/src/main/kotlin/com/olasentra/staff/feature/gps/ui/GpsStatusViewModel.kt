package com.olasentra.staff.feature.gps.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.GpsStatusSummary
import com.olasentra.staff.domain.repository.GpsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class GpsStatusViewModel @Inject constructor(
    private val gpsRepository: GpsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(GpsStatusUiState(isInitialLoading = true))
    val uiState: StateFlow<GpsStatusUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            gpsRepository.observeGpsStatus().collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        status = resource.data,
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
            val registrationId = _uiState.value.status?.registrationId
            gpsRepository.refreshGpsStatus(registrationId)
        }
    }
}

data class GpsStatusUiState(
    val status: GpsStatusSummary? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
