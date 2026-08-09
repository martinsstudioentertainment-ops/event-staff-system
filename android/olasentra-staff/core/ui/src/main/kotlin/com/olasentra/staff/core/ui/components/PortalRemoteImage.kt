package com.olasentra.staff.core.ui.components

import androidx.annotation.DrawableRes
import androidx.compose.foundation.Image
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import coil.compose.SubcomposeAsyncImage
import coil.compose.SubcomposeAsyncImageContent

@Composable
fun PortalRemoteImage(
    imageUrl: String?,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    @DrawableRes fallbackResId: Int? = null,
    contentScale: ContentScale = ContentScale.Fit,
) {
    val url = imageUrl?.trim()?.takeIf { it.isNotBlank() }
    if (url == null) {
        if (fallbackResId != null) {
            Image(
                painter = painterResource(fallbackResId),
                contentDescription = contentDescription,
                modifier = modifier,
                contentScale = contentScale,
            )
        }
        return
    }

    SubcomposeAsyncImage(
        model = url,
        contentDescription = contentDescription,
        modifier = modifier,
        contentScale = contentScale,
        loading = {
            if (fallbackResId != null) {
                Image(
                    painter = painterResource(fallbackResId),
                    contentDescription = contentDescription,
                    modifier = Modifier.matchParentSize(),
                    contentScale = contentScale,
                )
            } else {
                SubcomposeAsyncImageContent()
            }
        },
        error = {
            if (fallbackResId != null) {
                Image(
                    painter = painterResource(fallbackResId),
                    contentDescription = contentDescription,
                    modifier = Modifier.matchParentSize(),
                    contentScale = contentScale,
                )
            } else {
                SubcomposeAsyncImageContent()
            }
        },
        success = { SubcomposeAsyncImageContent() },
    )
}
