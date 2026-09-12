package org.manaclinic.app.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.PhoneInTalk
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import org.manaclinic.app.ClinicInfo
import org.manaclinic.app.dialNumber
import org.manaclinic.app.openUrl
import org.manaclinic.app.ui.RelativeMetrics
import org.manaclinic.app.ui.components.AccentChip
import org.manaclinic.app.ui.components.ActionButton
import org.manaclinic.app.ui.components.BrandHeader
import org.manaclinic.app.ui.components.TwoColumnActions
import org.manaclinic.app.ui.theme.ManaAccent

@Composable
fun HomeScreen(
    metrics: RelativeMetrics,
    onBookClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val scroll = rememberScrollState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(scroll),
    ) {
        BrandHeader(
            metrics = metrics,
            subtitle = "روان‌شناسی و مشاوره در سعادت‌آباد\nنوبت آنلاین و تماس سریع",
            modifier = Modifier.fillMaxWidth(),
        )

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(metrics.pagePadding),
            verticalArrangement = Arrangement.spacedBy(metrics.itemGap),
        ) {
            AccentChip(text = "پشتیبانی: ${ClinicInfo.SUPPORT_HOURS}", metrics = metrics)

            Text(
                text = "رزرو نوبت",
                fontSize = metrics.titleSize,
                fontWeight = FontWeight.SemiBold,
                color = MaterialTheme.colorScheme.onBackground,
            )
            Text(
                text = "انتخاب متخصص و گرفتن وقت از همان سامانهٔ سایت.",
                fontSize = metrics.bodySize,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                lineHeight = (metrics.bodySize.value * 1.5f).sp,
            )

            ActionButton(
                text = "گرفتن نوبت",
                icon = Icons.Filled.CalendarMonth,
                onClick = onBookClick,
                metrics = metrics,
            )

            Text(
                text = "تماس سریع",
                fontSize = metrics.titleSize,
                fontWeight = FontWeight.SemiBold,
            )

            TwoColumnActions(
                metrics = metrics,
                left = {
                    ActionButton(
                        text = "تماس موبایل",
                        icon = Icons.Filled.Call,
                        onClick = { context.dialNumber(ClinicInfo.MOBILE) },
                        metrics = metrics,
                    )
                },
                right = {
                    ActionButton(
                        text = "خط ثابت",
                        icon = Icons.Filled.PhoneInTalk,
                        onClick = { context.dialNumber(ClinicInfo.LANDLINE) },
                        metrics = metrics,
                        primary = false,
                    )
                },
            )

            ActionButton(
                text = "پیام در واتساپ",
                icon = Icons.Filled.Chat,
                onClick = { context.openUrl(ClinicInfo.WHATSAPP_URL) },
                metrics = metrics,
                containerColor = ManaAccent,
            )
        }
    }
}
