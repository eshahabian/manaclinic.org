<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_panel.php';
require_once __DIR__ . '/../../includes/outreach.php';
require_sms_settings_operator();

ensure_outreach_schema($pdo);

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$selectedYmd = trim((string) ($_GET['date'] ?? $tomorrow));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedYmd)) {
    $selectedYmd = $tomorrow;
}
$maxYmd = date('Y-m-d', strtotime('+14 days'));
if ($selectedYmd < $today) {
    $selectedYmd = $today;
}
if ($selectedYmd > $maxYmd) {
    $selectedYmd = $maxYmd;
}

$doctors = sms_digest_doctor_choices($pdo);
$digests = sms_doctor_digests_for_date($pdo, $selectedYmd, null);
$digestById = [];
foreach ($digests as $pack) {
    $digestById[(string) $pack['doctor_id']] = $pack;
}

$jalaliView = '';
if (function_exists('gregorian_to_jalali')) {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $selectedYmd));
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);
    $jalaliView = $jy . '/' . sprintf('%02d', $jm) . '/' . sprintf('%02d', $jd);
}
$dayLabel = function_exists('format_fa_day_long')
    ? format_fa_day_long($selectedYmd . ' 12:00:00')
    : to_fa_digits($selectedYmd);

$recent = sms_recent($pdo, 'doctor_daily_digest', 15);
$digestBase = url('/admin/sms-doctor-digest');

$digestBaseJson = json_encode($digestBase, JSON_UNESCAPED_UNICODE);
$GLOBALS['pageHead'] = '<link rel="stylesheet" href="/assets/vendor/jalali/jalalidatepicker.min.css">';
$GLOBALS['pageScripts'] = '<script src="/assets/vendor/jalali/jalaali.min.js"></script>'
    . '<script src="/assets/vendor/jalali/jalalidatepicker.min.js"></script>'
    . '<script>(function(){'
    . 'var digestBase=' . $digestBaseJson . ';'
    . 'function faToEn(str){return String(str||"").replace(/[۰-۹]/g,function(d){return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d));});}'
    . 'function pad(n){return (n<10?"0":"")+n;}'
    . 'if(typeof jalaliDatepicker!=="undefined"){jalaliDatepicker.startWatch({selector:"[data-jdp]",time:false,hideAfterChange:true,showTodayBtn:true,showEmptyBtn:false,autoReadOnlyInput:true,persianDigits:true,zIndex:100000,container:"body"});}'
    . 'var view=document.getElementById("digest-date-view");'
    . 'function goToPickedDate(){if(!view||typeof jalaali==="undefined")return;var t=faToEn(view.value).replace(/-/g,"/").trim();var p=t.split("/");if(p.length!==3)return;var g=jalaali.toGregorian(parseInt(p[0],10),parseInt(p[1],10),parseInt(p[2],10));if(!g||!g.gy)return;window.location=digestBase+"?date="+encodeURIComponent(g.gy+"-"+pad(g.gm)+"-"+pad(g.gd));}'
    . 'if(view){view.addEventListener("jdp:change",goToPickedDate);view.addEventListener("change",goToPickedDate);}'
    . 'function setChecks(mode){document.querySelectorAll(\'input[name="doctor_ids[]"]\').forEach(function(cb){if(cb.disabled){cb.checked=false;return;}if(mode==="slots")cb.checked=cb.getAttribute("data-has-slots")==="1";else if(mode==="all")cb.checked=true;else cb.checked=false;});}'
    . 'var a=document.getElementById("digest-select-with-slots");var b=document.getElementById("digest-select-all");var c=document.getElementById("digest-select-none");'
    . 'if(a)a.addEventListener("click",function(e){e.preventDefault();setChecks("slots");});'
    . 'if(b)b.addEventListener("click",function(e){e.preventDefault();setChecks("all");});'
    . 'if(c)c.addEventListener("click",function(e){e.preventDefault();setChecks("none");});'
    . '})();</script>';

ob_start();
?>
<h1>ارسال دستی برنامه درمانگرها</h1>
<p class="muted" style="margin-top:.35rem;line-height:1.8">
  تاریخ روزهای آینده را انتخاب کنید، درمانگرها را تیک بزنید و خلاصهٔ ساعت‌های نوبت همان روز برایشان پیامک شود.
  ارسال خودکار صبحگاهی ساعت ۷ سر جایش می‌ماند. <?= sms_connection_ready($pdo) ? 'ارسال فعال است.' : 'تا تکمیل تنظیمات پیامک، ارسال خاموش است.' ?>
