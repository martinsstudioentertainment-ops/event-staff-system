package com.olasentra.staff.feature.availability.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.AvailabilityDay
import com.olasentra.staff.domain.model.AvailabilityOverview
import com.olasentra.staff.domain.repository.AvailabilityRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class AvailabilityViewModel @Inject constructor(
    private val availabilityRepository: AvailabilityRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val monthFormatter = DateTimeFormatter.ofPattern("yyyy-MM")

    private val _uiState = MutableStateFlow(
        AvailabilityUiState(
            currentMonth = YearMonth.now(),
            isInitialLoading = true,
        ),
    )
    val uiState: StateFlow<AvailabilityUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            _uiState
                .map { it.currentMonth.format(monthFormatter) }
                .distinctUntilChanged()
                .flatMapLatest { monthKey ->
                    availabilityRepository.observeAvailability(monthKey)
                }
                .collect { resource ->
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
        refreshCurrentMonth()
    }

    fun previousMonth() {
        _uiState.update { it.copy(currentMonth = it.currentMonth.minusMonths(1), selectedDate = null) }
        refreshCurrentMonth()
    }

    fun nextMonth() {
        _uiState.update { it.copy(currentMonth = it.currentMonth.plusMonths(1), selectedDate = null) }
        refreshCurrentMonth()
    }

    fun selectDate(date: String) {
        _uiState.update { it.copy(selectedDate = date, actionMessage = null, actionError = null) }
    }

    fun dismissEditor() {
        _uiState.update { it.copy(selectedDate = null) }
    }

    fun refresh() {
        refreshCurrentMonth()
    }

    fun setStatus(status: String) {
        val date = _uiState.value.selectedDate ?: return
        val month = _uiState.value.currentMonth.format(monthFormatter)
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isSaving = true) }
            val result = availabilityRepository.setDayStatus(
                date = date,
                status = status,
                notes = null,
                month = month,
            )
            _uiState.update {
                it.copy(
                    isSaving = false,
                    actionMessage = result.message,
                    actionError = if (result.success) null else result.message,
                    selectedDate = if (result.success) null else it.selectedDate,
                )
            }
        }
    }

    fun submitLeave(type: String) {
        val date = _uiState.value.selectedDate ?: return
        val month = _uiState.value.currentMonth.format(monthFormatter)
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isSaving = true) }
            val result = availabilityRepository.submitLeave(
                date = date,
                type = type,
                notes = null,
                month = month,
            )
            _uiState.update {
                it.copy(
                    isSaving = false,
                    actionMessage = result.message,
                    actionError = if (result.success) null else result.message,
                    selectedDate = if (result.success) null else it.selectedDate,
                )
            }
        }
    }

    fun dayForDate(date: String): AvailabilityDay? {
        return _uiState.value.overview?.days?.firstOrNull { it.date == date }
    }

    fun isPastDate(date: String): Boolean {
        return runCatching { LocalDate.parse(date).isBefore(LocalDate.now()) }.getOrDefault(true)
    }

    private fun refreshCurrentMonth() {
        val month = _uiState.value.currentMonth.format(monthFormatter)
        viewModelScope.launch(dispatchers.io) {
            availabilityRepository.refreshAvailability(month)
        }
    }
}

data class AvailabilityUiState(
    val currentMonth: YearMonth,
    val overview: AvailabilityOverview? = null,
    val selectedDate: String? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val isSaving: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
    val actionMessage: String? = null,
    val actionError: String? = null,
)
