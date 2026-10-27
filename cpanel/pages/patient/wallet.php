<?php
declare(strict_types=1);

$user = require_login();
require_once __DIR__ . '/../../includes/wallet.php';

ensure_wallet_schema($pdo);
$wallet = ensure_wallet($pdo, $user['id']);

$tx = $pdo->prepare('
  SELECT * FROM wallet_transactions
  WHERE wallet_id = ?
  ORDER BY created_at DESC
  LIMIT 30
');
$tx->execute([$wallet['id']]);
$transactions = $tx->fetchAll();

ob_start();
?>
<div class="stack">
  <h1>کیف پول</h1>
  <p class="muted">وقت که می‌گیرید و هنوز فیش تأیید نشده، مانده به اندازه حق ویزیت منفی می‌شود. با تأیید فیش، آن بدهی صفر می‌شود. اگر همان نوبتِ پرداخت‌شده برگزار نشود و کنسل شود، مبلغ ویزیت جداگانه به موجودی قابل استفاده برمی‌گردد.</p>

  <div class="panel" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
    <div>
      <div class="muted" style="font-size:.85rem">موجودی قابل استفاده</div>
      <div style="font-size:1.5rem;font-weight:700;color:<?= (int) $wallet['balance'] < 0 ? 'var(--danger)' : 'var(--primary)' ?>;margin-top:.25rem"><?= e(format_price((int)$wallet['balance'])) ?></div>
    </div>
    <div>
      <div class="muted" style="font-size:.85rem">در انتظار تسویه (امانی)</div>
      <div style="font-size:1.1rem;margin-top:.25rem"><?= e(format_price((int)$wallet['held_balance'])) ?></div>
      <p class="muted" style="font-size:.75rem;margin:.35rem 0 0">برای درمانگران: مبالغ کارگاه تا پایان برگزاری در امانی نگه داشته می‌شود.</p>
    </div>
  </div>

  <section class="panel">
    <h2 style="margin:0 0 1rem;font-size:1.05rem">آخرین تراکنش‌ها</h2>
    <?php if ($transactions): ?>
      <table class="table">
        <thead>
          <tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>مانده بعد از آن</th><th>توضیح</th></tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $t): ?>
            <?php
              $amount = (int) $t['amount'];
              $after = (int) ($t['balance_after'] ?? 0);
              $sign = $amount > 0 ? '+' : ($amount < 0 ? '−' : '');
            ?>
            <tr>
              <td><?= e(format_fa_datetime($t['created_at'])) ?></td>
              <td><?= e(wallet_kind_label($t['kind'])) ?></td>
              <td style="direction:ltr;text-align:right;color:<?= $amount >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                <?= e($sign . format_price(abs($amount))) ?>
              </td>
              <td style="color:<?= $after < 0 ? 'var(--danger)' : 'inherit' ?>"><?= e(format_price($after)) ?></td>
              <td class="muted" style="font-size:.85rem"><?= e((string)$t['description']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">تراکنشی ثبت نشده است.</p>
    <?php endif; ?>
  </section>

  <div class="panel muted" style="font-size:.85rem;line-height:1.8">
    <strong>روال بازگشت وجه جلسه:</strong>
    گرفتن نوبت، مانده را منفی می‌کند. تأیید فیش بانکی، همان بدهی را صفر می‌کند.
    اگر جلسه برگزار نشود و نوبت پرداخت‌شده کنسل شود، یک ردیف «بازگشت وجه» به موجودی قابل استفاده اضافه می‌شود:
    کنسلی منشی همیشه کل مبلغ است؛ کنسلی مراجع اگر حداقل ۲۴ ساعت مانده باشد کل مبلغ، و اگر کمتر باشد قابل استرداد نیست.
  </div>
</div>
<?php
$walletHtml = ob_get_clean();
$walletRole = (string) ($user['role'] ?? '');
if ($walletRole === 'ADMIN') {
    require_once __DIR__ . '/../../includes/admin_panel.php';
    render_admin_page('کیف پول', $walletHtml);
} elseif ($walletRole === 'SECRETARY') {
    require_once __DIR__ . '/../../includes/secretary_panel.php';
    render_secretary_page('کیف پول', $walletHtml);
} elseif ($walletRole === 'DOCTOR') {
    require_once __DIR__ . '/../../includes/doctor_panel.php';
    render_doctor_page('کیف پول', $walletHtml);
} elseif ($walletRole === 'ACCOUNTANT') {
    require_once __DIR__ . '/../../includes/accountant.php';
    render_accountant_page('کیف پول', $walletHtml);
} else {
    require_once __DIR__ . '/../../includes/patient_panel.php';
    render_patient_page('کیف پول', $walletHtml);
}
