<?php
declare(strict_types=1);

/** @return array<string,array{label:string,class:string,title:string,aria:string,url:string,on:bool}> */
function clinic_messenger_catalog(PDO $pdo): array
{
    $defs = [
        'whatsapp' => [
            'label' => 'واتساپ',
            'class' => 'footer-social-whatsapp',
            'title' => 'واتساپ',
            'aria' => 'چت در واتساپ',
            'url' => 'https://wa.me/989101387838',
            'on' => true,
        ],
        'telegram' => [
            'label' => 'تلگرام',
            'class' => 'footer-social-telegram',
            'title' => 'تلگرام',
            'aria' => 'چت در تلگرام',
            'url' => 'https://t.me/+989101387838',
            'on' => true,
        ],
        'instagram' => [
            'label' => 'اینستاگرام',
            'class' => 'footer-social-instagram',
            'title' => 'اینستاگرام',
            'aria' => 'صفحه اینستاگرام مانا کلینیک',
            'url' => 'https://www.instagram.com/mana_clinic/',
            'on' => true,
        ],
        'bale' => [
            'label' => 'بله',
            'class' => 'footer-social-bale',
            'title' => 'بله',
            'aria' => 'چت در بله',
            'url' => '',
            'on' => false,
        ],
    ];
    if (!function_exists('mail_setting_get')) {
        return $defs;
    }
    foreach ($defs as $key => $item) {
        $on = mail_setting_get($pdo, 'messenger_' . $key . '_on', '');
        $url = mail_setting_get($pdo, 'messenger_' . $key . '_url', '');
        if ($on === '1' || $on === '0') {
            $defs[$key]['on'] = $on === '1';
        }
        if ($url !== '') {
            $defs[$key]['url'] = $url;
        }
    }

    return $defs;
}

/** @return list<array{key:string,class:string,title:string,aria:string,url:string}> */
function clinic_messenger_public(PDO $pdo): array
{
    $out = [];
    foreach (clinic_messenger_catalog($pdo) as $key => $item) {
        $url = trim((string) $item['url']);
        if (empty($item['on']) || $url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $out[] = [
            'key' => $key,
            'class' => (string) $item['class'],
            'title' => (string) $item['title'],
            'aria' => (string) $item['aria'],
            'url' => $url,
        ];
    }

    return $out;
}

function clinic_gateway_config(array $config): array
{
    global $pdo;
    if (!$pdo instanceof PDO || !function_exists('mail_setting_get')) {
        return $config;
    }
    $merchant = trim((string) mail_setting_get($pdo, 'gateway_merchant_id', ''));
    if ($merchant !== '') {
        $config['zarinpal_merchant_id'] = $merchant;
    }
    $sandbox = mail_setting_get($pdo, 'gateway_sandbox', '');
    if ($sandbox === '1' || $sandbox === '0') {
        $config['zarinpal_sandbox'] = $sandbox === '1';
    }
    $online = mail_setting_get($pdo, 'gateway_online', '');
    if ($online === '1' || $online === '0') {
        $config['online_payment_enabled'] = $online === '1';
    }

    return $config;
}
