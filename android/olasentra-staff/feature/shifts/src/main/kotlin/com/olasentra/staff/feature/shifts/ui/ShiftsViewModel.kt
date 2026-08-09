package com.olasentra.staff.feature.shifts.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.ShiftDetail
import com.olasentra.staff.domain.model.ShiftFilter
import com.olasentra.staff.domain.model.ShiftsOverview
import com.olasentra.staff.domain.repository.ShiftsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@OptIn(ExperimentalCoroutinesApi::class)
@HiltViewModel
class ShiftsViewModel @Inject constructor(
    private val shiftsRepository: ShiftsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val activeFilter = MutableStateFlow(ShiftFilter.ALL)

    private val _uiState = MutableStateFlow(ShiftsUiState(isInitialLoading = true))
    val uiState: StateFlow<ShiftsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            activeFilter.flatMapLatest { filter ->
                shiftsRepository.observeShifts(filter)
            }.collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        overview = resource.data,
                        activeFilter = activeFilter.value,
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

    fun onFilterSelected(filter: ShiftFilter) {
        if (activeFilter.value == filter) return
        _uiState.update { it.copy(activeFilter = filter, isInitialLoading = true) }
        activeFilter.value = filter
        refresh()
    }

    fun refresh() {
        val filter = activeFilter.value
        viewModelScope.launch(dispatchers.io) {
            shiftsRepository.refreshShifts(filter)
        }
    }
}

data class ShiftsUiState(
    val overview: ShiftsOverview? = null,
    val activeFilter: ShiftFilter = ShiftFilter.ALL,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)

@HiltViewModel
class ShiftDetailViewModel @Inject constructor(
    private val shiftsRepository: ShiftsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(ShiftDetailUiState(isInitialLoading = true))
    val uiState: StateFlow<ShiftDetailUiState> = _uiState.asStateFlow()

    private var registrationId: Long? = null

    fun load(registrationId: Long) {
        if (this.registrationId == registrationId) return
        this.registrationId = registrationId

        viewModelScope.launch(dispatchers.io) {
            shiftsRepository.observeShiftDetail(registrationId).collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        detail = resource.data,
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
        val id = registrationId ?: return
        viewModelScope.launch(dispatchers.io) {
            shiftsRepository.refreshShiftDetail(id)
        }
    }
}

data class ShiftDetailUiState(
    val detail: ShiftDetail? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
)
