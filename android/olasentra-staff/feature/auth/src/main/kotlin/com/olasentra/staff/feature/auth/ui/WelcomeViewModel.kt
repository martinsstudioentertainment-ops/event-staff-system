package com.olasentra.staff.feature.auth.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.domain.repository.ConfigRepository
import com.olasentra.staff.core.util.DispatcherProvider
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class WelcomeViewModel @Inject constructor(
    private val configRepository: ConfigRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(WelcomeUiState())
    val uiState: StateFlow<WelcomeUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            runCatching { configRepository.fetchConfig(appVersion = null) }
                .onSuccess { config ->
                    _uiState.update {
                        WelcomeUiState(
                            appName = config.portal.appName,
                            loginLogoUrl = config.portal.branding.loginLogoUrl,
                            bannerTitle = config.portal.banner.title,
                            bannerBody = config.portal.banner.body,
                            bannerImageUrl = config.portal.banner.imageUrl,
                            privacyUrl = config.privacyUrl,
                            termsUrl = config.termsUrl,
                            contactEmail = config.portal.contact.email,
                        )
                    }
                }
        }
    }
}

data class WelcomeUiState(
    val appName: String = "Olasentra",
    val loginLogoUrl: String? = null,
    val bannerTitle: String? = null,
    val bannerBody: String? = null,
    val bannerImageUrl: String? = null,
    val privacyUrl: String? = null,
    val termsUrl: String? = null,
    val contactEmail: String? = null,
)
