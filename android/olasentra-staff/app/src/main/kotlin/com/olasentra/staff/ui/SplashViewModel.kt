package com.olasentra.staff.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.security.TokenStorage
import com.olasentra.staff.core.util.DispatcherProvider
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.launch

@HiltViewModel
class SplashViewModel @Inject constructor(
    private val tokenStorage: TokenStorage,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _navigationEffect = MutableSharedFlow<NavigationEffect>(replay = 1)

    val navigationEffect: SharedFlow<NavigationEffect> = _navigationEffect.asSharedFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            val destination = try {
                if (tokenStorage.hasValidSession()) {
                    NavigationEffect.NavigateToMain
                } else {
                    NavigationEffect.NavigateToLogin
                }
            } catch (_: Throwable) {
                NavigationEffect.NavigateToLogin
            }
            _navigationEffect.emit(destination)
        }
    }

    enum class NavigationEffect {
        NavigateToLogin,
        NavigateToMain,
    }
}
