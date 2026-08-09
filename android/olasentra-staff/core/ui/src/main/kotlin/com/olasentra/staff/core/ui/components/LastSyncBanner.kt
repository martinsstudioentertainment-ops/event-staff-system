package com.olasentra.staff.core.ui.components

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.olasentra.staff.core.util.SyncTimeFormatter

@Composable
fun LastSyncBanner(
    lastSyncedAtEpochMs: Long?,
    isOfflineData: Boolean,
    modifier: Modifier = Modifier,
) {
    val syncText = SyncTimeFormatter.format(lastSyncedAtEpochMs)
    val message = if (isOfflineData) {
        "$syncText · showing cached data"
    } else {
        syncText
    }

    Surface(
        modifier = modifier.fillMaxWidth(),
        color = MaterialTheme.colorScheme.surfaceVariant,
    ) {
        Text(
            text = message,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
