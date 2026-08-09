package com.olasentra.staff.feature.auth.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.AuthBodyText
import com.olasentra.staff.core.ui.components.AuthPrimaryButton
import com.olasentra.staff.core.ui.components.AuthSecondaryButton
import com.olasentra.staff.core.ui.components.PortalMobileBannerCard
import com.olasentra.staff.core.ui.components.PortalBannerUiModel
import com.olasentra.staff.core.ui.components.PortalRemoteImage
import com.olasentra.staff.core.ui.theme.OlasentraColors

@Composable
fun WelcomeScreen(
    onSignIn: () -> Unit,
    onRegister: () -> Unit,
    modifier: Modifier = Modifier,
    viewModel: WelcomeViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    Scaffold(
        modifier = modifier,
        containerColor = OlasentraColors.Background,
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(horizontal = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Box(
                modifier = Modifier
                    .size(96.dp)
                    .clip(CircleShape)
                    .background(OlasentraColors.PrimaryOrange),
                contentAlignment = Alignment.Center,
            ) {
                PortalRemoteImage(
                    imageUrl = uiState.loginLogoUrl,
                    contentDescription = uiState.appName,
                    modifier = Modifier.size(72.dp),
                )
            }

            Spacer(modifier = Modifier.height(20.dp))

            Text(
                text = uiState.appName.ifBlank { "Olasentra" },
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                color = OlasentraColors.DarkNavy,
            )

            Spacer(modifier = Modifier.height(8.dp))

            Text(
                text = "Events. People. Connected.",
                style = MaterialTheme.typography.titleMedium,
                color = OlasentraColors.DarkNavy.copy(alpha = 0.72f),
                textAlign = TextAlign.Center,
            )

            Spacer(modifier = Modifier.height(12.dp))

            AuthBodyText(
                text = "Sign in with Google or your staff email — both use the same profile.",
            )

            Spacer(modifier = Modifier.height(24.dp))

            PortalMobileBannerCard(
                banner = PortalBannerUiModel(
                    title = uiState.bannerTitle,
                    body = uiState.bannerBody,
                    imageUrl = uiState.bannerImageUrl,
                ),
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(modifier = Modifier.height(28.dp))

            AuthPrimaryButton(
                text = "Sign in",
                onClick = onSignIn,
            )

            Spacer(modifier = Modifier.height(12.dp))

            AuthSecondaryButton(
                text = "Register",
                onClick = onRegister,
            )

            Spacer(modifier = Modifier.height(24.dp))

            RowLinks(
                privacyUrl = uiState.privacyUrl,
                termsUrl = uiState.termsUrl,
                supportEmail = uiState.contactEmail,
            )
        }
    }
}

@Composable
private fun RowLinks(
    privacyUrl: String?,
    termsUrl: String?,
    supportEmail: String?,
) {
    val context = LocalContext.current
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        privacyUrl?.let { url ->
            TextButton(onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }) {
                Text(text = "Privacy Policy", color = OlasentraColors.DarkNavy.copy(alpha = 0.72f))
            }
        }
        termsUrl?.let { url ->
            TextButton(onClick = { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }) {
                Text(text = "Terms & Conditions", color = OlasentraColors.DarkNavy.copy(alpha = 0.72f))
            }
        }
        if (!supportEmail.isNullOrBlank()) {
            TextButton(onClick = {
                context.startActivity(
                    Intent(Intent.ACTION_SENDTO).apply {
                        data = Uri.parse("mailto:$supportEmail")
                    },
                )
            }) {
                Text(text = "Contact Support", color = OlasentraColors.DarkNavy.copy(alpha = 0.72f))
            }
        }
    }
}