</p>

<div class="panel form-stack" style="margin-top:1rem">
  <label class="label" for="digest-date-view">تاریخ نوبت‌ها</label>
  <input class="input" type="text" id="digest-date-view" data-jdp data-jdp-only-date autocomplete="off" readonly
         value="<?= e(to_fa_digits($jalaliView)) ?>" style="cursor:pointer;max-width:16rem">
  <p class="muted" style="margin:0;font-size:.85rem">روز انتخاب‌شده: <?= e($dayLabel) ?></p>
  <p class="muted" style="margin:0;font-size:.8rem">
    میانبر:
    <a href="<?= e($digestBase . '?date=' . rawurlencode($today)) ?>">امروز</a>
    ·
    <a href="<?= e($digestBase . '?date=' . rawurlencode($tomorrow)) ?>">فردا</a>
  </p>
</div>

<form method="post" action="<?= e(url('/admin/sms-doctor-digest')) ?>" class="panel form-stack" style="margin-top:1rem"
      onsubmit="return confirm('برای درمانگرهای تیک‌خورده، برنامهٔ <?= e($dayLabel) ?> پیامک شود؟');">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="sms_doctor_digest_manual">
  <input type="hidden" name="date" value="<?= e($selectedYmd) ?>">

  <div style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;margin-bottom:.5rem">
    <a href="#" id="digest-select-with-slots">فقط کسانی که این روز نوبت دارند</a>
    <a href="#" id="digest-select-all">انتخاب همه</a>
    <a href="#" id="digest-select-none">لغو همه</a>
  </div>

  <?php if ($doctors === []): ?>
    <p class="muted">درمانگر فعالی نیست.</p>
  <?php else: ?>
    <div style="display:grid;gap:.55rem">
      <?php foreach ($doctors as $doc):
        $id = (string) ($doc['id'] ?? '');
        $pack = $digestById[$id] ?? null;
        $slots = (int) ($pack['slot_count'] ?? 0);
        $phoneOk = sms_iran_mobile((string) ($doc['phone'] ?? '')) !== null;
      ?>
        <label style="display:flex;gap:.6rem;align-items:flex-start;line-height:1.6;padding:.45rem .6rem;border:1px solid var(--line);border-radius:.65rem">
          <input type="checkbox" name="doctor_ids[]" value="<?= e($id) ?>"
                 data-has-slots="<?= $slots > 0 ? '1' : '0' ?>"
                 <?= $slots > 0 && $phoneOk ? 'checked' : '' ?>
                 <?= $phoneOk ? '' : 'disabled' ?>>
          <span>
            <strong><?= e((string) ($doc['name'] ?? 'درمانگر')) ?></strong>
            <?php if ($phoneOk): ?>
              <span class="muted" dir="ltr"> · <?= e((string) $doc['phone']) ?></span>
            <?php else: ?>
              <span style="color:#a33b3b"> · بدون موبایل معتبر</span>
            <?php endif; ?>
            <br>
            <?php if ($slots > 0): ?>
              <span class="muted"><?= e(to_fa_digits((string) $slots)) ?> نوبت:
                <?= e(implode('، ', array_map(static fn(string $t): string => to_fa_digits($t), $pack['times'] ?? []))) ?>
              </span>
            <?php else: ?>
              <span class="muted">این روز نوبت تأییدشده ندارد</span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <button type="submit" class="btn btn-primary" style="margin-top:1rem" <?= sms_connection_ready($pdo) ? '' : 'disabled' ?>>
    ارسال پیامک برنامه روز
  </button>
</form>

<?php if ($recent): ?>
<section class="panel" style="margin-top:1rem">
  <h2>آخرین خلاصه‌های ارسال‌شده</h2>
  <?php foreach ($recent as $row): ?>
    <article style="padding:.75rem 0;border-bottom:1px solid #ddd">
      <strong dir="ltr"><?= e((string) $row['phone']) ?></strong>
      · <?= e(sms_status_label((string) $row['status'])) ?>
      · <?= e(format_fa_datetime((string) $row['created_at'])) ?>
      <pre style="white-space:pre-wrap;margin:.4rem 0 0;font:inherit"><?= e((string) $row['body']) ?></pre>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php
sms_render_page('ارسال دستی برنامه درمانگرها', ob_get_clean());
