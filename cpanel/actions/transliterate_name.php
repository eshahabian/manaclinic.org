<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$name = trim((string) ($_GET['name'] ?? ''));
$part = ($_GET['part'] ?? 'first') === 'last' ? 'last' : 'first';

if ($name === '' || mb_strlen($name) > 64) {
    http_response_code(400);
    echo json_encode(['error' => 'نام نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$latin = transliterate_persian_name($pdo, $name, $part);
if ($latin === '') {
    http_response_code(422);
    echo json_encode(['error' => 'تبدیل نام ممکن نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['latin' => $latin], JSON_UNESCAPED_UNICODE);
