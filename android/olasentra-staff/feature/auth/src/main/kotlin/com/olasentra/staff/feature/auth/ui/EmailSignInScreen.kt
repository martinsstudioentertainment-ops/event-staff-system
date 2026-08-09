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
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.AuthBodyText
import com.olasentra.staff.core.ui.components.AuthErrorText
import com.olasentra.staff.core.ui.components.AuthPrimaryButton
import com.olasentra.staff.core.ui.components.AuthScreenBackground
import com.olasentra.staff.core.ui.components.AuthSuccessText
import com.olasentra.staff.core.ui.components.AuthTextField
import com.olasentra.staff.core.ui.theme.OlasentraColors

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EmailSignInScreen(
    onBack: () -> Unit,
    onCodeSent: (String) -> Unit,
    modifier: Modifier = Modifier,
    viewModel: EmailSignInViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    LaunchedEffect(viewModel) {
        viewModel.events.collect { event ->
            when (event) {
                is EmailSignInViewModel.Event.CodeSent -> onCodeSent(event.email)
            }
        }
    }

    AuthScreenBackground(modifier = modifier) {
        Scaffold(
            modifier = Modifier.fillMaxSize(),
            containerColor = OlasentraColors.Background,
            topBar = {
                TopAppBar(
                    title = {
                        Text(
                            text = "Sign in with Email",
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
                AuthBodyText(
                    text = "Enter the email address on your approved staff profile. " +
                        "Gmail and non-Gmail addresses are supported. We will send a one-time code.",
                )
                Spacer(modifier = Modifier.height(24.dp))
                AuthTextField(
                    value = uiState.email,
                    onValueChange = viewModel::updateEmail,
                    label = "Email address",
                    enabled = !uiState.isSending,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                )
                uiState.successMessage?.let { message ->
                    Spacer(modifier = Modifier.height(12.dp))
                    AuthSuccessText(text = message)
                }
                uiState.errorMessage?.let { message ->
                    Spacer(modifier = Modifier.height(12.dp))
                    AuthErrorText(text = message)
                }
                Spacer(modifier = Modifier.height(24.dp))
                AuthPrimaryButton(
                    text = "Send verification code",
                    onClick = viewModel::sendVerificationCode,
                    enabled = !uiState.isSending,
                    loading = uiState.isSending,
                )
            }
        }
    }
}
