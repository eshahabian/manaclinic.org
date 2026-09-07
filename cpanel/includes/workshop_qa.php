<?php
declare(strict_types=1);

function ensure_workshop_qa_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS workshop_qa_posts (
            id VARCHAR(32) PRIMARY KEY,
            workshop_id VARCHAR(32) NOT NULL,
            parent_id VARCHAR(32) NULL,
            author_user_id VARCHAR(32) NOT NULL,
            author_kind ENUM('patient','instructor') NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wqa_workshop (workshop_id, created_at),
            INDEX idx_wqa_parent (parent_id),
            CONSTRAINT fk_wqa_workshop FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $ignored) {
    }
    $ready = true;
}

function workshop_qa_url_doctor(string $workshopId): string
{
    return url('/doctor/workshops/qa?id=' . rawurlencode($workshopId));
}

function workshop_qa_is_offline_workshop(PDO $pdo, string $workshopId): bool
{
    $stmt = $pdo->prepare('SELECT type FROM workshops WHERE id=? LIMIT 1');
    $stmt->execute([$workshopId]);
    $type = (string) ($stmt->fetchColumn() ?: '');

    return function_exists('workshop_is_offline') && workshop_is_offline($type);
}

function workshop_qa_patient_enrollment(PDO $pdo, string $patientId, string $workshopId): ?array
{
    $stmt = $pdo->prepare("
      SELECT e.*, w.type, w.title, w.doctor_id
      FROM workshop_enrollments e
      JOIN workshops w ON w.id = e.workshop_id
      WHERE e.patient_id=? AND e.workshop_id=?
        AND e.status IN ('CONFIRMED','COMPLETED')
      LIMIT 1
    ");
    $stmt->execute([$patientId, $workshopId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !workshop_is_offline((string) ($row['type'] ?? ''))) {
        return null;
    }

    return $row;
}

function workshop_qa_doctor_owns(PDO $pdo, string $doctorProfileId, string $workshopId): bool
{
    $stmt = $pdo->prepare("SELECT id FROM workshops WHERE id=? AND doctor_id=? AND type='OFFLINE' LIMIT 1");
    $stmt->execute([$workshopId, $doctorProfileId]);

    return (bool) $stmt->fetch();
}

function workshop_qa_list(PDO $pdo, string $workshopId): array
{
    ensure_workshop_qa_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT q.*, u.name AS author_name
      FROM workshop_qa_posts q
      JOIN users u ON u.id = q.author_user_id
      WHERE q.workshop_id=?
      ORDER BY q.created_at ASC
    ");
    $stmt->execute([$workshopId]);
    $rows = $stmt->fetchAll();
    $threads = [];
    $replies = [];
    foreach ($rows as $row) {
        $parent = trim((string) ($row['parent_id'] ?? ''));
        if ($parent === '') {
            $row['replies'] = [];
            $threads[(string) $row['id']] = $row;
        } else {
            $replies[$parent][] = $row;
        }
    }
    foreach ($replies as $pid => $list) {
        if (isset($threads[$pid])) {
            $threads[$pid]['replies'] = $list;
        }
    }

    return array_values($threads);
}

function workshop_qa_save(
    PDO $pdo,
    string $workshopId,
    string $authorUserId,
    string $authorKind,
    string $body,
    ?string $parentId = null
): string {
    ensure_workshop_qa_schema($pdo);
    if (!in_array($authorKind, ['patient', 'instructor'], true)) {
        throw new RuntimeException('نوع نویسنده نامعتبر است.');
    }
    $body = trim($body);
    $len = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
    if ($body === '') {
        throw new RuntimeException('متن پرسش یا پاسخ را بنویسید.');
    }
    if ($len > 4000) {
        throw new RuntimeException('متن خیلی طولانی است.');
    }
    if (!workshop_qa_is_offline_workshop($pdo, $workshopId)) {
        throw new RuntimeException('پرسش و پاسخ فقط برای دوره آفلاین است.');
    }
    $parentId = $parentId !== null ? trim($parentId) : '';
    if ($parentId !== '') {
        $parent = $pdo->prepare('SELECT id FROM workshop_qa_posts WHERE id=? AND workshop_id=? AND parent_id IS NULL LIMIT 1');
        $parent->execute([$parentId, $workshopId]);
        if (!$parent->fetch()) {
            throw new RuntimeException('پرسش یافت نشد.');
        }
    } else {
        $parentId = null;
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO workshop_qa_posts (id, workshop_id, parent_id, author_user_id, author_kind, body)
      VALUES (?,?,?,?,?,?)
    ')->execute([$id, $workshopId, $parentId, $authorUserId, $authorKind, $body]);

    return $id;
}

function workshop_qa_kind_label(string $kind): string
{
    return $kind === 'instructor' ? 'درمانگر دوره' : 'شرکت‌کننده';
}

function workshop_qa_render(array $threads, array $opts = []): string
{
    $postUrl = (string) ($opts['post_url'] ?? '');
    $workshopId = (string) ($opts['workshop_id'] ?? '');
    $enrollmentId = (string) ($opts['enrollment_id'] ?? '');
    $canPost = !empty($opts['can_post']);
    $askLabel = (string) ($opts['ask_label'] ?? 'پرسش جدید');
    $replyLabel = (string) ($opts['reply_label'] ?? 'پاسخ');

    ob_start();
    ?>
<section class="workshop-qa" id="workshop-qa">
  <h2 class="workshop-qa-title">پرسش و پاسخ همگانی</h2>
  <p class="muted workshop-qa-lead">سؤال‌ها برای همهٔ شرکت‌کننده‌های این دوره آفلاین دیده می‌شود. درمانگر همان‌جا پاسخ می‌دهد.</p>

  <?php if ($threads === []): ?>
    <p class="muted">هنوز پرسشی ثبت نشده است.</p>
  <?php else: ?>
    <ol class="workshop-qa-threads">
      <?php foreach ($threads as $thread): ?>
        <?php if (!is_array($thread)) { continue; } ?>
        <li class="workshop-qa-thread">
          <div class="workshop-qa-post">
            <div class="workshop-qa-meta">
              <strong><?= e(trim((string) ($thread['author_name'] ?? 'شرکت‌کننده')) ?: 'شرکت‌کننده') ?></strong>
              <span class="badge"><?= e(workshop_qa_kind_label((string) ($thread['author_kind'] ?? 'patient'))) ?></span>
              <?php if (!empty($thread['created_at'])): ?>
                <span class="muted"><?= e(format_fa_datetime((string) $thread['created_at'])) ?></span>
              <?php endif; ?>
            </div>
            <p><?= nl2br(e((string) ($thread['body'] ?? ''))) ?></p>
          </div>
          <?php foreach (($thread['replies'] ?? []) as $reply): ?>
            <?php if (!is_array($reply)) { continue; } ?>
            <div class="workshop-qa-post is-reply">
              <div class="workshop-qa-meta">
                <strong><?= e(trim((string) ($reply['author_name'] ?? '')) ?: 'پاسخ') ?></strong>
                <span class="badge"><?= e(workshop_qa_kind_label((string) ($reply['author_kind'] ?? 'patient'))) ?></span>
                <?php if (!empty($reply['created_at'])): ?>
                  <span class="muted"><?= e(format_fa_datetime((string) $reply['created_at'])) ?></span>
                <?php endif; ?>
              </div>
              <p><?= nl2br(e((string) ($reply['body'] ?? ''))) ?></p>
            </div>
          <?php endforeach; ?>
          <?php if ($canPost): ?>
            <form class="workshop-qa-form" method="post" action="<?= e($postUrl) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="workshop_id" value="<?= e($workshopId) ?>">
              <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
              <input type="hidden" name="parent_id" value="<?= e((string) ($thread['id'] ?? '')) ?>">
              <label class="label" for="qa-reply-<?= e((string) ($thread['id'] ?? '')) ?>"><?= e($replyLabel) ?></label>
              <textarea class="input" id="qa-reply-<?= e((string) ($thread['id'] ?? '')) ?>" name="body" rows="2" maxlength="4000" required placeholder="پاسخ را بنویسید…"></textarea>
              <button class="btn btn-outline btn-sm" type="submit">ارسال پاسخ</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($canPost): ?>
    <form class="workshop-qa-form workshop-qa-ask" method="post" action="<?= e($postUrl) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="workshop_id" value="<?= e($workshopId) ?>">
      <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
      <label class="label" for="qa-ask-body"><?= e($askLabel) ?></label>
      <textarea class="input" id="qa-ask-body" name="body" rows="3" maxlength="4000" required placeholder="سؤال خود را برای همه بنویسید…"></textarea>
      <button class="btn btn-primary btn-sm" type="submit">ثبت پرسش</button>
    </form>
  <?php endif; ?>
</section>
    <?php
    return (string) ob_get_clean();
}
