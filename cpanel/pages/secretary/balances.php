<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/wallet.php';

$embedded = !empty($balanceEmbedded);
if (!$embedded) {
    require_once __DIR__ . '/../../includes/secretary_panel.php';
    require_login(['SECRETARY']);
}
$balanceBase = $embedded ? (string) ($balanceBase ?? '/app/balances') : '/secretary/balances';
$tab = (($_GET['tab'] ?? '') === 'creditors') ? 'creditors' : 'debtors';
$rows = [];
try {
    $rows = wallet_party_rows($pdo, $tab === 'creditors' ? 'credit' : 'debt');
} catch (Throwable $e) {
    $rows = [];
}

ob_start();
?>
<h1>بدهکاران و بستانکاران</h1>
<p class="muted">مانده از حق ویزیت همان درمانگر است. منفی یعنی هنوز فیش تأیید نشده. مثبت یعنی مبلغی، مثلاً از کنسلی کلینیک، در کیف پول مانده است.</p>
<div class="binder-tabs" role="tablist" aria-label="بدهکاران و بستانکاران" style="margin-top:1rem">
  <a class="binder-tab<?= $tab === 'debtors' ? ' is-active' : '' ?>" href="<?= e(url($balanceBase)) ?>">بدهکاران</a>
  <a class="binder-tab<?= $tab === 'creditors' ? ' is-active' : '' ?>" href="<?= e(url($balanceBase . '?tab=creditors')) ?>">بستانکاران</a>
</div>
<?php if (!$rows): ?>
  <p class="muted" style="margin-top:1rem"><?= $tab === 'creditors' ? 'بستانکاری ثبت نشده است.' : 'بدهکاری ثبت نشده است.' ?></p>
<?php else: ?>
  <div class="stack" style="margin-top:1rem">
    <?php foreach ($rows as $row): ?>
      <?php $balance = (int) ($row['balance'] ?? 0); ?>
      <article class="panel" style="margin:0">
        <strong><?= e(trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : (string) ($row['username'] ?? '')) ?></strong>
        <p class="muted" style="margin:.35rem 0 0;line-height:1.7">
          <?php if (trim((string) ($row['phone'] ?? '')) !== ''): ?>
            <?= e((string) $row['phone']) ?> ·
          <?php endif; ?>
          درمانگر <?= trim((string) ($row['doctor_name'] ?? '')) !== '' ? e((string) $row['doctor_name']) : 'انتخاب نشده' ?>
        </p>
        <p style="margin:.45rem 0 0;font-weight:800;color:<?= $balance < 0 ? '#a33b3b' : '#1a7a45' ?>">
          <?= e(format_price(abs($balance))) ?>
          <?= $balance < 0 ? 'بدهکار' : 'بستانکار' ?>
        </p>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$html = ob_get_clean();
if ($embedded) {
    $balanceHtml = $html;
    return;
}
render_secretary_page('بدهکاران و بستانکاران', $html);
