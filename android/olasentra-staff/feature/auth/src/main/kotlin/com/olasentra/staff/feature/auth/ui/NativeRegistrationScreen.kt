package com.olasentra.staff.feature.auth.ui

import android.app.Activity
import android.content.ContextWrapper
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.util.formatStaffRoleLabel
import com.olasentra.staff.domain.model.RegistrationEventOption

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NativeRegistrationScreen(
    registrationSiteUrl: String,
    googleIdToken: String?,
    onBack: () -> Unit,
    onSubmitted: () -> Unit,
    modifier: Modifier = Modifier,
    viewModel: NativeRegistrationViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val activity = context.findHostActivity()

    LaunchedEffect(registrationSiteUrl, googleIdToken) {
        viewModel.initialize(registrationSiteUrl, googleIdToken)
    }

    LaunchedEffect(uiState.submitted) {
        if (uiState.submitted) {
            onSubmitted()
        }
    }

    val frontPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        uri?.let { loadUpload(context, it) { name, mime, bytes ->
            viewModel.setUpload(RegistrationUploadSlot.Front, name, mime, bytes)
        } }
    }
    val backPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        uri?.let { loadUpload(context, it) { name, mime, bytes ->
            viewModel.setUpload(RegistrationUploadSlot.Back, name, mime, bytes)
        } }
    }

    Scaffold(
        modifier = modifier,
        topBar = {
            TopAppBar(
                title = { Text(text = formatStaffRoleLabel(uiState.formSlug)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { innerPadding ->
        when {
            uiState.isLoading -> {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(innerPadding),
                    verticalArrangement = Arrangement.Center,
                ) {
                    CircularProgressIndicator(modifier = Modifier.padding(24.dp))
                }
            }

            uiState.verifiedEmail.isBlank() -> {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(innerPadding)
                        .padding(24.dp),
                    verticalArrangement = Arrangement.spacedBy(16.dp),
                ) {
                    Text(
                        text = "Verify your Gmail with Google before completing registration.",
                        style = MaterialTheme.typography.bodyLarge,
                    )
                    Button(
                        onClick = {
                            activity?.let(viewModel::verifyGoogleWithPicker)
                        },
                        enabled = activity != null && !uiState.isLoading,
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Text(text = "Continue with Google")
                    }
                    uiState.errorMessage?.let { message ->
                        Text(text = message, color = MaterialTheme.colorScheme.error)
                    }
                }
            }

            else -> {
                LazyColumn(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(innerPadding),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    item {
                        Text(
                            text = "Registering as ${uiState.verifiedEmail}",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }

                    item { SectionTitle("Personal details") }
                    item {
                        RegistrationField(
                            label = "Surname",
                            value = uiState.surname,
                            onValueChange = { viewModel.updateField(RegistrationField.Surname, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "First name",
                            value = uiState.firstName,
                            onValueChange = { viewModel.updateField(RegistrationField.FirstName, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "Full address",
                            value = uiState.fullAddress,
                            onValueChange = { viewModel.updateField(RegistrationField.FullAddress, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "Eircode",
                            value = uiState.eircode,
                            onValueChange = { viewModel.updateField(RegistrationField.Eircode, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "Date of birth (YYYY-MM-DD)",
                            value = uiState.dateOfBirth,
                            onValueChange = { viewModel.updateField(RegistrationField.DateOfBirth, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "Gender (male/female/other/prefer_not_to_say)",
                            value = uiState.gender,
                            onValueChange = { viewModel.updateField(RegistrationField.Gender, it) },
                        )
                    }

                    item { SectionTitle("Contact & financial") }
                    item {
                        RegistrationField(
                            label = "Mobile",
                            value = uiState.mobile,
                            onValueChange = { viewModel.updateField(RegistrationField.Mobile, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "NI / PPS number",
                            value = uiState.ppsNumber,
                            onValueChange = { viewModel.updateField(RegistrationField.PpsNumber, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "Bank IBAN",
                            value = uiState.bankIban,
                            onValueChange = { viewModel.updateField(RegistrationField.BankIban, it) },
                        )
                    }

                    item { SectionTitle("PSA licence") }
                    item {
                        RegistrationField(
                            label = "PSA licence number",
                            value = uiState.psaLicence,
                            onValueChange = { viewModel.updateField(RegistrationField.PsaLicence, it) },
                        )
                    }
                    item {
                        RegistrationField(
                            label = "PSA expiry date (YYYY-MM-DD)",
                            value = uiState.psaExpiryDate,
                            onValueChange = { viewModel.updateField(RegistrationField.PsaExpiryDate, it) },
                        )
                    }
                    item {
                        OutlinedButton(onClick = { frontPicker.launch("image/*") }, modifier = Modifier.fillMaxWidth()) {
                            Text(text = uiState.psaFrontImage?.fileName ?: "Upload PSA card front")
                        }
                    }
                    item {
                        OutlinedButton(onClick = { backPicker.launch("image/*") }, modifier = Modifier.fillMaxWidth()) {
                            Text(text = uiState.psaBackImage?.fileName ?: "Upload PSA card back")
                        }
                    }

                    item { SectionTitle("Select shifts") }
                    items(uiState.events, key = { it.eventId }) { event ->
                        RegistrationEventRow(
                            event = event,
                            selected = event.eventId in uiState.selectedEventIds,
                            onToggle = { viewModel.toggleEvent(event.eventId) },
                        )
                    }

                    item {
                        Button(
                            onClick = viewModel::submit,
                            enabled = !uiState.isSubmitting,
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Text(text = if (uiState.isSubmitting) "Submitting…" else "Submit registration")
                        }
                    }

                    uiState.errorMessage?.let { message ->
                        item {
                            Text(text = message, color = MaterialTheme.colorScheme.error)
                        }
                    }
                    uiState.successMessage?.let { message ->
                        item {
                            Text(text = message, color = MaterialTheme.colorScheme.primary)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SectionTitle(title: String) {
    Text(
        text = title,
        style = MaterialTheme.typography.titleMedium,
        modifier = Modifier.padding(top = 8.dp),
    )
}

@Composable
private fun RegistrationField(
    label: String,
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(text = label) },
        modifier = modifier.fillMaxWidth(),
        singleLine = label != "Full address",
    )
}

@Composable
private fun RegistrationEventRow(
    event: RegistrationEventOption,
    selected: Boolean,
    onToggle: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
    ) {
        RowWithCheckbox(
            checked = selected,
            enabled = !event.isFull,
            onToggle = onToggle,
            label = "${event.label} · ${event.eventDate} · ${event.timeLabel}",
        )
        Text(
            text = event.venueName,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(start = 48.dp),
        )
    }
}

@Composable
private fun RowWithCheckbox(
    checked: Boolean,
    enabled: Boolean,
    onToggle: () -> Unit,
    label: String,
) {
    androidx.compose.foundation.layout.Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = androidx.compose.ui.Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = { if (enabled) onToggle() }, enabled = enabled)
        Text(text = label, modifier = Modifier.padding(start = 8.dp))
    }
}

private fun loadUpload(
    context: android.content.Context,
    uri: Uri,
    onLoaded: (String, String, ByteArray) -> Unit,
) {
    val resolver = context.contentResolver
    val mime = resolver.getType(uri) ?: "image/jpeg"
    val name = uri.lastPathSegment?.substringAfterLast('/') ?: "upload.jpg"
    val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: return
    onLoaded(name, mime, bytes)
}

private fun android.content.Context.findHostActivity(): Activity? {
    var currentContext = this
    while (currentContext is ContextWrapper) {
        if (currentContext is Activity) return currentContext
        currentContext = currentContext.baseContext
    }
    return null
}
