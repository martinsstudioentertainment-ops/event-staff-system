package com.olasentra.staff.navigation

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.repository.DashboardRepository
import com.olasentra.staff.domain.usecase.ObserveDashboardUseCase
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

@HiltViewModel
class AppShellViewModel @Inject constructor(
    dashboardRepository: DashboardRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val observeDashboard = ObserveDashboardUseCase(dashboardRepository)

    private val _unreadMessages = MutableStateFlow(0)
    val unreadMessages: StateFlow<Int> = _unreadMessages.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            observeDashboard().collect { resource ->
                _unreadMessages.value = resource.data?.unreadMessages ?: 0
            }
        }
    }
}
