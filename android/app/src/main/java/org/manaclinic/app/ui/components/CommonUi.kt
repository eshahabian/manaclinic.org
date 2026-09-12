package org.manaclinic.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import org.manaclinic.app.ui.RelativeMetrics
import org.manaclinic.app.ui.theme.ManaAccent
import org.manaclinic.app.ui.theme.ManaGreen
import org.manaclinic.app.ui.theme.ManaGreenDark

@Composable
fun BrandHeader(
    metrics: RelativeMetrics,
    subtitle: String,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .background(Brush.verticalGradient(listOf(ManaGreen, ManaGreenDark)))
            .padding(horizontal = metrics.pagePadding, vertical = metrics.sectionGap),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = "مانا کلینیک",
            color = Color.White,
            fontSize = metrics.brandSize,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
        )
        Spacer(modifier = Modifier.height(metrics.itemGap / 2))
        Text(
            text = subtitle,
            color = Color.White.copy(alpha = 0.9f),
            fontSize = metrics.bodySize,
            textAlign = TextAlign.Center,
            lineHeight = (metrics.bodySize.value * 1.5f).sp,
        )
    }
}

@Composable
fun ActionButton(
    text: String,
    icon: ImageVector,
    onClick: () -> Unit,
    metrics: RelativeMetrics,
    modifier: Modifier = Modifier,
    primary: Boolean = true,
    containerColor: Color = MaterialTheme.colorScheme.primary,
) {
    val shape = RoundedCornerShape(metrics.corner)
    val iconSize = (metrics.bodySize.value * 1.15f).dp
    if (primary) {
        Button(
            onClick = onClick,
            modifier = modifier
                .fillMaxWidth()
                .height(metrics.buttonMinHeight),
            shape = shape,
            colors = ButtonDefaults.buttonColors(containerColor = containerColor),
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(iconSize))
            Spacer(Modifier.width(metrics.itemGap * 0.6f))
            Text(text = text, fontSize = metrics.bodySize, fontWeight = FontWeight.SemiBold)
        }
    } else {
        OutlinedButton(
            onClick = onClick,
            modifier = modifier
                .fillMaxWidth()
                .height(metrics.buttonMinHeight),
            shape = shape,
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(iconSize))
            Spacer(Modifier.width(metrics.itemGap * 0.6f))
            Text(text = text, fontSize = metrics.bodySize, fontWeight = FontWeight.Medium)
        }
    }
}

@Composable
fun InfoRow(
    label: String,
    value: String,
    metrics: RelativeMetrics,
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
) {
    val content: @Composable () -> Unit = {
        Column(modifier = Modifier.padding(metrics.pagePadding * 0.85f)) {
            Text(
                text = label,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                fontSize = (metrics.bodySize.value * 0.85f).sp,
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = value,
                color = if (onClick != null) ManaGreen else MaterialTheme.colorScheme.onSurface,
                fontSize = metrics.bodySize,
                fontWeight = FontWeight.SemiBold,
                lineHeight = (metrics.bodySize.value * 1.55f).sp,
            )
        }
    }

    if (onClick != null) {
        Surface(
            onClick = onClick,
            modifier = modifier.fillMaxWidth(),
            shape = RoundedCornerShape(metrics.corner * 0.75f),
            color = MaterialTheme.colorScheme.surface,
        ) { content() }
    } else {
        Surface(
            modifier = modifier.fillMaxWidth(),
            shape = RoundedCornerShape(metrics.corner * 0.75f),
            color = MaterialTheme.colorScheme.surface,
        ) { content() }
    }
}

@Composable
fun AccentChip(text: String, metrics: RelativeMetrics) {
    Surface(
        color = ManaAccent.copy(alpha = 0.15f),
        shape = RoundedCornerShape(percent = 40),
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(
                horizontal = metrics.itemGap,
                vertical = metrics.itemGap * 0.4f,
            ),
            color = ManaAccent,
            fontSize = (metrics.bodySize.value * 0.85f).sp,
            fontWeight = FontWeight.Medium,
        )
    }
}

@Composable
fun TwoColumnActions(
    metrics: RelativeMetrics,
    left: @Composable () -> Unit,
    right: @Composable () -> Unit,
) {
    if (metrics.isWide) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(metrics.itemGap),
        ) {
            Column(modifier = Modifier.weight(1f)) { left() }
            Column(modifier = Modifier.weight(1f)) { right() }
        }
    } else {
        Column(verticalArrangement = Arrangement.spacedBy(metrics.itemGap)) {
            left()
            right()
        }
    }
}
