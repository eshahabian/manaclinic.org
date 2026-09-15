<?php
declare(strict_types=1);

/**
 * تخته یادداشت مشترک منشی‌ها (سبک Notion / Reminders):
 * چک‌لیست مشترک، Enter برای ثبت، بولد و هایلایت.
 * دسترسی: منشی‌ها + ادمین + دکتر شیوا گرانمایه‌پور.
 */

function ensure_staff_board_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS staff_shared_notes (
        id VARCHAR(32) PRIMARY KEY,
        body TEXT NOT NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 0,
        is_bold TINYINT(1) NOT NULL DEFAULT 0,
        is_highlight TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_by VARCHAR(32) NOT NULL,
        updated_by VARCHAR(32) NULL,
        done_by VARCHAR(32) NULL,
        done_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_staff_board_open (is_done, sort_order, created_at),
        INDEX idx_staff_board_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

/** منشی، ادمین، یا دکتر شیوا */
function staff_board_can_access(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $role = strtoupper((string) ($user['role'] ?? ''));
    if ($role === 'SECRETARY' || $role === 'ADMIN') {
        return true;
    }
    if ($role === 'DOCTOR' && function_exists('doctor_is_shiva') && doctor_is_shiva($user)) {
        return true;
    }

    return false;
}

function staff_board_can_edit(?array $user): bool
{
    return staff_board_can_access($user);
}

function staff_board_require_user(): array
{
    $user = require_login(['SECRETARY', 'ADMIN', 'DOCTOR']);
    if (!staff_board_can_access($user)) {
        flash_set('error', 'دسترسی به یادداشت مشترک منشی‌ها فقط برای منشی‌ها، مدیر و دکتر شیوا گرانمایه‌پور است.');
        $role = strtoupper((string) ($user['role'] ?? ''));
        if ($role === 'DOCTOR') {
            redirect('/doctor/notifications');
        }
        if ($role === 'ADMIN') {
            redirect('/admin');
        }
        redirect('/secretary/messages');
    }

    return $user;
}

function staff_board_path_for(?array $user): string
{
    $role = strtoupper((string) ($user['role'] ?? ''));
    if ($role === 'ADMIN') {
        return '/admin/staff-board';
    }
    if ($role === 'DOCTOR') {
        return '/doctor/staff-board';
    }

    return '/secretary/board';
}

function staff_board_api_url(): string
{
    return '/api/staff-board';
}

function staff_board_normalize_body(string $body): string
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    $body = preg_replace("/\n{3,}/u", "\n\n", $body) ?? $body;
    if (mb_strlen($body) > 4000) {
        throw new RuntimeException('متن یادداشت خیلی طولانی است (حداکثر ۴۰۰۰ کاراکتر).');
    }

    return $body;
}

function staff_board_row_public(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'is_done' => !empty($row['is_done']),
        'is_bold' => !empty($row['is_bold']),
        'is_highlight' => !empty($row['is_highlight']),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'created_by' => (string) ($row['created_by'] ?? ''),
        'created_name' => (string) ($row['created_name'] ?? $row['created_username'] ?? 'منشی'),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'done_at' => (string) ($row['done_at'] ?? ''),
        'created_label' => function_exists('format_fa_datetime')
            ? format_fa_datetime((string) ($row['created_at'] ?? ''))
            : (string) ($row['created_at'] ?? ''),
    ];
}

function staff_board_list(PDO $pdo, string $filter = 'open', int $limit = 200): array
{
    ensure_staff_board_schema($pdo);
    $filter = in_array($filter, ['open', 'done', 'all'], true) ? $filter : 'open';
    $limit = max(1, min(300, $limit));
    $where = '';
    if ($filter === 'open') {
        $where = 'WHERE n.is_done = 0';
    } elseif ($filter === 'done') {
        $where = 'WHERE n.is_done = 1';
    }
    $sql = "
      SELECT n.*, u.name AS created_name, u.username AS created_username
      FROM staff_shared_notes n
      LEFT JOIN users u ON u.id = n.created_by
      {$where}
      ORDER BY n.is_done ASC, n.sort_order ASC, n.created_at DESC
      LIMIT {$limit}
    ";
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = staff_board_row_public($row);
    }

    return $out;
}

