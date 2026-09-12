package org.manaclinic.app.ui.screens

import android.annotation.SuppressLint
import android.graphics.Bitmap
import android.view.ViewGroup
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import org.manaclinic.app.ClinicInfo
import org.manaclinic.app.ui.RelativeMetrics

@OptIn(ExperimentalMaterial3Api::class)
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun BookScreen(
    metrics: RelativeMetrics,
    modifier: Modifier = Modifier,
) {
    var progress by remember { mutableFloatStateOf(0f) }
    var canGoBack by remember { mutableStateOf(false) }
    var webViewRef by remember { mutableStateOf<WebView?>(null) }

    BackHandler(enabled = canGoBack) {
        webViewRef?.goBack()
    }

    Column(modifier = modifier.fillMaxSize()) {
        TopAppBar(
            title = {
                Text(
                    text = "رزرو نوبت",
                    fontSize = metrics.titleSize,
                    fontWeight = FontWeight.SemiBold,
                )
            },
            navigationIcon = {
                IconButton(
                    onClick = { webViewRef?.goBack() },
                    enabled = canGoBack,
                ) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "بازگشت")
                }
            },
            actions = {
                IconButton(onClick = { webViewRef?.reload() }) {
                    Icon(Icons.Filled.Refresh, contentDescription = "بارگذاری مجدد")
                }
            },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.surface,
            ),
        )

        if (progress in 0f..<1f) {
            LinearProgressIndicator(
                progress = { progress },
                modifier = Modifier.fillMaxWidth(),
            )
        }

        Text(
            text = "نوبت از طریق سایت مانا کلینیک گرفته می‌شود.",
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = metrics.pagePadding, vertical = metrics.itemGap / 2),
            fontSize = metrics.bodySize * 0.9f,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        Box(modifier = Modifier.fillMaxSize()) {
            AndroidView(
                factory = { context ->
                    WebView(context).apply {
                        layoutParams = ViewGroup.LayoutParams(
                            ViewGroup.LayoutParams.MATCH_PARENT,
                            ViewGroup.LayoutParams.MATCH_PARENT,
                        )
                        settings.javaScriptEnabled = true
                        settings.domStorageEnabled = true
                        settings.cacheMode = WebSettings.LOAD_DEFAULT
                        settings.builtInZoomControls = false
                        settings.displayZoomControls = false
                        settings.useWideViewPort = true
                        settings.loadWithOverviewMode = true
                        settings.setSupportZoom(true)
                        // Let the site's own responsive CSS adapt to phone width
                        settings.textZoom = 100

                        webChromeClient = object : WebChromeClient() {
                            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                                progress = newProgress / 100f
                            }
                        }
                        webViewClient = object : WebViewClient() {
                            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                                canGoBack = view?.canGoBack() == true
                            }

                            override fun onPageFinished(view: WebView?, url: String?) {
                                canGoBack = view?.canGoBack() == true
                                progress = 1f
                            }

                            override fun shouldOverrideUrlLoading(
                                view: WebView?,
                                request: WebResourceRequest?,
                            ): Boolean {
                                val host = request?.url?.host ?: return false
                                // Keep booking flow inside the clinic domain
                                return if (host.contains("manaclinic.org") ||
                                    host.contains("zarinpal.com") ||
                                    host.contains("gateway")
                                ) {
                                    false
                                } else {
                                    // External links open outside if needed — stay in WebView for payment gateways
                                    false
                                }
                            }
                        }
                        webViewRef = this
                        loadUrl(ClinicInfo.BOOK_URL)
                    }
                },
                update = { webViewRef = it },
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

private operator fun androidx.compose.ui.unit.TextUnit.times(factor: Float) =
    androidx.compose.ui.unit.TextUnit(value * factor, type)
