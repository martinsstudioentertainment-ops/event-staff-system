package com.olasentra.staff.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext

private val LightColors = lightColorScheme(
    primary = OlasentraPalette.DarkOrange,
    onPrimary = OlasentraPalette.LightOrange,
    secondary = OlasentraPalette.MediumOrange,
    onSecondary = OlasentraPalette.LightOrange,
    background = OlasentraPalette.LightOrange,
    onBackground = OlasentraPalette.DarkOrange,
    surface = OlasentraPalette.PaleOrange,
    onSurface = OlasentraPalette.DarkOrange,
    error = OlasentraPalette.Danger,
    onError = OlasentraPalette.LightOrange,
)

private val DarkColors = darkColorScheme(
    primary = OlasentraPalette.MediumOrange,
    onPrimary = OlasentraPalette.LightOrange,
    secondary = OlasentraPalette.LightOrange,
    onSecondary = OlasentraPalette.DeepOrange,
    background = OlasentraPalette.DeepOrange,
    onBackground = OlasentraPalette.LightOrange,
    surface = OlasentraPalette.DeepOrangeSurface,
    onSurface = OlasentraPalette.LightOrange,
    error = OlasentraPalette.Danger,
    onError = OlasentraPalette.LightOrange,
)

@Composable
fun OlasentraTheme(
    darkTheme: Boolean = true,
    dynamicColor: Boolean = false,
    content: @Composable () -> Unit,
) {
    val scheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S -> {
            val context = LocalContext.current
            if (darkTheme) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        }
        darkTheme -> DarkColors
        else -> LightColors
    }

    MaterialTheme(
        colorScheme = scheme,
        content = content,
    )
}
