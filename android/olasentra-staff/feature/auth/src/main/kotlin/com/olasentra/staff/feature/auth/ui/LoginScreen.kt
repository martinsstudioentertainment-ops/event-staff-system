package com.olasentra.staff.feature.auth.ui

import android.app.Activity
import android.content.ContextWrapper
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Login
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.olasentra.staff.core.ui.components.AuthBodyText
import com.olasentra.staff.core.ui.components.AuthPrimaryButton
import com.olasentra.staff.core.ui.components.AuthScreenBackground
import com.olasentra.staff.core.ui.components.AuthSecondaryButton
import com.olasentra.staff.core.ui.components.PortalRemoteImage
import com.olasentra.staff.core.ui.theme.OlasentraColors
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle

@Composable
fun LoginScreen(
    onLoginSuccess: () -> Unit,
    onApplyToJoin: () -> Unit,
    onEmailSignIn: () -> Unit = {},
    modifier: Modifier = Modifier,
    viewModel: LoginViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val context = LocalContext.current

    LaunchedEffect(viewModel) {
        viewModel.events.collect { event ->
            when (event) {
                LoginViewModel.Event.LoginSucceeded -> onLoginSuccess()
                is LoginViewModel.Event.ShowError -> snackbarHostState.showSnackbar(event.message)
            }
        }
    }

    LaunchedEffect(uiState.postRegistrationMessage) {
        val message = uiState.postRegistrationMessage ?: return@LaunchedEffect
        snackbarHostState.showSnackbar(message)
        viewModel.clearPostRegistrationMessage()
    }

    AuthScreenBackground(modifier = modifier) {
        Scaffold(
            modifier = Modifier.fillMaxSize(),
            containerColor = OlasentraColors.Background,
            snackbarHost = { SnackbarHost(hostState = snackbarHostState) },
        ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(horizontal = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            if (uiState.showNoStaffProfile) {
                NoStaffProfilePanel(
                    appName = uiState.appName,
                    isLoading = uiState.isLoading,
                    onApplyToJoin = onApplyToJoin,
                    onTryAnotherAccount = {
                        val hostActivity = context.findHostActivity() ?: return@NoStaffProfilePanel
                        viewModel.onTryAnotherGoogleAccountClicked(hostActivity)
                    },
                )
            } else {
                LoginPanel(
                    appName = uiState.appName,
                    loginLogoUrl = uiState.loginLogoUrl,
                    maintenanceEnabled = uiState.maintenanceEnabled,
                    maintenanceMessage = uiState.maintenanceMessage,
                    forceUpdateRequired = uiState.forceUpdateRequired,
                    forceUpdateMessage = uiState.forceUpdateMessage,
                    emailOtpEnabled = uiState.emailOtpEnabled,
                    isLoading = uiState.isLoading,
                    onGoogleSignIn = {
                        val hostActivity = context.findHostActivity() ?: return@LoginPanel
                        viewModel.onGoogleSignInClicked(hostActivity)
                    },
                    onEmailSignIn = onEmailSignIn,
                    onApplyToJoin = onApplyToJoin,
                )
            }

            if (!uiState.privacyUrl.isNullOrBlank() || !uiState.termsUrl.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(24.dp))
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                ) {
                    uiState.privacyUrl?.let { url ->
                        TextButton(onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }) {
                            Text(text = "Privacy")
                        }
                    }
                    uiState.termsUrl?.let { url ->
                        TextButton(onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }) {
                            Text(text = "Terms")
                        }
                    }
                }
            }
        }
        }
    }
}

