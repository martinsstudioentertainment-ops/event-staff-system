package com.olasentra.staff.feature.auth.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.AuthBodyText
import com.olasentra.staff.core.ui.components.AuthErrorText
import com.olasentra.staff.core.ui.components.AuthPrimaryButton
import com.olasentra.staff.core.ui.components.AuthScreenBackground
import com.olasentra.staff.core.ui.components.AuthTextField
import com.olasentra.staff.core.ui.theme.OlasentraColors
import com.olasentra.staff.domain.model.RegistrationSession

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OtpVerificationScreen(
    onBack: () -> Unit,
    onLoginSuccess: () -> Unit,
    onRegistrationVerified: (RegistrationSession) -> Unit,
    modifier: Modifier = Modifier,
    viewModel: OtpVerificationViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }

    LaunchedEffect(viewModel) {
        viewModel.events.collect { event ->
            when (event) {
                OtpVerificationViewModel.Event.LoginSucceeded -> onLoginSuccess()
                is OtpVerificationViewModel.Event.RegistrationVerified -> onRegistrationVerified(event.session)
                OtpVerificationViewModel.Event.CodeResent -> snackbarHostState.showSnackbar("New code sent to your email.")
            }
        }
    }

    AuthScreenBackground(modifier = modifier) {
        Scaffold(
            modifier = Modifier.fillMaxSize(),
            containerColor = OlasentraColors.Background,
            snackbarHost = { SnackbarHost(hostState = snackbarHostState) },
            topBar = {
                TopAppBar(
                    title = {
                        Text(
                            text = "Verify code",
                            color = OlasentraColors.DarkNavy,
                            fontWeight = FontWeight.Bold,
                        )
                    },
                    navigationIcon = {
                        IconButton(onClick = onBack) {
                            Icon(
                                imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                                contentDescription = "Back",
                                tint = OlasentraColors.DarkNavy,
                            )
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = OlasentraColors.Background,
                    ),
                )
            },
        ) { innerPadding ->
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
                    .padding(horizontal = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                AuthBodyText(text = "Enter the 6-digit code sent to")
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = uiState.email,
                    style = androidx.compose.material3.MaterialTheme.typography.titleMedium,
                    color = OlasentraColors.PrimaryOrange,
                    fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center,
                )
                Spacer(modifier = Modifier.height(24.dp))
                AuthTextField(
                    value = uiState.code,
                    onValueChange = viewModel::updateCode,
                    label = "Verification code",
                    enabled = !uiState.isVerifying,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                )
                uiState.errorMessage?.let { message ->
                    Spacer(modifier = Modifier.height(12.dp))
                    AuthErrorText(text = message)
                }
                Spacer(modifier = Modifier.height(24.dp))
                AuthPrimaryButton(
                    text = "Verify and sign in",
                    onClick = viewModel::verify,
                    enabled = !uiState.isVerifying,
                    loading = uiState.isVerifying,
                )
                Spacer(modifier = Modifier.height(12.dp))
                TextButton(
                    onClick = viewModel::resendCode,
                    enabled = !uiState.isResending && !uiState.isVerifying,
                ) {
                    Text(
                        text = if (uiState.isResending) "Sending…" else "Resend code",
                        color = OlasentraColors.PrimaryOrange,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }
    }
}