function staff_board_get(PDO $pdo, string $id): ?array
{
    ensure_staff_board_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT n.*, u.name AS created_name, u.username AS created_username
      FROM staff_shared_notes n
      LEFT JOIN users u ON u.id = n.created_by
      WHERE n.id = ?
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ? staff_board_row_public($row) : null;
}

function staff_board_next_sort(PDO $pdo): int
{
    $max = (int) $pdo->query('SELECT COALESCE(MIN(sort_order), 0) FROM staff_shared_notes WHERE is_done = 0')->fetchColumn();

    return $max - 1;
}

function staff_board_create(PDO $pdo, string $userId, string $body, bool $bold = false, bool $highlight = false): array
{
    ensure_staff_board_schema($pdo);
    $body = staff_board_normalize_body($body);
    if ($body === '') {
        throw new RuntimeException('متن یادداشت را بنویسید.');
    }
    $id = cuid();
    $sort = staff_board_next_sort($pdo);
    $stmt = $pdo->prepare('
      INSERT INTO staff_shared_notes
        (id, body, is_done, is_bold, is_highlight, sort_order, created_by, updated_by)
      VALUES (?,?,0,?,?,?,?,?)
    ');
    $stmt->execute([
        $id,
        $body,
        $bold ? 1 : 0,
        $highlight ? 1 : 0,
        $sort,
        $userId,
        $userId,
    ]);

    $row = staff_board_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('ذخیره یادداشت ناموفق بود.');
    }

    return $row;
}

function staff_board_update_body(PDO $pdo, string $id, string $userId, string $body): array
{
    ensure_staff_board_schema($pdo);
    $body = staff_board_normalize_body($body);
    if ($body === '') {
        throw new RuntimeException('متن یادداشت خالی نشود.');
    }
    $stmt = $pdo->prepare('UPDATE staff_shared_notes SET body=?, updated_by=? WHERE id=?');
    $stmt->execute([$body, $userId, $id]);
    if ($stmt->rowCount() < 1 && !staff_board_get($pdo, $id)) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }
    $row = staff_board_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }

    return $row;
}

function staff_board_toggle_done(PDO $pdo, string $id, string $userId, ?bool $force = null): array
{
    ensure_staff_board_schema($pdo);
    $current = $pdo->prepare('SELECT is_done FROM staff_shared_notes WHERE id=? LIMIT 1');
    $current->execute([$id]);
    $wasDone = $current->fetchColumn();
    if ($wasDone === false) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }
    $next = $force !== null ? ($force ? 1 : 0) : ((int) $wasDone ? 0 : 1);
    if ($next) {
        $pdo->prepare('UPDATE staff_shared_notes SET is_done=1, done_at=NOW(), done_by=?, updated_by=? WHERE id=?')
            ->execute([$userId, $userId, $id]);
    } else {
        $pdo->prepare('UPDATE staff_shared_notes SET is_done=0, done_at=NULL, done_by=NULL, updated_by=? WHERE id=?')
            ->execute([$userId, $id]);
    }
    $row = staff_board_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }

    return $row;
}

function staff_board_set_format(PDO $pdo, string $id, string $userId, ?bool $bold = null, ?bool $highlight = null): array
{
    ensure_staff_board_schema($pdo);
    $sets = ['updated_by=?'];
    $params = [$userId];
    if ($bold !== null) {
        $sets[] = 'is_bold=?';
        $params[] = $bold ? 1 : 0;
    }
    if ($highlight !== null) {
        $sets[] = 'is_highlight=?';
        $params[] = $highlight ? 1 : 0;
    }
    if (count($sets) === 1) {
        throw new RuntimeException('فرمت مشخص نشده است.');
    }
    $params[] = $id;
    $pdo->prepare('UPDATE staff_shared_notes SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
    $row = staff_board_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }

    return $row;
}

function staff_board_delete(PDO $pdo, string $id): void
{
    ensure_staff_board_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM staff_shared_notes WHERE id=?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('یادداشت پیدا نشد.');
    }
}

function staff_board_counts(PDO $pdo): array
{
    ensure_staff_board_schema($pdo);
    $open = (int) $pdo->query('SELECT COUNT(*) FROM staff_shared_notes WHERE is_done=0')->fetchColumn();
    $done = (int) $pdo->query('SELECT COUNT(*) FROM staff_shared_notes WHERE is_done=1')->fetchColumn();

    return ['open' => $open, 'done' => $done, 'all' => $open + $done];
}