@Composable
private fun LoginPanel(
    appName: String,
    loginLogoUrl: String?,
    maintenanceEnabled: Boolean,
    maintenanceMessage: String?,
    forceUpdateRequired: Boolean,
    forceUpdateMessage: String?,
    emailOtpEnabled: Boolean,
    isLoading: Boolean,
    onGoogleSignIn: () -> Unit,
    onEmailSignIn: () -> Unit,
    onApplyToJoin: () -> Unit,
) {
    val logoUrl = loginLogoUrl?.trim()?.takeIf { it.isNotBlank() }
    if (logoUrl != null) {
        PortalRemoteImage(
            imageUrl = logoUrl,
            contentDescription = appName,
            modifier = Modifier.size(96.dp),
        )
    } else {
        Icon(
            imageVector = Icons.Default.Login,
            contentDescription = "Sign in",
            modifier = Modifier.size(72.dp),
            tint = OlasentraColors.PrimaryOrange,
        )
    }
    Spacer(modifier = Modifier.height(24.dp))
    Text(
        text = appName.ifBlank { "Olasentra" },
        style = MaterialTheme.typography.headlineMedium,
        fontWeight = FontWeight.Bold,
        color = OlasentraColors.DarkNavy,
        textAlign = TextAlign.Center,
    )
    Spacer(modifier = Modifier.height(6.dp))
    Text(
        text = "— STAFF —",
        style = MaterialTheme.typography.labelLarge.copy(letterSpacing = 2.sp),
        color = OlasentraColors.Accent,
        textAlign = TextAlign.Center,
    )
    if (forceUpdateRequired) {
        Spacer(modifier = Modifier.height(12.dp))
        Text(
            text = forceUpdateMessage?.takeIf { it.isNotBlank() }
                ?: "A newer version of the app is required. Please update before signing in.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.error,
            textAlign = TextAlign.Center,
        )
    } else if (maintenanceEnabled) {
        Spacer(modifier = Modifier.height(12.dp))
        Text(
            text = maintenanceMessage?.takeIf { it.isNotBlank() }
                ?: "The app is temporarily unavailable for maintenance.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.error,
            textAlign = TextAlign.Center,
        )
    }
    Spacer(modifier = Modifier.height(12.dp))
    AuthBodyText(
        text = "Sign in with Google or your staff email. Both use the same profile when the email matches your staff record.",
    )
    Spacer(modifier = Modifier.height(28.dp))
    AuthPrimaryButton(
        text = "Sign in with Google",
        onClick = onGoogleSignIn,
        enabled = !maintenanceEnabled && !forceUpdateRequired,
        loading = isLoading,
    )
    if (emailOtpEnabled) {
        Spacer(modifier = Modifier.height(12.dp))
        AuthSecondaryButton(
            text = "Sign in with Email",
            onClick = onEmailSignIn,
            enabled = !isLoading && !maintenanceEnabled && !forceUpdateRequired,
        )
    }
    Spacer(modifier = Modifier.height(16.dp))
    Text(
        text = "OR",
        style = MaterialTheme.typography.labelLarge,
        color = OlasentraColors.DarkNavy,
        fontWeight = FontWeight.Bold,
    )
    Spacer(modifier = Modifier.height(16.dp))
    AuthSecondaryButton(
        text = "Apply to Join ${appName.ifBlank { "Olasentra" }}",
        onClick = onApplyToJoin,
        enabled = !isLoading,
    )
}

@Composable
private fun NoStaffProfilePanel(
    appName: String,
    isLoading: Boolean,
    onApplyToJoin: () -> Unit,
    onTryAnotherAccount: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceVariant,
        ),
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = "No staff profile found for this Google account.",
                style = MaterialTheme.typography.titleMedium,
                color = OlasentraColors.DarkNavy,
                textAlign = TextAlign.Center,
            )
            Spacer(modifier = Modifier.height(12.dp))
            AuthBodyText(
                text = "If your staff email is not Gmail, go back and choose Sign in with Email. Otherwise apply on register.olasentra.com using the same email as your profile.",
            )
            Spacer(modifier = Modifier.height(24.dp))
            Button(
                modifier = Modifier.fillMaxWidth(),
                enabled = !isLoading,
                onClick = onApplyToJoin,
            ) {
                Text(text = "Apply to Join ${appName.ifBlank { "Olasentra" }}")
            }
            Spacer(modifier = Modifier.height(12.dp))
            OutlinedButton(
                modifier = Modifier.fillMaxWidth(),
                enabled = !isLoading,
                onClick = onTryAnotherAccount,
            ) {
                if (isLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text(text = "Try Another Google Account")
                }
            }
        }
    }
}

private fun android.content.Context.findHostActivity(): Activity? {
    var currentContext = this
    while (currentContext is ContextWrapper) {
        if (currentContext is Activity) {
            return currentContext
        }
        currentContext = currentContext.baseContext
    }
    return null
}
