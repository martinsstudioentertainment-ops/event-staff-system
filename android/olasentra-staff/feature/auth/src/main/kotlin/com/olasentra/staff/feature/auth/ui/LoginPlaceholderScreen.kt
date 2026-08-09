package com.olasentra.staff.feature.auth.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.olasentra.staff.core.ui.components.AuthBodyText
import com.olasentra.staff.core.ui.components.AuthPrimaryButton
import com.olasentra.staff.core.ui.components.AuthScreenBackground
import com.olasentra.staff.core.ui.components.AuthSecondaryButton
import com.olasentra.staff.core.ui.components.PortalRemoteImage
import com.olasentra.staff.core.ui.theme.OlasentraColors
import kotlinx.coroutines.launch

@Composable
fun LoginPlaceholderScreen(
    appName: String,
    loginLogoUrl: String?,
    onApplyToJoin: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val snackbarHostState = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

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
                PortalRemoteImage(
                    imageUrl = loginLogoUrl,
                    contentDescription = appName,
                    modifier = Modifier.size(96.dp),
                )

                Spacer(modifier = Modifier.height(20.dp))

                Text(
                    text = appName.ifBlank { "Olasentra" },
                    modifier = Modifier.fillMaxWidth(),
                    color = OlasentraColors.DarkNavy,
                    fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center,
                )

                Spacer(modifier = Modifier.height(12.dp))

                AuthBodyText(
                    text = "Google Sign-In is not configured for this build yet. You can still apply to join Olasentra.",
                )

                Spacer(modifier = Modifier.height(24.dp))

                AuthPrimaryButton(
                    text = "Sign in with Google",
                    onClick = {
                        scope.launch {
                            snackbarHostState.showSnackbar("Available in Phase 2B")
                        }
                    },
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(modifier = Modifier.height(12.dp))

                AuthSecondaryButton(
                    text = "Apply to Join",
                    onClick = onApplyToJoin,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}
