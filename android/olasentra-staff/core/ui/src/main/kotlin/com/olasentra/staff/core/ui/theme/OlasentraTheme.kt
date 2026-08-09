package com.olasentra.staff.core.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext

private val LightColorScheme = lightColorScheme(
    primary = OlasentraColors.DarkOrange,
    onPrimary = OlasentraColors.LightOrange,
    primaryContainer = OlasentraColors.PaleOrange,
    onPrimaryContainer = OlasentraColors.DarkOrange,
    secondary = OlasentraColors.MediumOrange,
    onSecondary = OlasentraColors.LightOrange,
    secondaryContainer = OlasentraColors.LightOrange,
    onSecondaryContainer = OlasentraColors.DarkOrange,
    tertiary = OlasentraColors.Success,
    onTertiary = OlasentraColors.LightOrange,
    background = OlasentraColors.Background,
    onBackground = OlasentraColors.OnBackground,
    surface = OlasentraColors.CardBackground,
    onSurface = OlasentraColors.OnBackground,
    surfaceVariant = OlasentraColors.LightOrange,
    onSurfaceVariant = OlasentraColors.TextSecondary,
    error = OlasentraColors.Danger,
    onError = OlasentraColors.LightOrange,
    outline = OlasentraColors.Outline,
)

private val DarkColorScheme = darkColorScheme(
    primary = OlasentraColors.MediumOrange,
    onPrimary = OlasentraColors.LightOrange,
    primaryContainer = OlasentraColors.DeepOrangeSurface,
    onPrimaryContainer = OlasentraColors.LightOrange,
    secondary = OlasentraColors.LightOrange,
    onSecondary = OlasentraColors.DeepOrange,
    secondaryContainer = OlasentraColors.DeepOrangeSurface,
    onSecondaryContainer = OlasentraColors.LightOrange,
    tertiary = OlasentraColors.Success,
    onTertiary = OlasentraColors.DeepOrange,
    background = OlasentraColors.PrimaryDark,
    onBackground = OlasentraColors.OnBackgroundDark,
    surface = OlasentraColors.DeepOrangeSurface,
    onSurface = OlasentraColors.OnBackgroundDark,
    surfaceVariant = OlasentraColors.DeepOrange,
    onSurfaceVariant = OlasentraColors.OutlineDark,
    error = OlasentraColors.Danger,
    onError = OlasentraColors.LightOrange,
    outline = OlasentraColors.OutlineDark,
)

@Composable
fun OlasentraTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false,
    content: @Composable () -> Unit,
) {
    val colorScheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S -> {
            val context = LocalContext.current
            if (darkTheme) {
                dynamicDarkColorScheme(context)
            } else {
                dynamicLightColorScheme(context)
            }
        }

        darkTheme -> DarkColorScheme
        else -> LightColorScheme
    }

    MaterialTheme(
        colorScheme = colorScheme,
        content = content,
    )
}
