package com.olasentra.staff.core.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.olasentra.staff.core.ui.theme.OlasentraColors

@Composable
fun PortalNotificationsHeader(
    displayName: String,
    role: String,
    initials: String,
    unreadCount: Int,
    onMarkAllRead: () -> Unit,
    markAllEnabled: Boolean,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .background(OlasentraColors.DarkNavy)
            .padding(horizontal = 16.dp, vertical = 16.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PortalAvatar(initials = initials, size = 44.dp)
            Spacer(modifier = Modifier.size(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = displayName,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = OlasentraColors.White,
                )
                Text(
                    text = role.ifBlank { "Olasentra" },
                    style = MaterialTheme.typography.bodySmall,
                    color = OlasentraColors.TextSecondary,
                )
            }
        }

        Spacer(modifier = Modifier.height(16.dp))

        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = "Notifications",
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                color = OlasentraColors.White,
            )
            if (unreadCount > 0) {
                Spacer(modifier = Modifier.size(8.dp))
                Box(
                    modifier = Modifier
                        .size(22.dp)
                        .clip(CircleShape)
                        .background(OlasentraColors.Danger),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = if (unreadCount > 9) "9+" else unreadCount.toString(),
                        color = OlasentraColors.White,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
        }

        Spacer(modifier = Modifier.height(4.dp))
        Text(
            text = "Updates about your registrations and shifts",
            style = MaterialTheme.typography.bodyMedium,
            color = OlasentraColors.TextSecondary,
        )

        if (unreadCount > 0) {
            Spacer(modifier = Modifier.height(12.dp))
            OutlinedButton(
                onClick = onMarkAllRead,
                enabled = markAllEnabled,
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(12.dp),
                border = androidx.compose.foundation.BorderStroke(1.dp, OlasentraColors.Border),
            ) {
                Text(text = "Mark all as read", color = OlasentraColors.White)
            }
        }
    }
}

@Composable
fun PortalNotificationCard(
    title: String,
    body: String,
    timestamp: String,
    actionLabel: String?,
    isUnread: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val shape = RoundedCornerShape(16.dp)
    val borderColor = if (isUnread) {
        OlasentraColors.PrimaryOrange.copy(alpha = 0.55f)
    } else {
        OlasentraColors.NavySecondary
    }

    Box(
        modifier = modifier
            .fillMaxWidth()
            .clip(shape)
            .background(OlasentraColors.NavySecondary)
            .border(1.dp, borderColor, shape)
            .clickable(onClick = onClick),
    ) {
        if (isUnread) {
            Box(
                modifier = Modifier
                    .align(Alignment.CenterStart)
                    .size(width = 4.dp, height = 80.dp)
                    .background(OlasentraColors.PrimaryOrange),
            )
        }

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.Top,
            ) {
                Text(
                    text = title,
                    modifier = Modifier.weight(1f).padding(end = 8.dp),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = OlasentraColors.White,
                )
                Text(
                    text = timestamp,
                    style = MaterialTheme.typography.labelSmall,
                    color = OlasentraColors.TextSecondary,
                )
            }

            if (body.isNotBlank()) {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = body,
                    style = MaterialTheme.typography.bodyMedium,
                    color = OlasentraColors.White.copy(alpha = 0.82f),
                    maxLines = 4,
                    overflow = TextOverflow.Ellipsis,
                )
            }

            if (!actionLabel.isNullOrBlank()) {
                Spacer(modifier = Modifier.height(12.dp))
                TextButton(onClick = onClick) {
                    Text(
                        text = actionLabel,
                        color = OlasentraColors.PrimaryOrange,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }

        if (isUnread) {
            Box(
                modifier = Modifier
                    .align(Alignment.BottomStart)
                    .fillMaxWidth()
                    .height(2.dp)
                    .background(OlasentraColors.PrimaryOrange.copy(alpha = 0.65f)),
            )
        }
    }
}

@Composable
fun PortalNotificationsSectionTitle(
    title: String,
    modifier: Modifier = Modifier,
) {
    Text(
        text = title.uppercase(),
        modifier = modifier.padding(horizontal = 16.dp, vertical = 8.dp),
        style = MaterialTheme.typography.labelLarge,
        color = OlasentraColors.TextSecondary,
        fontWeight = FontWeight.Bold,
        letterSpacing = 1.sp,
    )
}
