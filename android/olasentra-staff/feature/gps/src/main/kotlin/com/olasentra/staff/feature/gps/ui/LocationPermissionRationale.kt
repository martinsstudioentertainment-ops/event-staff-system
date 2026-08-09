package com.olasentra.staff.feature.gps.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun LocationPermissionRationale(
    onRequestPermission: () -> Unit,
    onOpenSettings: () -> Unit,
    permanentlyDenied: Boolean,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                text = "Location access required",
                style = MaterialTheme.typography.titleMedium,
            )
            Text(
                text = "Olasentra needs your location to verify check-in at the event venue and send GPS attendance updates during your shift.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = "Background location may be requested in a future update for monitoring when the app is closed.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (permanentlyDenied) {
                Button(onClick = onOpenSettings, modifier = Modifier.fillMaxWidth()) {
                    Text(text = "Open Settings")
                }
            } else {
                Button(onClick = onRequestPermission, modifier = Modifier.fillMaxWidth()) {
                    Text(text = "Allow location access")
                }
            }
        }
    }
}
