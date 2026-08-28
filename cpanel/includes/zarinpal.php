<?php
declare(strict_types=1);

function zarinpal_request(array $config, int $amountToman, string $description, string $callbackUrl, ?string $email = null): array
{
    $merchant = $config['zarinpal_merchant_id'];
    $sandbox = !empty($config['zarinpal_sandbox']);
    $base = $sandbox
        ? 'https://sandbox.zarinpal.com/pg/v4/payment'
        : 'https://payment.zarinpal.com/pg/v4/payment';
    $start = $sandbox
        ? 'https://sandbox.zarinpal.com/pg/StartPay/'
        : 'https://www.zarinpal.com/pg/StartPay/';

    $payload = [
        'merchant_id' => $merchant,
        'amount' => $amountToman * 10,
        'callback_url' => $callbackUrl,
        'description' => $description,
        'metadata' => array_filter(['email' => $email]),
    ];

    $response = zarinpal_http_post($base . '/request.json', $payload);
    if (!empty($response['data']['authority'])) {
        $authority = $response['data']['authority'];
        return [
            'authority' => $authority,
            'paymentUrl' => $start . $authority,
        ];
    }

    // حالت توسعه / sandbox بدون درگاه واقعی
    if ($sandbox) {
        $authority = 'DEV' . time() . random_int(100, 999);
        $appUrl = rtrim($config['app_url'], '/');
        return [
            'authority' => $authority,
            'paymentUrl' => $appUrl . '/payments/verify?Authority=' . urlencode($authority) . '&Status=OK&dev=1',
        ];
    }

    throw new RuntimeException($response['errors']['message'] ?? 'خطا در اتصال به زرین‌پال');
}

function zarinpal_verify(array $config, string $authority, int $amountToman): array
{
    if (str_starts_with($authority, 'DEV')) {
        return ['ok' => true, 'refId' => (string) time()];
    }

    $sandbox = !empty($config['zarinpal_sandbox']);
    $base = $sandbox
        ? 'https://sandbox.zarinpal.com/pg/v4/payment'
        : 'https://payment.zarinpal.com/pg/v4/payment';

    $response = zarinpal_http_post($base . '/verify.json', [
        'merchant_id' => $config['zarinpal_merchant_id'],
        'amount' => $amountToman * 10,
        'authority' => $authority,
    ]);

    $code = $response['data']['code'] ?? null;
    if ($code === 100 || $code === 101) {
        return ['ok' => true, 'refId' => (string) ($response['data']['ref_id'] ?? '')];
    }

    return ['ok' => false, 'message' => $response['errors']['message'] ?? 'تأیید پرداخت ناموفق بود'];
}

function zarinpal_http_post(string $url, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 30,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!$raw) {
        return [];
    }
    return json_decode($raw, true) ?: [];
}
