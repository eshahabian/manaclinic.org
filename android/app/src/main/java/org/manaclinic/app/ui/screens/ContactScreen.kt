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
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Email
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.PhoneInTalk
import androidx.compose.material.icons.filled.Public
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import org.manaclinic.app.ClinicInfo
import org.manaclinic.app.dialNumber
import org.manaclinic.app.openUrl
import org.manaclinic.app.sendEmail
import org.manaclinic.app.ui.RelativeMetrics
import org.manaclinic.app.ui.components.ActionButton
import org.manaclinic.app.ui.components.BrandHeader
import org.manaclinic.app.ui.components.InfoRow
import org.manaclinic.app.ui.components.TwoColumnActions
import org.manaclinic.app.ui.theme.ManaAccent

@Composable
fun ContactScreen(
    metrics: RelativeMetrics,
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
            subtitle = "تماس، آدرس و شبکه‌های اجتماعی",
        )

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(metrics.pagePadding),
            verticalArrangement = Arrangement.spacedBy(metrics.itemGap),
        ) {
            Text(
                text = "راه‌های تماس",
                fontSize = metrics.titleSize,
                fontWeight = FontWeight.SemiBold,
            )

            TwoColumnActions(
                metrics = metrics,
                left = {
                    ActionButton(
                        text = "موبایل",
                        icon = Icons.Filled.Call,
                        onClick = { context.dialNumber(ClinicInfo.MOBILE) },
                        metrics = metrics,
                    )
                },
                right = {
                    ActionButton(
                        text = "ثابت",
                        icon = Icons.Filled.PhoneInTalk,
                        onClick = { context.dialNumber(ClinicInfo.LANDLINE) },
                        metrics = metrics,
                        primary = false,
                    )
                },
            )

            ActionButton(
                text = "واتساپ",
                icon = Icons.Filled.Chat,
                onClick = { context.openUrl(ClinicInfo.WHATSAPP_URL) },
                metrics = metrics,
                containerColor = ManaAccent,
            )

            InfoRow(
                label = "موبایل",
                value = ClinicInfo.DISPLAY_MOBILE,
                metrics = metrics,
                onClick = { context.dialNumber(ClinicInfo.MOBILE) },
            )
            InfoRow(
                label = "خط ثابت",
                value = ClinicInfo.DISPLAY_LANDLINE,
                metrics = metrics,
                onClick = { context.dialNumber(ClinicInfo.LANDLINE) },
            )
            InfoRow(
                label = "ایمیل",
                value = ClinicInfo.EMAIL,
                metrics = metrics,
                onClick = { context.sendEmail(ClinicInfo.EMAIL) },
            )
            InfoRow(
                label = "آدرس مطب",
                value = ClinicInfo.ADDRESS,
                metrics = metrics,
            )
            InfoRow(
                label = "ساعات پشتیبانی",
                value = ClinicInfo.SUPPORT_HOURS,
                metrics = metrics,
            )

            ActionButton(
                text = "مسیریابی در نقشه",
                icon = Icons.Filled.Map,
                onClick = { context.openUrl(ClinicInfo.MAP_URL) },
                metrics = metrics,
                primary = false,
            )
            ActionButton(
                text = "اینستاگرام mana_clinic",
                icon = Icons.Filled.Public,
                onClick = { context.openUrl(ClinicInfo.INSTAGRAM_URL) },
                metrics = metrics,
                primary = false,
            )
            ActionButton(
                text = "ایمیل به کلینیک",
                icon = Icons.Filled.Email,
                onClick = { context.sendEmail(ClinicInfo.EMAIL) },
                metrics = metrics,
                primary = false,
            )
        }
    }
}
