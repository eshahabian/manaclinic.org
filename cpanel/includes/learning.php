<?php
declare(strict_types=1);

// Public learning catalogue. Add a lesson only when its content is ready.
function mana_learning_catalog(): array
{
    return [
        'couples-therapy' => [
            'title' => 'درس‌هایی از Couples Therapy',
            'description' => 'رابطه از زاویه‌ای دیگر؛ درس‌هایی برای تأمل دربارهٔ رابطه با نقطهٔ شروع مستند زوج‌درمانی با اورنا گورالنیک.',
            'image' => '/assets/img/couples-two-meanings-v2.webp',
            'image_alt' => 'تصویرسازی مفهومی دو برداشت متفاوت در رابطه',
            'cadence' => 'مجموعهٔ هفتگی',
            'seasons' => [
                1 => ['title' => 'فصل اول', 'lessons' => [
                    1 => [
                        'title' => 'سرِ اتفاق دعوا می‌کنیم، یا سرِ معنای آن؟',
                        'summary' => 'یک اتفاق، دو برداشت متفاوت؛ همراه با تمرین جداکردن اتفاق از برداشت.',
                        'label' => 'قسمت اول',
                        'format' => 'متن و تمرین',
                        'template' => 'couples_therapy.php',
                    ],
                ]],
            ],
        ],
        'schemas' => [
            'title' => 'شناخت طرح‌واره‌ها',
            'description' => 'شناخت الگوهای تکرارشوندهٔ زندگی؛ شروع با طرح‌وارهٔ رهاشدگی / بی‌ثباتی، نشانه‌ها و راه‌های کارکردن روی آن.',
            'image' => '/assets/img/schema-abandonment-v1.webp',
            'image_alt' => 'تصویرسازی مفهومی ترس از نبودن دیگری و ساختن احساس امنیت',
            'cadence' => 'مجموعهٔ آموزشی',
            'seasons' => [
                1 => ['title' => 'فصل اول', 'lessons' => [
                    1 => [
                        'title' => 'رهاشدگی / بی‌ثباتی؛ وقتی از رفتن دیگری می‌ترسیم',
                        'summary' => 'معنا، نشانه‌های احتمالی، چرخهٔ رابطه و تمرین‌های مقدماتی؛ همراه با معرفی مسیر درمان و منابع.',
                        'label' => 'درس اول',
                        'format' => 'راهنما و تمرین',
                        'template' => 'schema_abandonment.php',
                    ],
                ]],
            ],
        ],
    ];
}

function mana_learning_lesson_path(string $series, int $season, int $episode): string
{
    return '/learn/' . $series . '/season-' . $season . '/episode-' . $episode;
}

function mana_learning_lessons(string $slug, array $series): array
{
    $lessons = [];
    $seasons = $series['seasons'];
    ksort($seasons, SORT_NUMERIC);
    foreach ($seasons as $seasonNumber => $season) {
        $episodes = $season['lessons'];
        ksort($episodes, SORT_NUMERIC);
        foreach ($episodes as $episodeNumber => $lesson) {
            $lessons[] = $lesson + [
                'season' => $seasonNumber,
                'episode' => $episodeNumber,
                'season_title' => $season['title'],
                'path' => mana_learning_lesson_path($slug, $seasonNumber, $episodeNumber),
            ];
        }
    }
    return $lessons;
}
