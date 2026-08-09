package com.olasentra.staff.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.hilt.navigation.compose.hiltViewModel
import com.olasentra.staff.core.ui.theme.OlasentraColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@Composable
fun SplashScreen(
    onNavigateToLogin: () -> Unit,
    onNavigateToMain: () -> Unit,
    modifier: Modifier = Modifier,
    viewModel: SplashViewModel = hiltViewModel(),
) {
    LaunchedEffect(viewModel) {
        val timeoutJob = launch {
            delay(8000)
            onNavigateToLogin()
        }
        viewModel.navigationEffect.collect { effect ->
            timeoutJob.cancel()
            when (effect) {
                SplashViewModel.NavigationEffect.NavigateToLogin -> onNavigateToLogin()
                SplashViewModel.NavigationEffect.NavigateToMain -> onNavigateToMain()
            }
        }
    }

    Box(
        modifier = modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text = "Olasentra Staff",
            style = MaterialTheme.typography.headlineMedium,
            color = OlasentraColors.Accent,
        )
    }
}
