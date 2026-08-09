package com.olasentra.staff.core.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Campaign
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.olasentra.staff.core.ui.theme.OlasentraColors

data class PortalBannerUiModel(
    val title: String?,
    val body: String?,
    val imageUrl: String?,
)

data class PortalAnnouncementUiModel(
    val title: String,
    val body: String?,
)

@Composable
fun PortalMobileBannerCard(
    banner: PortalBannerUiModel,
    modifier: Modifier = Modifier,
    onViewAll: (() -> Unit)? = null,
) {
    val title = banner.title?.trim().orEmpty().ifBlank { "Stay Updated" }
    val body = banner.body?.trim().orEmpty()
        .ifBlank { "Check your latest announcements and notifications." }
    val imageUrl = banner.imageUrl?.trim().orEmpty()

    Card(
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 8.dp),
        shape = RoundedCornerShape(20.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 4.dp),
        colors = CardDefaults.cardColors(containerColor = Color.Transparent),
    ) {
        Column(
            modifier = Modifier
                .background(
                    Brush.horizontalGradient(
                        colors = listOf(
                            OlasentraColors.PrimaryOrange,
                            OlasentraColors.SecondaryOrange,
                        ),
                    ),
                )
                .padding(16.dp),
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    modifier = Modifier
                        .size(44.dp)
                        .clip(CircleShape)
                        .background(OlasentraColors.White.copy(alpha = 0.18f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        imageVector = Icons.Default.Campaign,
                        contentDescription = null,
                        tint = OlasentraColors.White,
                        modifier = Modifier.size(24.dp),
                    )
                }
                Spacer(modifier = Modifier.width(12.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = title,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = OlasentraColors.White,
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    Text(
                        text = body,
                        style = MaterialTheme.typography.bodyMedium,
                        color = OlasentraColors.White.copy(alpha = 0.92f),
                    )
                }
                if (onViewAll != null) {
                    OutlinedButton(
                        onClick = onViewAll,
                        shape = RoundedCornerShape(999.dp),
                        border = androidx.compose.foundation.BorderStroke(
                            1.dp,
                            OlasentraColors.White.copy(alpha = 0.85f),
                        ),
                    ) {
                        Text(
                            text = "View All",
                            color = OlasentraColors.White,
                        )
                    }
                }
            }

            if (imageUrl.isNotEmpty()) {
                Spacer(modifier = Modifier.height(12.dp))
                PortalRemoteImage(
                    imageUrl = imageUrl,
                    contentDescription = title,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(120.dp),
                )
            }

            Spacer(modifier = Modifier.height(12.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                repeat(3) { index ->
                    Box(
                        modifier = Modifier
                            .padding(horizontal = 3.dp)
                            .size(if (index == 0) 8.dp else 6.dp)
                            .clip(CircleShape)
                            .background(
                                if (index == 0) {
                                    OlasentraColors.White
                                } else {
                                    OlasentraColors.White.copy(alpha = 0.45f)
                                },
                            ),
                    )
                }
            }
        }
    }
}

@Composable
fun PortalMobileAnnouncementsSection(
    announcements: List<PortalAnnouncementUiModel>,
    modifier: Modifier = Modifier,
) {
    if (announcements.isEmpty()) {
        return
    }

    Column(
        modifier = modifier.fillMaxWidth(),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        PortalSectionTitle(title = "Announcements")
        announcements.forEach { announcement ->
            Card(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp),
                shape = RoundedCornerShape(16.dp),
                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                colors = CardDefaults.cardColors(containerColor = OlasentraColors.CardBackground),
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(
                        text = announcement.title,
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = OlasentraColors.TextPrimary,
                    )
                    val body = announcement.body?.trim().orEmpty()
                    if (body.isNotEmpty()) {
                        Spacer(modifier = Modifier.height(4.dp))
                        Text(
                            text = body,
                            style = MaterialTheme.typography.bodyMedium,
                            color = OlasentraColors.TextSecondary,
                        )
                    }
                }
            }
        }
    }
}
