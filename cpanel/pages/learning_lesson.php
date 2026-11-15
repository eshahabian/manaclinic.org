<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/learning.php';
$catalog = mana_learning_catalog();
$series = $catalog[$learningSeries] ?? null;
$lesson = $series['seasons'][$learningSeason]['lessons'][$learningEpisode] ?? null;
if ($lesson === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
// Template names come only from the server-owned catalogue, never a request path.
require __DIR__ . '/' . $lesson['template'];
