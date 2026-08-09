package com.olasentra.staff.ui.dashboard

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.data.model.AuthSession
import com.olasentra.staff.data.repository.AuthException
import com.olasentra.staff.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class DashboardViewModel @Inject constructor(
    private val authRepository: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(DashboardUiState())
    val state: StateFlow<DashboardUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            authRepository.observeSession().collect { session ->
                _state.update { it.copy(session = session) }
            }
        }
        refresh()
    }

    fun refresh() {
        viewModelScope.launch {
            _state.update { it.copy(isLoading = true, errorMessage = null) }
            runCatching { authRepository.fetchDashboard() }
                .onSuccess { response ->
                    _state.update {
                        it.copy(
                            isLoading = false,
                            displayName = extractDisplayName(response.profile, it.session),
                            email = extractString(response.profile, "email") ?: it.session?.staff?.email,
                            upcomingShiftsCount = response.upcomingShifts?.size ?: 0,
                            unreadNotifications = extractInt(response.unread, "notifications"),
                            unreadMessages = extractInt(response.unread, "messages"),
                            todayShiftLabel = extractTodayShiftLabel(response.todayShift),
                            availableEventsCount = response.availableEventsCount ?: 0,
                            approvalStatus = extractString(response.approvalStatus, "label")
                                ?: extractString(response.approvalStatus, "status"),
                        )
                    }
                }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            isLoading = false,
                            errorMessage = when (error) {
                                is AuthException -> error.message ?: "Could not load dashboard."
                                else -> error.message ?: "Could not load dashboard."
                            },
                        )
                    }
                }
        }
    }

    fun signOut(onSignedOut: () -> Unit) {
        viewModelScope.launch {
            authRepository.logout()
            onSignedOut()
        }
    }

    private fun extractDisplayName(
        profile: Map<String, Any?>?,
        session: AuthSession?,
    ): String {
        val firstName = extractString(profile, "first_name")
        val surname = extractString(profile, "surname")
        val parts = listOfNotNull(firstName, surname).filter { it.isNotBlank() }
        if (parts.isNotEmpty()) {
            return parts.joinToString(" ")
        }
        return session?.staff?.displayName ?: "Staff"
    }

    private fun extractTodayShiftLabel(todayShift: Map<String, Any?>?): String? {
        if (todayShift.isNullOrEmpty()) {
            return null
        }
        val eventName = extractString(todayShift, "event_name")
            ?: extractString(todayShift, "title")
        val status = extractString(todayShift, "status")
        return when {
            eventName != null && status != null -> "$eventName · $status"
            eventName != null -> eventName
            status != null -> status
            else -> "Shift scheduled today"
        }
    }

    private fun extractString(map: Map<String, Any?>?, key: String): String? {
        val value = map?.get(key) ?: return null
        return value.toString().takeIf { it.isNotBlank() }
    }

    private fun extractInt(map: Map<String, Any?>?, key: String): Int {
        val value = map?.get(key) ?: return 0
        return when (value) {
            is Number -> value.toInt()
            is String -> value.toIntOrNull() ?: 0
            else -> 0
        }
    }
}

data class DashboardUiState(
    val session: AuthSession? = null,
    val isLoading: Boolean = true,
    val displayName: String = "Staff",
    val email: String? = null,
    val upcomingShiftsCount: Int = 0,
    val unreadNotifications: Int = 0,
    val unreadMessages: Int = 0,
    val todayShiftLabel: String? = null,
    val availableEventsCount: Int = 0,
    val approvalStatus: String? = null,
    val errorMessage: String? = null,
)
