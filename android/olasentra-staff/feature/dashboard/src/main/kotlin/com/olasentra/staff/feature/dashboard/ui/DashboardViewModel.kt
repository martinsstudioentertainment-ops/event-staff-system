package com.olasentra.staff.feature.dashboard.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.DashboardSummary
import com.olasentra.staff.domain.repository.DashboardRepository
import com.olasentra.staff.domain.usecase.ObserveDashboardUseCase
import com.olasentra.staff.domain.usecase.RefreshDashboardUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class DashboardViewModel @Inject constructor(
    dashboardRepository: DashboardRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val observeDashboard = ObserveDashboardUseCase(dashboardRepository)
    private val refreshDashboard = RefreshDashboardUseCase(dashboardRepository)

    private val _uiState = MutableStateFlow(DashboardUiState(isInitialLoading = true))
    val uiState: StateFlow<DashboardUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            observeDashboard().collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        dashboard = resource.data,
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
            refreshDashboard()
        }
    }
}

data class DashboardUiState(
    val dashboard: DashboardSummary? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
