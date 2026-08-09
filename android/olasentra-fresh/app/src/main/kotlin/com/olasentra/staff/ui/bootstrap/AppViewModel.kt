package com.olasentra.staff.ui.bootstrap

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.BuildConfig
import com.olasentra.staff.data.remote.MobileApiService
import com.olasentra.staff.data.repository.AuthRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class AppViewModel @Inject constructor(
    private val api: MobileApiService,
    private val authRepository: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(AppUiState())
    val state: StateFlow<AppUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            _state.update { it.copy(isBootstrapping = true) }

            val configResult = runCatching {
                api.getConfig(appVersion = BuildConfig.VERSION_NAME)
            }

            val session = authRepository.hydrateSessionFromStorage()

            configResult
                .onSuccess { config ->
                    _state.update {
                        it.copy(
                            isBootstrapping = false,
                            appName = config.appName?.takeIf { name -> name.isNotBlank() } ?: "Olasentra",
                            emailOtpEnabled = config.emailOtpEnabled ?: true,
                            startDestination = if (session != null) {
                                AppDestination.Dashboard
                            } else {
                                AppDestination.Login
                            },
                        )
                    }
                }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            isBootstrapping = false,
                            appName = "Olasentra",
                            emailOtpEnabled = true,
                            configError = error.message ?: "Could not reach Mobile API",
                            startDestination = if (session != null) {
                                AppDestination.Dashboard
                            } else {
                                AppDestination.Login
                            },
                        )
                    }
                }
        }
    }
}

enum class AppDestination {
    Login,
    Dashboard,
}

data class AppUiState(
    val isBootstrapping: Boolean = true,
    val appName: String = "Olasentra",
    val emailOtpEnabled: Boolean = true,
    val configError: String? = null,
    val startDestination: AppDestination? = null,
)
