package org.manaclinic.app

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Message
import android.view.View
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ProgressBar
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import android.Manifest
import android.content.pm.PackageManager

/**
 * Native shell around the live site, same idea as the 7rokh app:
 * the phone app is manaclinic.org, including its own header and menu.
 */
class MainActivity : ComponentActivity() {
    private lateinit var webView: WebView
    private lateinit var progress: ProgressBar
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var pendingWebPermission: PermissionRequest? = null

    private val fileChooser = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        val callback = fileCallback
        fileCallback = null
        callback?.onReceiveValue(
            WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data),
        )
    }

    private val runtimePermissions = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { granted ->
        val request = pendingWebPermission
        pendingWebPermission = null
        if (request == null) return@registerForActivityResult
        if (granted.values.all { it }) {
            request.grant(request.resources)
        } else {
            request.deny()
        }
    }

    override fun attachBaseContext(newBase: android.content.Context) {
        val config = android.content.res.Configuration(newBase.resources.configuration)
        config.setLocale(java.util.Locale.forLanguageTag("fa"))
        super.attachBaseContext(newBase.createConfigurationContext(config))
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        webView = findViewById(R.id.web)
        progress = findViewById(R.id.progress)

        val cookies = CookieManager.getInstance()
        cookies.setAcceptCookie(true)
        cookies.setAcceptThirdPartyCookies(webView, true)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            javaScriptCanOpenWindowsAutomatically = true
            setSupportMultipleWindows(true)
            mediaPlaybackRequiresUserGesture = false
            useWideViewPort = true
            loadWithOverviewMode = true
            builtInZoomControls = false
            displayZoomControls = false
            setSupportZoom(false)
            cacheMode = WebSettings.LOAD_DEFAULT
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            allowFileAccess = false
            allowContentAccess = true
            userAgentString = userAgentString + " ManaClinicApp/" + BuildConfig.VERSION_NAME
        }

        webView.webViewClient = SiteClient()
        webView.webChromeClient = SiteChrome()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) webView.goBack() else finish()
            }
        })

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(ClinicInfo.SITE_URL)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onPause() {
        webView.onPause()
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        webView.onResume()
    }

    override fun onDestroy() {
        webView.destroy()
        super.onDestroy()
    }

    private fun openOutside(uri: Uri) {
        val intent = when (uri.scheme?.lowercase()) {
            "tel" -> Intent(Intent.ACTION_DIAL, uri)
            "mailto" -> Intent(Intent.ACTION_SENDTO, uri)
            else -> Intent(Intent.ACTION_VIEW, uri)
        }
        try {
            startActivity(intent)
        } catch (_: ActivityNotFoundException) {
            android.widget.Toast.makeText(this, "برنامه‌ای برای باز کردن این لینک پیدا نشد", android.widget.Toast.LENGTH_SHORT).show()
        }
    }

    private fun stayInApp(uri: Uri): Boolean {
        val scheme = uri.scheme?.lowercase() ?: return false
        if (scheme != "http" && scheme != "https") return false
        val host = uri.host?.lowercase() ?: return false
        return host == "manaclinic.org" ||
            host.endsWith(".manaclinic.org") ||
            host.endsWith("zarinpal.com") ||
            host.endsWith("zarinpal.ir") ||
            host.endsWith("shaparak.ir") ||
            host.endsWith("behpardakht.com") ||
            host.contains("sadad")
    }

    private inner class SiteClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            if (!request.isForMainFrame) return false
            val uri = request.url
            if (stayInApp(uri)) return false
            openOutside(uri)
            return true
        }

        override fun onPageFinished(view: WebView?, url: String?) {
            progress.visibility = View.GONE
        }

        override fun onReceivedError(
            view: WebView,
            request: WebResourceRequest,
            error: WebResourceError,
        ) {
            if (!request.isForMainFrame) return
            view.loadDataWithBaseURL(
                ClinicInfo.SITE_URL,
                OFFLINE_HTML,
                "text/html",
                "utf-8",
                request.url.toString(),
            )
        }
    }

    private inner class SiteChrome : WebChromeClient() {
        override fun onProgressChanged(view: WebView?, newProgress: Int) {
            progress.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
            progress.progress = newProgress
        }

        override fun onCreateWindow(
            view: WebView,
            isDialog: Boolean,
            isUserGesture: Boolean,
            resultMsg: Message,
        ): Boolean {
            val transport = resultMsg.obj as WebView.WebViewTransport
            val popup = WebView(view.context)
            popup.webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(popupView: WebView, request: WebResourceRequest): Boolean {
                    val uri = request.url
                    if (stayInApp(uri)) {
                        view.loadUrl(uri.toString())
                    } else {
                        openOutside(uri)
                    }
                    popupView.destroy()
                    return true
                }
            }
            transport.webView = popup
            resultMsg.sendToTarget()
            return true
        }

        override fun onShowFileChooser(
            webView: WebView?,
            filePathCallback: ValueCallback<Array<Uri>>?,
            fileChooserParams: FileChooserParams?,
        ): Boolean {
            fileCallback?.onReceiveValue(null)
            fileCallback = filePathCallback
            val intent = fileChooserParams?.createIntent() ?: return false
            return try {
                fileChooser.launch(intent)
                true
            } catch (_: ActivityNotFoundException) {
                fileCallback = null
                filePathCallback?.onReceiveValue(null)
                false
            }
        }

        override fun onPermissionRequest(request: PermissionRequest) {
            val needed = request.resources.mapNotNull { resource ->
                when (resource) {
                    PermissionRequest.RESOURCE_VIDEO_CAPTURE -> Manifest.permission.CAMERA
                    PermissionRequest.RESOURCE_AUDIO_CAPTURE -> Manifest.permission.RECORD_AUDIO
                    else -> null
                }
            }.distinct()
            val missing = needed.filter {
                ContextCompat.checkSelfPermission(this@MainActivity, it) != PackageManager.PERMISSION_GRANTED
            }
            if (missing.isEmpty()) {
                request.grant(request.resources)
            } else {
                pendingWebPermission?.deny()
                pendingWebPermission = request
                runtimePermissions.launch(missing.toTypedArray())
            }
        }
    }

    private companion object {
        const val OFFLINE_HTML = """
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>مانا کلینیک</title>
              <style>
                body { margin:0; min-height:100vh; display:grid; place-items:center; background:#f7f5f0; color:#1a2e28; font-family:Tahoma,sans-serif; }
                main { text-align:center; padding:1.5rem; }
                a { display:inline-block; margin-top:1rem; background:#1b5e4b; color:#fff; text-decoration:none; padding:.7rem 1.2rem; border-radius:.7rem; }
              </style>
            </head>
            <body>
              <main>
                <h1>اتصال برقرار نشد</h1>
                <p>اینترنت را بررسی کنید و دوباره سایت را باز کنید.</p>
                <a href="https://manaclinic.org/">تلاش دوباره</a>
              </main>
            </body>
            </html>
        """
    }
}
