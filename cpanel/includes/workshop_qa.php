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
    foreach ([
        'is_private' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER body',
        'audience_user_id' => 'VARCHAR(32) NULL AFTER is_private',
    ] as $col => $ddl) {
        try {
            $has = $pdo->query("SHOW COLUMNS FROM workshop_qa_posts LIKE " . $pdo->quote($col))->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE workshop_qa_posts ADD COLUMN {$col} {$ddl}");
            }
        } catch (Throwable $ignored) {
        }
    }
    try {
        $pdo->exec("
          CREATE TABLE IF NOT EXISTS workshop_qa_likes (
            post_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (post_id, user_id),
            INDEX idx_wqa_like_post (post_id)
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

function workshop_qa_doctor_user_id(PDO $pdo, string $workshopId): string
{
    $stmt = $pdo->prepare('
      SELECT dp.user_id
      FROM workshops w
      JOIN doctor_profiles dp ON dp.id = w.doctor_id
      WHERE w.id=?
      LIMIT 1
    ');
    $stmt->execute([$workshopId]);

    return (string) ($stmt->fetchColumn() ?: '');
}

function workshop_qa_kind_label(string $kind): string
{
    return $kind === 'instructor' ? 'درمانگر دوره' : 'شرکت‌کننده';
}

function workshop_qa_snip(string $text, int $max = 90): string
{
    $text = trim($text);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $max ? (mb_substr($text, 0, $max) . '…') : $text;
    }

    return strlen($text) > $max ? (substr($text, 0, $max) . '…') : $text;
}

function workshop_qa_list(PDO $pdo, string $workshopId, array $opts = []): array
{
    ensure_workshop_qa_schema($pdo);
    $viewerId = (string) ($opts['viewer_id'] ?? '');
    $isDoctor = !empty($opts['is_doctor']);
    $private = !empty($opts['private']);

    $sql = '
      SELECT q.*, u.name AS author_name,
             (SELECT COUNT(*) FROM workshop_qa_likes l WHERE l.post_id = q.id) AS like_count
      FROM workshop_qa_posts q
      JOIN users u ON u.id = q.author_user_id
      WHERE q.workshop_id=?
    ';
    $params = [$workshopId];
    if ($private) {
        $sql .= ' AND q.is_private=1';
        if (!$isDoctor) {
            $sql .= ' AND (q.author_user_id=? OR q.audience_user_id=?)';
            $params[] = $viewerId;
            $params[] = $viewerId;
        }
    } else {
        $sql .= ' AND q.is_private=0';
    }
    $sql .= ' ORDER BY q.created_at ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $liked = [];
    if ($viewerId !== '' && $rows) {
        $ids = array_values(array_filter(array_map(static fn($r) => (string) ($r['id'] ?? ''), $rows)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $likeStmt = $pdo->prepare("SELECT post_id FROM workshop_qa_likes WHERE user_id=? AND post_id IN ({$in})");
            $likeStmt->execute(array_merge([$viewerId], $ids));
            foreach ($likeStmt->fetchAll() as $like) {
                $liked[(string) $like['post_id']] = true;
            }
        }
    }
    $byId = [];
    foreach ($rows as $row) {
        $byId[(string) $row['id']] = $row;
    }
    $out = [];
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $parentId = trim((string) ($row['parent_id'] ?? ''));
        $parent = $parentId !== '' && isset($byId[$parentId]) ? $byId[$parentId] : null;
        $row['liked'] = !empty($liked[$id]);
        $row['like_count'] = (int) ($row['like_count'] ?? 0);
        $row['parent_name'] = $parent ? trim((string) ($parent['author_name'] ?? '')) : '';
        $row['parent_body'] = $parent ? trim((string) ($parent['body'] ?? '')) : '';
        $out[] = $row;
    }

    return $out;
}

function workshop_qa_save(
    PDO $pdo,
    string $workshopId,
    string $authorUserId,
    string $authorKind,
    string $body,
    ?string $parentId = null,
    bool $isPrivate = false,
    ?string $audienceUserId = null
): string {
    ensure_workshop_qa_schema($pdo);
    if (!in_array($authorKind, ['patient', 'instructor'], true)) {
        throw new RuntimeException('نوع نویسنده نامعتبر است.');
    }
    $body = trim($body);
    $len = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
    if ($body === '') {
        throw new RuntimeException('متن پیام را بنویسید.');
    }
    if ($len > 4000) {
        throw new RuntimeException('متن خیلی طولانی است.');
    }
    if (!workshop_qa_is_offline_workshop($pdo, $workshopId)) {
        throw new RuntimeException('تالار گفتگو فقط برای دوره آفلاین است.');
    }
    $parentId = $parentId !== null ? trim($parentId) : '';
    if ($parentId !== '') {
        $parent = $pdo->prepare('SELECT id, is_private, author_user_id, audience_user_id FROM workshop_qa_posts WHERE id=? AND workshop_id=? LIMIT 1');
        $parent->execute([$parentId, $workshopId]);
        $parentRow = $parent->fetch();
        if (!$parentRow) {
            throw new RuntimeException('پیام برای پاسخ پیدا نشد.');
        }
        if ((int) ($parentRow['is_private'] ?? 0) === 1) {
            $isPrivate = true;
            if ($authorKind === 'instructor') {
                $audienceUserId = (string) ($parentRow['author_user_id'] ?? '') === $authorUserId
                    ? (string) ($parentRow['audience_user_id'] ?? '')
                    : (string) ($parentRow['author_user_id'] ?? '');
            }
        }
    } else {
        $parentId = null;
    }
    if ($isPrivate && $authorKind === 'patient' && ($audienceUserId === null || $audienceUserId === '')) {
        $audienceUserId = workshop_qa_doctor_user_id($pdo, $workshopId);
    }
    if ($isPrivate && ($audienceUserId === null || $audienceUserId === '')) {
        throw new RuntimeException('گیرنده پیام خصوصی مشخص نیست.');
    }

    $id = cuid();
    $pdo->prepare('
      INSERT INTO workshop_qa_posts (id, workshop_id, parent_id, author_user_id, author_kind, body, is_private, audience_user_id)
      VALUES (?,?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $workshopId,
        $parentId,
        $authorUserId,
        $authorKind,
        $body,
        $isPrivate ? 1 : 0,
        $isPrivate ? $audienceUserId : null,
    ]);

    return $id;
}

function workshop_qa_toggle_like(PDO $pdo, string $workshopId, string $postId, string $userId): bool
{
    ensure_workshop_qa_schema($pdo);
    $post = $pdo->prepare('SELECT id FROM workshop_qa_posts WHERE id=? AND workshop_id=? LIMIT 1');
    $post->execute([$postId, $workshopId]);
    if (!$post->fetch()) {
        throw new RuntimeException('پیام یافت نشد.');
    }
    $has = $pdo->prepare('SELECT 1 FROM workshop_qa_likes WHERE post_id=? AND user_id=? LIMIT 1');
    $has->execute([$postId, $userId]);
    if ($has->fetch()) {
        $pdo->prepare('DELETE FROM workshop_qa_likes WHERE post_id=? AND user_id=?')->execute([$postId, $userId]);
        return false;
    }
    $pdo->prepare('INSERT INTO workshop_qa_likes (post_id, user_id) VALUES (?,?)')->execute([$postId, $userId]);

    return true;
}

function workshop_qa_render(array $messages, array $opts = []): string
{
    $postUrl = (string) ($opts['post_url'] ?? '');
    $workshopId = (string) ($opts['workshop_id'] ?? '');
    $enrollmentId = (string) ($opts['enrollment_id'] ?? '');
    $canPost = !empty($opts['can_post']);
    $viewerId = (string) ($opts['viewer_id'] ?? '');
    $isDoctor = !empty($opts['is_doctor']);
    $tab = (string) ($opts['tab'] ?? 'public');
    if ($tab !== 'private') {
        $tab = 'public';
    }
    $publicUrl = (string) ($opts['public_url'] ?? '');
    $privateUrl = (string) ($opts['private_url'] ?? '');
    $isPrivateTab = $tab === 'private';

    ob_start();
    ?>
<section class="workshop-qa" id="workshop-qa">
  <h2 class="workshop-qa-title">تالار گفتگو</h2>
  <p class="muted workshop-qa-lead">چت همگانی دوره است؛ با نام خودتان بنویسید تا بقیه همان‌جا جواب بدهند. اگر خواستید فقط درمانگر ببیند، تب پیام خصوصی را باز کنید.</p>
  <div class="workshop-qa-tabs" role="tablist">
    <a class="workshop-qa-tab<?= !$isPrivateTab ? ' is-active' : '' ?>" href="<?= e($publicUrl !== '' ? $publicUrl : '#workshop-qa') ?>">همگانی</a>
    <a class="workshop-qa-tab<?= $isPrivateTab ? ' is-active' : '' ?>" href="<?= e($privateUrl !== '' ? $privateUrl : '#workshop-qa') ?>"><?= $isDoctor ? 'پیام‌های خصوصی' : 'پیام خصوصی با درمانگر' ?></a>
  </div>

  <div class="workshop-qa-feed" id="workshop-qa-feed">
    <?php if ($messages === []): ?>
      <p class="muted"><?= $isPrivateTab ? 'هنوز پیام خصوصی نیست.' : 'هنوز کسی در تالار ننوشته است. اولین پیام را شما بگذارید.' ?></p>
    <?php else: ?>
      <?php foreach ($messages as $msg): ?>
        <?php if (!is_array($msg)) { continue; } ?>
        <?php
          $mid = (string) ($msg['id'] ?? '');
          $name = trim((string) ($msg['author_name'] ?? '')) ?: 'شرکت‌کننده';
          $mine = $viewerId !== '' && (string) ($msg['author_user_id'] ?? '') === $viewerId;
        ?>
        <article class="workshop-qa-msg<?= $mine ? ' is-mine' : '' ?>" id="qa-<?= e($mid) ?>">
          <div class="workshop-qa-meta">
            <strong><?= e($name) ?></strong>
            <span class="badge"><?= e(workshop_qa_kind_label((string) ($msg['author_kind'] ?? 'patient'))) ?></span>
            <?php if (!empty($msg['created_at'])): ?>
              <span class="muted"><?= e(format_fa_datetime((string) $msg['created_at'])) ?></span>
            <?php endif; ?>
          </div>
          <?php if ((string) ($msg['parent_body'] ?? '') !== ''): ?>
            <div class="workshop-qa-quote">در پاسخ به <?= e((string) ($msg['parent_name'] ?: 'پیام')) ?>: <?= e(workshop_qa_snip((string) $msg['parent_body'])) ?></div>
          <?php endif; ?>
          <p><?= nl2br(e((string) ($msg['body'] ?? ''))) ?></p>
          <div class="workshop-qa-actions">
            <?php if ($canPost): ?>
              <form method="post" action="<?= e($postUrl) ?>" class="workshop-qa-like-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="like">
                <input type="hidden" name="workshop_id" value="<?= e($workshopId) ?>">
                <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
                <input type="hidden" name="post_id" value="<?= e($mid) ?>">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <button type="submit" class="workshop-qa-like<?= !empty($msg['liked']) ? ' is-on' : '' ?>">
                  <?= !empty($msg['liked']) ? 'پسندیدی' : 'پسندیدن' ?>
                  · <?= e(to_fa_digits((string) (int) ($msg['like_count'] ?? 0))) ?>
                </button>
              </form>
              <button type="button" class="workshop-qa-reply-btn" data-qa-reply="<?= e($mid) ?>" data-qa-name="<?= e($name) ?>">ریپلای</button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($canPost): ?>
    <form class="workshop-qa-form workshop-qa-ask" method="post" action="<?= e($postUrl) ?>" id="workshop-qa-composer">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="post">
      <input type="hidden" name="workshop_id" value="<?= e($workshopId) ?>">
      <input type="hidden" name="enrollment_id" value="<?= e($enrollmentId) ?>">
      <input type="hidden" name="parent_id" id="qa-parent-id" value="">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <?php if ($isPrivateTab): ?>
        <input type="hidden" name="is_private" value="1">
      <?php endif; ?>
      <p class="workshop-qa-replying" id="qa-replying" hidden></p>
      <label class="label" for="qa-ask-body"><?= $isPrivateTab ? ($isDoctor ? 'پاسخ خصوصی' : 'پیام فقط برای درمانگر') : 'پیام همگانی' ?></label>
      <?php if ($isPrivateTab && $isDoctor): ?>
        <p class="muted" style="font-size:.82rem;margin:.2rem 0 .45rem">برای جواب خصوصی، اول ریپلای همان پیام مراجع را بزنید.</p>
      <?php endif; ?>
      <textarea class="input" id="qa-ask-body" name="body" rows="3" maxlength="4000" required placeholder="<?= $isPrivateTab ? 'فقط شما و درمانگر این را می‌بینید…' : 'با نام خودتان برای همه بنویسید…' ?>"></textarea>
      <div class="workshop-qa-compose-row">
        <button class="btn btn-primary btn-sm" type="submit">ارسال</button>
        <button class="btn btn-outline btn-sm" type="button" id="qa-reply-cancel" hidden>لغو ریپلای</button>
      </div>
    </form>
    <script>
    (function(){
      var parent = document.getElementById("qa-parent-id");
      var hint = document.getElementById("qa-replying");
      var cancel = document.getElementById("qa-reply-cancel");
      var box = document.getElementById("qa-ask-body");
      function clearReply(){
        if (parent) parent.value = "";
        if (hint) { hint.hidden = true; hint.textContent = ""; }
        if (cancel) cancel.hidden = true;
      }
      document.querySelectorAll("[data-qa-reply]").forEach(function(btn){
        btn.addEventListener("click", function(){
          if (parent) parent.value = btn.getAttribute("data-qa-reply") || "";
          if (hint) {
            hint.hidden = false;
            hint.textContent = "ریپلای به " + (btn.getAttribute("data-qa-name") || "پیام");
          }
          if (cancel) cancel.hidden = false;
          if (box) box.focus();
        });
      });
      if (cancel) cancel.addEventListener("click", clearReply);
      var feed = document.getElementById("workshop-qa-feed");
      if (feed) feed.scrollTop = feed.scrollHeight;
    })();
    </script>
  <?php endif; ?>
</section>
    <?php
    return (string) ob_get_clean();
}
