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

    'session_name' => 'mana_clinic_sess',
];
