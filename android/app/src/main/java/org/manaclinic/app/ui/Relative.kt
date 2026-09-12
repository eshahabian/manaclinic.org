package org.manaclinic.app.ui

import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlin.math.min

/**
 * Relative metrics derived from the current window size so layouts
 * stay proportional across phones and tablets.
 */
data class RelativeMetrics(
    val width: Dp,
    val height: Dp,
    val isCompact: Boolean,
    val isWide: Boolean,
    val pagePadding: Dp,
    val sectionGap: Dp,
    val itemGap: Dp,
    val corner: Dp,
    val brandSize: TextUnit,
    val titleSize: TextUnit,
    val bodySize: TextUnit,
    val buttonMinHeight: Dp,
    val heroFraction: Float,
)

@Composable
fun rememberRelativeMetrics(
    maxWidth: Dp,
    maxHeight: Dp,
): RelativeMetrics {
    val shortest = min(maxWidth.value, maxHeight.value)
    val scale = (shortest / 360f).coerceIn(0.85f, 1.35f)
    return remember(maxWidth, maxHeight, scale) {
        RelativeMetrics(
            width = maxWidth,
            height = maxHeight,
            isCompact = maxWidth < 400.dp,
            isWide = maxWidth >= 600.dp,
            pagePadding = (16f * scale).dp,
            sectionGap = (20f * scale).dp,
            itemGap = (12f * scale).dp,
            corner = (16f * scale).dp,
            brandSize = (28f * scale).sp,
            titleSize = (20f * scale).sp,
            bodySize = (15f * scale).sp,
            buttonMinHeight = (52f * scale).dp,
            heroFraction = if (maxHeight < 640.dp) 0.22f else 0.28f,
        )
    }
}

@Composable
fun RelativeScaffold(
    modifier: Modifier = Modifier,
    content: @Composable (RelativeMetrics) -> Unit,
) {
    BoxWithConstraints(modifier = modifier) {
        val metrics = rememberRelativeMetrics(maxWidth, maxHeight)
        content(metrics)
    }
}
