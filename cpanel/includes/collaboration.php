<?php
declare(strict_types=1);

function ensure_collaboration_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS collaboration_requests (
        id VARCHAR(32) NOT NULL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(32) NOT NULL,
        specialty VARCHAR(160) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'new',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_collab_status (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function collaboration_requests(PDO $pdo, int $limit = 40): array
{
    ensure_collaboration_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM collaboration_requests ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function collaboration_safe_return(string $next): string
{
    $path = parse_url(trim($next), PHP_URL_PATH);
    if ($path === '/contact' || $path === '/collaborate') {
        return $path;
    }

    return '/collaborate';
}

function collaboration_request_form_html(string $prefix = 'collab'): string
{
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'collab';
    $next = collaboration_safe_return((string) ($GLOBALS['path'] ?? '/collaborate'));
    ob_start();
    ?>
    <form class="footer-consult" method="post" action="<?= e(url('/collaborate')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <label class="sr-only" for="<?= e($prefix) ?>-name">نام و نام خانوادگی، الزامی</label>
      <input class="input" id="<?= e($prefix) ?>-name" name="name" required aria-required="true" maxlength="120" placeholder="نام و نام خانوادگی *" autocomplete="name">
      <label class="sr-only" for="<?= e($prefix) ?>-phone">شماره تماس، الزامی</label>
      <input class="input input-rtl" id="<?= e($prefix) ?>-phone" name="phone" required aria-required="true" inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
      <label class="sr-only" for="<?= e($prefix) ?>-specialty">تخصص یا زمینه همکاری، الزامی</label>
      <input class="input" id="<?= e($prefix) ?>-specialty" name="specialty" required aria-required="true" maxlength="160" placeholder="تخصص یا زمینه همکاری *">
      <label class="sr-only" for="<?= e($prefix) ?>-message">توضیح درخواست، الزامی</label>
      <textarea class="input" id="<?= e($prefix) ?>-message" name="message" required aria-required="true" maxlength="2000" rows="4" placeholder="توضیح سابقه، روزهای حضور یا نوع همکاری *"></textarea>
      <button type="submit" class="btn btn-primary">ارسال درخواست</button>
    </form>
    <?php

    return (string) ob_get_clean();
}

function collaboration_user_id(PDO $pdo, string $sql, array $params = []): ?string
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();

        return $id ? (string) $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** سه حساب مشخص: شیوا گرانمایه‌پور، عطیه گارسچی، عماد شهابیان. پخش به نقش منشی نیست. */
function collaboration_recipient_ids(PDO $pdo): array
{
    $shiva = collaboration_user_id($pdo, "SELECT id FROM users WHERE LOWER(username)='shgeranmaye' AND COALESCE(is_disabled,0)=0 LIMIT 1")
        ?? collaboration_user_id($pdo, "SELECT id FROM users WHERE role='DOCTOR' AND name LIKE '%گرانمایه%' AND COALESCE(is_disabled,0)=0 ORDER BY (LOWER(username)='shgeranmaye') DESC LIMIT 1")
        ?? collaboration_user_id($pdo, "SELECT id FROM users WHERE LOWER(username)='shgeranmaye' LIMIT 1")
        ?? collaboration_user_id($pdo, "SELECT id FROM users WHERE name LIKE '%گرانمایه%' AND role IN ('DOCTOR','ADMIN','SECRETARY') ORDER BY (role='DOCTOR') DESC, COALESCE(is_disabled,0) ASC LIMIT 1");

    $atiyeh = null;
    if (!function_exists('find_doctor_atiyeh_garsichi_id') && is_file(__DIR__ . '/seed_articles.php')) {
        require_once __DIR__ . '/seed_articles.php';
    }
    if (function_exists('find_doctor_atiyeh_garsichi_id')) {
        try {
            $found = find_doctor_atiyeh_garsichi_id($pdo);
            $atiyeh = is_string($found) && $found !== '' ? $found : null;
        } catch (Throwable $e) {
            $atiyeh = null;
        }
    }
    $atiyeh ??= collaboration_user_id(
        $pdo,
        "SELECT id FROM users
         WHERE (name LIKE '%گارسچی%' OR name LIKE '%گرسچی%')
           AND role IN ('DOCTOR','ADMIN','SECRETARY')
         ORDER BY (role='DOCTOR') DESC, (name LIKE '%گارسچی%') DESC, COALESCE(is_disabled,0) ASC
         LIMIT 1"
    );

    $emad = collaboration_user_id($pdo, "SELECT id FROM users WHERE LOWER(username)='eshahabian' AND COALESCE(is_disabled,0)=0 LIMIT 1")
        ?? collaboration_user_id($pdo, "SELECT id FROM users WHERE LOWER(username)='eshahabian' LIMIT 1")
        ?? collaboration_user_id(
            $pdo,
            "SELECT id FROM users
             WHERE name LIKE '%عماد%' AND name LIKE '%شهابیان%'
               AND role IN ('ADMIN','DOCTOR','SECRETARY')
             ORDER BY (LOWER(username)='eshahabian') DESC, (role='ADMIN') DESC, COALESCE(is_disabled,0) ASC
             LIMIT 1"
        );

    $ids = [];
    foreach ([$shiva, $atiyeh, $emad] as $id) {
        if ($id !== null && $id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

function collaboration_user_can_read(PDO $pdo, ?array $user): bool
{
    $id = trim((string) ($user['id'] ?? ''));

    return $id !== '' && in_array($id, collaboration_recipient_ids($pdo), true);
}

/**
 * kind ذخیره‌شده other است. اگر متن «همکار» یا «دستیار» داشته باشد،
 * notification_kind آن را تحویل شیفت یا دستیار حساب می‌کند و از «سایر پیام‌ها» کنار می‌رود.
 */
function collaboration_notification_plain(string $text): string
{
    return str_replace(
        ['همکار', 'دستیار'],
        ["هم\u{200C}کار", "دست\u{200C}یار"],
        $text
    );
}

function collaboration_notify_recipients(PDO $pdo, string $name, string $phone, string $specialty, string $message): void
{
    if (!function_exists('notify_user')) {
        require_once __DIR__ . '/notifications.php';
    }
    if (!function_exists('notify_user')) {
        return;
    }
    $ids = collaboration_recipient_ids($pdo);
    if ($ids === []) {
        error_log('collaboration notify: no matching recipients');

        return;
    }
    $title = 'درخواست پیوستن درمانگر';
    $body = collaboration_notification_plain($name . "\n" . 'تلفن: ' . $phone . "\n" . 'زمینه: ' . $specialty . "\n" . $message);
    $body = mb_substr($body, 0, 900);
    foreach ($ids as $userId) {
        try {
            notify_user($pdo, $userId, $title, $body, '/doctor/collaboration-requests', 'other');
        } catch (Throwable $e) {
            error_log('collaboration notify user ' . $userId . ': ' . $e->getMessage());
        }
    }
}
