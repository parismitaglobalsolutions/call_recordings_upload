package com.maloogroup.callrecordings.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

private val colorScheme = lightColorScheme(
    primary = AppColors.Primary,
    onPrimary = AppColors.OnPrimary,
    background = AppColors.ScaffoldBg,
    onBackground = AppColors.TextPrimary,
    surface = AppColors.CardBg,
    onSurface = AppColors.TextPrimary,
    outline = AppColors.Border,
)

@Composable
fun MalooTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = colorScheme,
        typography = Typography(),
        content = content
    )
}
