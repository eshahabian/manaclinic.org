<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/learning.php';
require_once __DIR__ . '/../includes/learning_views.php';
$learningSeries = $learningSeries ?? 'couples-therapy';
$learningSeason = $learningSeason ?? 1;
$learningEpisode = $learningEpisode ?? 1;
$catalog = mana_learning_catalog();
$series = $catalog[$learningSeries] ?? null;
$lesson = $series['seasons'][$learningSeason]['lessons'][$learningEpisode] ?? null;
if ($lesson === null) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$lessonPath = mana_learning_lesson_path($learningSeries, $learningSeason, $learningEpisode);
$learningViewBadge = mana_learning_view_badge(mana_learning_views($pdo, current_user(), $lessonPath), $lessonPath);
// Template names come only from the server-owned catalogue, never a request path.
require __DIR__ . '/' . $lesson['template'];
