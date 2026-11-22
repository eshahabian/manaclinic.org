<?php
declare(strict_types=1);

function mana_learning_can_view_counts(?array $user): bool
{
    return match ($user['role'] ?? '') {
        'ADMIN' => strcasecmp((string) ($user['username'] ?? ''), 'eshahabian') === 0,
        'DOCTOR' => ($user['id'] ?? '') === '36dfdb042124cb0a89814c30',
        default => false,
    };
}

function mana_learning_ensure_views(PDO $pdo): void
{
    static $ready = false;
    if (!$ready) {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS learning_lesson_views (
            lesson_path VARCHAR(191) PRIMARY KEY,
            view_count BIGINT UNSIGNED NOT NULL DEFAULT 0
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ready = true;
    }
}

/** A null result means the response must not contain any counts. */
function mana_learning_views(PDO $pdo, ?array $user, ?string $openedLesson = null): ?array
{
    header('Vary: Cookie', false);
    $showCounts = mana_learning_can_view_counts($user)
        && !user_account_is_disabled($pdo, (string) ($user['id'] ?? ''));
    if ($showCounts) {
        header('Cache-Control: private, no-store');
    }
    $purpose = (string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . (string) ($_SERVER['HTTP_PURPOSE'] ?? '');
    $countVisit = $openedLesson !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && stripos($purpose, 'prefetch') === false && stripos($purpose, 'prerender') === false;
    if (!$showCounts && !$countVisit) {
        return null;
    }
    try {
        mana_learning_ensure_views($pdo);
        if ($countVisit) {
            $stmt = $pdo->prepare('
                INSERT INTO learning_lesson_views (lesson_path, view_count) VALUES (?, 1)
                ON DUPLICATE KEY UPDATE view_count = view_count + 1
            ');
            $stmt->execute([$openedLesson]);
        }
        if ($showCounts) {
            if ($openedLesson !== null) {
                $stmt = $pdo->prepare('SELECT lesson_path, view_count FROM learning_lesson_views WHERE lesson_path = ?');
                $stmt->execute([$openedLesson]);
                return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            return $pdo->query('SELECT lesson_path, view_count FROM learning_lesson_views')->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    } catch (Throwable $exception) {
        error_log('Learning view counter failed (error ' . $exception->getCode() . ').');
    }
    return null;
}

function mana_learning_view_badge(?array $counts, string $lessonPath): string
{
    if ($counts === null) {
        return '';
    }
    $count = to_fa_digits(number_format((int) ($counts[$lessonPath] ?? 0)));
    return '<span class="learning-view-count" title="تعداد بازدید">'
        . '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
        . '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>'
        . '<span aria-label="' . e($count . ' بازدید') . '">' . e($count) . '</span></span>';
}