function staff_board_render(PDO $pdo, array $user, array $opts = []): string
{
    $filter = (string) ($opts['filter'] ?? 'open');
    $filter = in_array($filter, ['open', 'done', 'all'], true) ? $filter : 'open';
    $canEdit = staff_board_can_edit($user);
    $items = staff_board_list($pdo, $filter);
    $counts = staff_board_counts($pdo);
    $api = url(staff_board_api_url());
    $selfPath = staff_board_path_for($user);

    ob_start();
    ?>
    <div class="stack staff-board" id="staff-board"
         data-api="<?= e($api) ?>"
         data-filter="<?= e($filter) ?>"
         data-can-edit="<?= $canEdit ? '1' : '0' ?>"
         data-poll-ms="12000">
      <header class="staff-board-hero">
        <div>
          <p class="staff-board-kicker">همکاری منشی‌ها</p>
          <h1>یادداشت مشترک</h1>
          <p class="muted staff-board-lead">
            مثل تخته Notion یا Reminders: نکته‌ها را بنویسید تا همکار هم ببیند —
            مثلاً «تا آخر ماه آینده وقت نمی‌خواهد». Enter ذخیره می‌کند؛ تیک بزنید وقتی انجام شد یا دیده شد.
          </p>
        </div>
        <div class="staff-board-stats" aria-live="polite">
          <span><strong id="staff-board-count-open"><?= e(to_fa_digits((string) $counts['open'])) ?></strong> باز</span>
          <span><strong id="staff-board-count-done"><?= e(to_fa_digits((string) $counts['done'])) ?></strong> انجام‌شده</span>
        </div>
      </header>

      <div class="panel-subtabs staff-board-tabs" role="tablist">
        <?php
        $tabs = [
            'open' => 'باز',
            'done' => 'انجام‌شده',
            'all' => 'همه',
        ];
        foreach ($tabs as $key => $label):
            $href = url($selfPath . ($key === 'open' ? '' : '?filter=' . $key));
            $countKey = $key === 'all' ? 'all' : $key;
            ?>
          <a class="panel-subtab<?= $filter === $key ? ' is-active' : '' ?>" href="<?= e($href) ?>" data-filter-link="<?= e($key) ?>">
            <?= e($label) ?>
            <span class="panel-subtab-count" data-count-for="<?= e($countKey) ?>"><?= e(to_fa_digits((string) $counts[$countKey])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($canEdit): ?>
        <form class="staff-board-composer panel" id="staff-board-composer" autocomplete="off">
          <div class="staff-board-composer-tools" role="group" aria-label="قالب متن">
            <button type="button" class="staff-board-tool" id="sb-tool-bold" aria-pressed="false" title="پررنگ">ب</button>
            <button type="button" class="staff-board-tool staff-board-tool-hl" id="sb-tool-hl" aria-pressed="false" title="هایلایت">هایلایت</button>
          </div>
          <label class="sr-only" for="staff-board-input">یادداشت جدید</label>
          <textarea class="input staff-board-input" id="staff-board-input" rows="2" maxlength="4000"
                    placeholder="نکته کاری را بنویسید و Enter بزنید… (Shift+Enter خط جدید)"></textarea>
          <div class="staff-board-composer-foot">
            <span class="muted" id="staff-board-status">Enter = ذخیره · Shift+Enter = خط جدید</span>
            <button type="submit" class="btn btn-primary btn-sm">افزودن</button>
          </div>
        </form>
      <?php else: ?>
        <p class="muted">فقط مشاهده — ویرایش برای منشی‌ها و مدیر است.</p>
      <?php endif; ?>

      <ul class="staff-board-list" id="staff-board-list" aria-live="polite">
        <?php if (!$items): ?>
          <li class="staff-board-empty muted" id="staff-board-empty">هنوز یادداشتی نیست. اولین نکته را بنویسید.</li>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?= staff_board_item_html($item, $canEdit) ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
    <?php
    return (string) ob_get_clean();
}

function staff_board_item_html(array $item, bool $canEdit): string
{
    $id = e((string) ($item['id'] ?? ''));
    $body = e((string) ($item['body'] ?? ''));
    $done = !empty($item['is_done']);
    $bold = !empty($item['is_bold']);
    $hl = !empty($item['is_highlight']);
    $name = e((string) ($item['created_name'] ?? ''));
    $when = e((string) ($item['created_label'] ?? ''));
    $cls = 'staff-board-item';
    if ($done) {
        $cls .= ' is-done';
    }
    if ($bold) {
        $cls .= ' is-bold';
    }
    if ($hl) {
        $cls .= ' is-highlight';
    }
    ob_start();
    ?>
    <li class="<?= e($cls) ?>" data-id="<?= $id ?>" data-done="<?= $done ? '1' : '0' ?>" data-bold="<?= $bold ? '1' : '0' ?>" data-highlight="<?= $hl ? '1' : '0' ?>">
      <label class="staff-board-check">
        <input type="checkbox" <?= $done ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> aria-label="انجام شد">
      </label>
      <div class="staff-board-body">
        <?php if ($canEdit && !$done): ?>
          <div class="staff-board-text" contenteditable="true" role="textbox" aria-label="متن یادداشت" spellcheck="true"><?= $body ?></div>
        <?php else: ?>
          <div class="staff-board-text"><?= nl2br($body) ?></div>
        <?php endif; ?>
        <div class="staff-board-meta">
          <span><?= $name ?></span>
          <span>·</span>
          <time><?= $when ?></time>
        </div>
      </div>
      <?php if ($canEdit): ?>
        <div class="staff-board-actions">
          <button type="button" class="staff-board-action" data-act="bold" title="پررنگ" aria-pressed="<?= $bold ? 'true' : 'false' ?>">ب</button>
          <button type="button" class="staff-board-action is-hl" data-act="highlight" title="هایلایت" aria-pressed="<?= $hl ? 'true' : 'false' ?>">هـ</button>
          <button type="button" class="staff-board-action is-danger" data-act="delete" title="حذف">×</button>
        </div>
      <?php endif; ?>
    </li>
    <?php
    return (string) ob_get_clean();
}

function staff_board_scripts(): string
{
    $js = <<<'JS'
<script>
(function () {
  var root = document.getElementById('staff-board');
  if (!root) return;
  var api = root.getAttribute('data-api') || '';
  var canEdit = root.getAttribute('data-can-edit') === '1';
  var filter = root.getAttribute('data-filter') || 'open';
  var pollMs = parseInt(root.getAttribute('data-poll-ms') || '12000', 10) || 12000;
  var list = document.getElementById('staff-board-list');
  var composer = document.getElementById('staff-board-composer');
  var input = document.getElementById('staff-board-input');
  var statusEl = document.getElementById('staff-board-status');
  var toolBold = document.getElementById('sb-tool-bold');
  var toolHl = document.getElementById('sb-tool-hl');
  var draftBold = false;
  var draftHl = false;
  var saving = false;
  var dirtyIds = {};

  function toFa(n) {
    return String(n).replace(/\d/g, function (d) {
      return '۰۱۲۳۴۵۶۷۸۹'[d];
    });
  }

  function setStatus(msg, isErr) {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.classList.toggle('is-err', !!isErr);
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  function post(action, payload) {
    var body = Object.assign({ action: action }, payload || {});
    var fd = new FormData();
    Object.keys(body).forEach(function (k) {
      if (body[k] === undefined || body[k] === null) return;
      fd.append(k, typeof body[k] === 'boolean' ? (body[k] ? '1' : '0') : String(body[k]));
    });
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
      body: fd
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function nl2br(s) {
    return esc(s).replace(/\n/g, '<br>');
  }

  function itemHtml(item) {
    var cls = 'staff-board-item';
    if (item.is_done) cls += ' is-done';
    if (item.is_bold) cls += ' is-bold';
    if (item.is_highlight) cls += ' is-highlight';
    var textInner = (canEdit && !item.is_done)
      ? '<div class="staff-board-text" contenteditable="true" role="textbox" spellcheck="true">' + esc(item.body) + '</div>'
      : '<div class="staff-board-text">' + nl2br(item.body) + '</div>';
    var actions = '';
    if (canEdit) {
      actions =
        '<div class="staff-board-actions">' +
          '<button type="button" class="staff-board-action" data-act="bold" title="پررنگ" aria-pressed="' + (item.is_bold ? 'true' : 'false') + '">ب</button>' +
          '<button type="button" class="staff-board-action is-hl" data-act="highlight" title="هایلایت" aria-pressed="' + (item.is_highlight ? 'true' : 'false') + '">هـ</button>' +
          '<button type="button" class="staff-board-action is-danger" data-act="delete" title="حذف">×</button>' +
        '</div>';
    }
    return (
      '<li class="' + cls + '" data-id="' + esc(item.id) + '" data-done="' + (item.is_done ? '1' : '0') + '" data-bold="' + (item.is_bold ? '1' : '0') + '" data-highlight="' + (item.is_highlight ? '1' : '0') + '">' +
        '<label class="staff-board-check"><input type="checkbox" ' + (item.is_done ? 'checked' : '') + (canEdit ? '' : ' disabled') + ' aria-label="انجام شد"></label>' +
        '<div class="staff-board-body">' + textInner +
          '<div class="staff-board-meta"><span>' + esc(item.created_name) + '</span><span>·</span><time>' + esc(item.created_label) + '</time></div>' +
        '</div>' + actions +
      '</li>'
    );
  }

  function updateCounts(c) {
    if (!c) return;
    var openEl = document.getElementById('staff-board-count-open');
    var doneEl = document.getElementById('staff-board-count-done');
    if (openEl) openEl.textContent = toFa(c.open || 0);
    if (doneEl) doneEl.textContent = toFa(c.done || 0);
    root.querySelectorAll('[data-count-for]').forEach(function (el) {
      var k = el.getAttribute('data-count-for');
      if (c[k] !== undefined) el.textContent = toFa(c[k]);
    });
  }

  function shouldShow(item) {
    if (filter === 'open') return !item.is_done;
    if (filter === 'done') return !!item.is_done;
    return true;
  }

  function upsertItem(item, prepend) {
    if (!list || !item || !item.id) return;
    var existing = list.querySelector('.staff-board-item[data-id="' + item.id + '"]');
    if (!shouldShow(item)) {
      if (existing) existing.remove();
      ensureEmpty();
      return;
    }
    var html = itemHtml(item);
    if (existing) {
      if (dirtyIds[item.id]) return;
      existing.outerHTML = html;
    } else {
      var empty = document.getElementById('staff-board-empty');
      if (empty) empty.remove();
      if (prepend) {
        list.insertAdjacentHTML('afterbegin', html);
      } else {
        list.insertAdjacentHTML('beforeend', html);
      }
    }
  }

  function ensureEmpty() {
    if (!list) return;
    if (list.querySelector('.staff-board-item')) return;
    if (!document.getElementById('staff-board-empty')) {
      list.innerHTML = '<li class="staff-board-empty muted" id="staff-board-empty">هنوز یادداشتی نیست. اولین نکته را بنویسید.</li>';
    }
  }

  function syncList() {
    var q = api + (api.indexOf('?') >= 0 ? '&' : '?') + 'filter=' + encodeURIComponent(filter);
    fetch(q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) return;
        updateCounts(j.counts);
        var seen = {};
        (j.items || []).forEach(function (item) {
          seen[item.id] = true;
          upsertItem(item, false);
        });
        list.querySelectorAll('.staff-board-item').forEach(function (li) {
          var id = li.getAttribute('data-id');
          if (id && !seen[id] && !dirtyIds[id]) li.remove();
        });
        ensureEmpty();
      })
      .catch(function () {});
  }

  if (toolBold) {
    toolBold.addEventListener('click', function () {
      draftBold = !draftBold;
      toolBold.setAttribute('aria-pressed', draftBold ? 'true' : 'false');
      toolBold.classList.toggle('is-on', draftBold);
    });
  }
  if (toolHl) {
    toolHl.addEventListener('click', function () {
      draftHl = !draftHl;
      toolHl.setAttribute('aria-pressed', draftHl ? 'true' : 'false');
      toolHl.classList.toggle('is-on', draftHl);
    });
  }

  function submitComposer(e) {
    if (e) e.preventDefault();
    if (!canEdit || !input || saving) return;
    var body = (input.value || '').trim();
    if (!body) {
      setStatus('متن را بنویسید.', true);
      return;
    }
    saving = true;
    setStatus('در حال ذخیره…');
    post('create', { body: body, is_bold: draftBold, is_highlight: draftHl })
      .then(function (res) {
        saving = false;
        if (!res.json || !res.json.ok) {
          setStatus((res.json && res.json.error) || 'ذخیره ناموفق بود.', true);
          return;
        }
        input.value = '';
        draftBold = false;
        draftHl = false;
        if (toolBold) { toolBold.setAttribute('aria-pressed', 'false'); toolBold.classList.remove('is-on'); }
        if (toolHl) { toolHl.setAttribute('aria-pressed', 'false'); toolHl.classList.remove('is-on'); }
        setStatus('ذخیره شد.');
        updateCounts(res.json.counts);
        upsertItem(res.json.item, true);
        input.focus();
      })
      .catch(function () {
        saving = false;
        setStatus('ارتباط قطع شد.', true);
      });
  }

  if (composer) composer.addEventListener('submit', submitComposer);
  if (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitComposer();
      }
    });
  }

  if (list) {
    list.addEventListener('change', function (e) {
      var t = e.target;
      if (!t || t.type !== 'checkbox') return;
      var li = t.closest('.staff-board-item');
      if (!li || !canEdit) return;
      var id = li.getAttribute('data-id');
      post('toggle', { id: id, is_done: t.checked })
        .then(function (res) {
          if (!res.json || !res.json.ok) {
            t.checked = !t.checked;
            setStatus((res.json && res.json.error) || 'به‌روزرسانی ناموفق.', true);
            return;
          }
          updateCounts(res.json.counts);
          upsertItem(res.json.item, true);
          setStatus(t.checked ? 'تیک زده شد.' : 'به باز برگشت.');
        })
        .catch(function () {
          t.checked = !t.checked;
          setStatus('ارتباط قطع شد.', true);
        });
    });

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-act]');
      if (!btn || !canEdit) return;
      var li = btn.closest('.staff-board-item');
      if (!li) return;
      var id = li.getAttribute('data-id');
      var act = btn.getAttribute('data-act');
      if (act === 'delete') {
        if (!confirm('این یادداشت حذف شود؟')) return;
        post('delete', { id: id })
          .then(function (res) {
            if (!res.json || !res.json.ok) {
              setStatus((res.json && res.json.error) || 'حذف ناموفق.', true);
              return;
            }
            li.remove();
            updateCounts(res.json.counts);
            ensureEmpty();
            setStatus('حذف شد.');
          });
        return;
      }
      if (act === 'bold' || act === 'highlight') {
        var payload = { id: id };
        if (act === 'bold') payload.is_bold = li.getAttribute('data-bold') !== '1';
        if (act === 'highlight') payload.is_highlight = li.getAttribute('data-highlight') !== '1';
        post('format', payload)
          .then(function (res) {
            if (!res.json || !res.json.ok) {
              setStatus((res.json && res.json.error) || 'فرمت ذخیره نشد.', true);
              return;
            }
            upsertItem(res.json.item, false);
          });
      }
    });

    var saveTimers = {};
    list.addEventListener('input', function (e) {
      var el = e.target;
      if (!el || !el.classList.contains('staff-board-text') || !el.isContentEditable) return;
      var li = el.closest('.staff-board-item');
      if (!li) return;
      var id = li.getAttribute('data-id');
      dirtyIds[id] = true;
      clearTimeout(saveTimers[id]);
      saveTimers[id] = setTimeout(function () {
        var body = (el.innerText || '').replace(/\u00a0/g, ' ').trim();
        if (!body) {
          setStatus('متن خالی نشود.', true);
          return;
        }
        post('update', { id: id, body: body })
          .then(function (res) {
            delete dirtyIds[id];
            if (!res.json || !res.json.ok) {
              setStatus((res.json && res.json.error) || 'ویرایش ذخیره نشد.', true);
              return;
            }
            setStatus('ویرایش ذخیره شد.');
          })
          .catch(function () {
            setStatus('ارتباط قطع شد.', true);
          });
      }, 600);
    });

    list.addEventListener('keydown', function (e) {
      var el = e.target;
      if (!el || !el.classList.contains('staff-board-text') || !el.isContentEditable) return;
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        el.blur();
      }
    });
  }

  if (pollMs > 0) {
    setInterval(function () {
      if (document.hidden) return;
      if (Object.keys(dirtyIds).length) return;
      syncList();
    }, pollMs);
  }
})();
</script>
JS;
    return $js;
}
