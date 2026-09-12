package org.manaclinic.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

val ManaGreen = Color(0xFF1B5E4B)
val ManaGreenDark = Color(0xFF134437)
val ManaAccent = Color(0xFFC4783A)
val ManaBg = Color(0xFFF7F5F0)
val ManaBgSoft = Color(0xFFE8EFE9)
val ManaFg = Color(0xFF1A2E28)
val ManaMuted = Color(0xFF5A6F66)
val ManaLine = Color(0xFFD5E0DA)

private val LightColors = lightColorScheme(
    primary = ManaGreen,
    onPrimary = Color.White,
    primaryContainer = ManaBgSoft,
    onPrimaryContainer = ManaGreenDark,
    secondary = ManaAccent,
    onSecondary = Color.White,
    background = ManaBg,
    onBackground = ManaFg,
    surface = Color.White,
    onSurface = ManaFg,
    surfaceVariant = ManaBgSoft,
    onSurfaceVariant = ManaMuted,
    outline = ManaLine,
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF6EC4A8),
    onPrimary = Color(0xFF0A1F18),
    primaryContainer = Color(0xFF1C2B26),
    onPrimaryContainer = Color(0xFFE7F0EC),
    secondary = Color(0xFFE0A05C),
    onSecondary = Color(0xFF1A1208),
    background = Color(0xFF101816),
    onBackground = Color(0xFFE7F0EC),
    surface = Color(0xFF17211E),
    onSurface = Color(0xFFE7F0EC),
    surfaceVariant = Color(0xFF1C2824),
    onSurfaceVariant = Color(0xFF9AAFA6),
    outline = Color(0xFF2C4039),
)

@Composable
fun ManaClinicTheme(content: @Composable () -> Unit) {
    val dark = isSystemInDarkTheme()
    MaterialTheme(
        colorScheme = if (dark) DarkColors else LightColors,
        typography = MaterialTheme.typography.copy(
            displayLarge = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.Bold,
                fontSize = 32.sp,
                lineHeight = 40.sp,
            ),
            headlineMedium = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.SemiBold,
                fontSize = 22.sp,
                lineHeight = 30.sp,
            ),
            titleLarge = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.SemiBold,
                fontSize = 18.sp,
                lineHeight = 26.sp,
            ),
            bodyLarge = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.Normal,
                fontSize = 16.sp,
                lineHeight = 26.sp,
            ),
            bodyMedium = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.Normal,
                fontSize = 14.sp,
                lineHeight = 22.sp,
            ),
            labelLarge = TextStyle(
                fontFamily = FontFamily.SansSerif,
                fontWeight = FontWeight.Medium,
                fontSize = 14.sp,
                lineHeight = 20.sp,
            ),
        ),
        content = content,
    )
}
