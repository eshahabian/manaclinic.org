<?php
/**
 * تنظیمات مانا کلینیک — این مقادیر را از cPanel → MySQL Databases پر کنید
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DB_NAME',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASSWORD',
    'db_charset' => 'utf8mb4',

    'app_name' => 'مانا کلینیک',
    'app_url' => 'https://manaclinic.org', // بدون اسلش آخر
    'timezone' => 'Asia/Tehran',

    'zarinpal_merchant_id' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
    'zarinpal_sandbox' => true, // بعد از گرفتن مرچنت واقعی false کنید
    'online_payment_enabled' => false, // پرداخت آنلاین زرین‌پال
    'workshop_media_max_mb' => 300, // حداکثر حجم هر فایل ویدیو/صوت (مگابایت)
    // 'media_stream_secret' => 'یک رشته تصادفی طولانی', // اختیاری — برای لینک‌های موقت پخش

    'session_name' => 'mana_clinic_sess',
    'allow_install' => false, // فقط هنگام نصب اولیه true کنید؛ بعد حتماً false بماند

    // ایمیل / SMTP — رمز را ترجیحاً از پنل ادمین → ایمیل و SMTP ذخیره کنید
    'mail_from' => 'noreply@manaclinic.org',
    'mail_from_name' => 'مانا کلینیک',
    'smtp_host' => 'localhost', // روی سی‌پنل معمولاً localhost
    'smtp_port' => 25,
    'smtp_user' => 'noreply@manaclinic.org',
    'smtp_pass' => '', // یا در پنل ادمین ذخیره شود
    'smtp_encryption' => 'none', // none | tls | ssl

    // موتور جدید تماس مانا (LiveKit Cloud) — مقادیر واقعی فقط در config.php خصوصی سرور
    'livekit_url' => '', // نمونه: wss://your-project.livekit.cloud
    'livekit_api_key' => '',
    'livekit_api_secret' => '',

    // دستیار گفت‌وگوی «با من حرف بزن»
    'assistant_enabled' => true,
    // Metis AI / درگاه OpenAI-compatible — کلید را فقط در config.php بگذارید (نه در git)
    'openai_api_key' => '', // روی سرور واقعی حتماً کلید Metis را بگذارید؛ بدون آن سوال‌ها ثابت می‌مانند
    'openai_base_url' => 'https://api.metisai.ir/openai/v1',
    'openai_model' => 'gpt-4o-mini',
];
