package com.olasentra.staff.core.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.olasentra.staff.core.ui.theme.OlasentraColors

@Composable
fun PortalDashboardHeader(
    appName: String,
    logoUrl: String?,
    displayName: String,
    role: String,
    initials: String,
    unreadNotifications: Int,
    onOpenNotifications: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .background(OlasentraColors.Background)
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.weight(1f),
            ) {
                Box(
                    modifier = Modifier
                        .size(40.dp)
                        .clip(CircleShape)
                        .background(OlasentraColors.PrimaryOrange),
                    contentAlignment = Alignment.Center,
                ) {
                    if (!logoUrl.isNullOrBlank()) {
                        PortalRemoteImage(
                            imageUrl = logoUrl,
                            contentDescription = appName,
                            modifier = Modifier.size(28.dp),
                            contentScale = ContentScale.Fit,
                        )
                    } else {
                        Text(
                            text = "O",
                            color = OlasentraColors.White,
                            fontWeight = FontWeight.Bold,
                            fontSize = 18.sp,
                        )
                    }
                }
                Spacer(modifier = Modifier.width(10.dp))
                Text(
                    text = appName.ifBlank { "Olasentra" },
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    color = OlasentraColors.DarkNavy,
                )
            }

            Row(verticalAlignment = Alignment.CenterVertically) {
                Box {
                    IconButton(onClick = onOpenNotifications) {
                        Icon(
                            imageVector = Icons.Default.Notifications,
                            contentDescription = "Notifications",
                            tint = OlasentraColors.DarkNavy,
                            modifier = Modifier.size(24.dp),
                        )
                    }
                    if (unreadNotifications > 0) {
                        Box(
                            modifier = Modifier
                                .align(Alignment.TopEnd)
                                .padding(top = 6.dp, end = 6.dp)
                                .size(18.dp)
                                .clip(CircleShape)
                                .background(OlasentraColors.PrimaryOrange),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(
                                text = if (unreadNotifications > 9) "9+" else unreadNotifications.toString(),
                                color = OlasentraColors.White,
                                style = MaterialTheme.typography.labelSmall,
                                fontWeight = FontWeight.Bold,
                                fontSize = 10.sp,
                            )
                        }
                    }
                }
                Spacer(modifier = Modifier.width(4.dp))
                PortalAvatar(initials = initials, size = 44.dp)
            }
        }

        Spacer(modifier = Modifier.size(16.dp))

        Text(
            text = "Welcome back,",
            style = MaterialTheme.typography.bodyMedium,
            color = OlasentraColors.TextSecondary,
        )
        Text(
            text = displayName,
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
            color = OlasentraColors.PrimaryOrange,
        )
        if (role.isNotBlank()) {
            Text(
                text = role,
                style = MaterialTheme.typography.bodySmall,
                color = OlasentraColors.TextSecondary,
            )
        }
    }
}
