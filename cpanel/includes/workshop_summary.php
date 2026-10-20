<?php
declare(strict_types=1);
require_once __DIR__.'/workshop_presenters.php';
$summaryPresenters=workshop_presenter_names($pdo,(string)$workshop['id']);
?>
<h3>خلاصه کارگاه</h3>
<div class="workshop-summary-grid">
  <div><strong>تعداد اعضا</strong><p><?= e(to_fa_digits((string)(int)($workshop['enrolled_count']??0))) ?> نفر<?= !empty($workshop['capacity'])?' از ظرفیت '.e(to_fa_digits((string)$workshop['capacity'])).' نفر':'' ?></p></div>
  <div><strong>اجراکنندگان</strong><p><?= e($summaryPresenters?implode('، ',$summaryPresenters):'هنوز انتخاب نشده‌اند') ?></p></div>
  <div><strong>زمان برگزاری</strong><p><?= e(format_workshop_datetime_fa((string)$workshop['starts_at'])) ?> تا <?= e(format_workshop_datetime_fa((string)$workshop['ends_at'])) ?></p><p><?= e(workshop_session_interval_label((string)($workshop['session_interval']??'WEEKLY'))) ?></p></div>
  <div><strong>نوع و محل برگزاری</strong><p><?= e(workshop_type_label((string)$workshop['type'])) ?></p><p><?= e((string)($workshop['location']??'')) ?></p></div>
  <div><strong>وضعیت</strong><p><?= $archived?'آرشیو':($workshop['is_published']?'منتشر شده':'پیش‌نویس') ?> · <?= !empty($workshop['enrollment_open'])?'ثبت‌نام باز':'ثبت‌نام بسته' ?></p></div>
  <div><strong>جلسات ثبت‌شده</strong><p><?= e(to_fa_digits((string)count($staffSessions))) ?> جلسه</p></div>
</div>
