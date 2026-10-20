<?php
declare(strict_types=1);
require_once __DIR__.'/workshop_presenters.php';
?>
      <section class="home-workshop-banners" id="home-workshop-banners" aria-labelledby="home-workshop-heading" style="margin-top:0">
        <div class="section-head">
          <div>
            <h2 id="home-workshop-heading">دوره‌ها و کارگاه‌ها</h2>
            <p class="muted">روی کارت بزنید تا توضیح و ثبت‌نام را ببینید</p>
          </div>
        </div>
        <?php if (empty($workshopArchive)): ?>
          <div class="therapists-all-link">
            <a class="articles-all-link" href="<?= e(url('/workshops')) ?>">مشاهده همه کارگاه‌ها ←</a>
          </div>
        <?php endif; ?>
        <?php if ($workshopBanners): ?>
        <div class="home-workshop-track">
          <?php foreach ($workshopBanners as $promo): ?>
            <?php
              $phase = workshop_promo_phase($promo);
              $when = workshop_is_offline((string) ($promo['type'] ?? ''))
                  ? 'دوره آفلاین'
                  : (format_workshop_datetime_fa((string) ($promo['starts_at'] ?? '')) . ' تا ' . format_workshop_datetime_fa((string) ($promo['ends_at'] ?? '')));
              $presenterNames=workshop_presenter_names($pdo,(string)$promo['id']);
              $presenterLabel=$presenterNames?'اجراکنندگان: '.implode('، ',$presenterNames):(string)($promo['doctor_name']??'');
              $meta = trim(workshop_type_label((string) ($promo['type'] ?? '')) . ' · ' . $presenterLabel . ' · ' . $when);
              $canApply = workshop_can_enroll($promo) && $phase !== 'done' && (string) ($promo['status'] ?? '') === 'PUBLISHED';
              $bannerSrc = function_exists('workshop_promo_image_src')
                  ? workshop_promo_image_src($promo)
                  : '';
              $blurb = function_exists('workshop_promo_blurb')
                  ? workshop_promo_blurb($promo)
                  : '';
              $notes = trim((string) ($promo['description'] ?? ''));
              $typeKey = workshop_tab_from_type((string) ($promo['type'] ?? ''));
            ?>
            <button
              type="button"
              class="home-workshop-banner"
              data-workshop-banner
              data-title="<?= e((string) ($promo['title'] ?? '')) ?>"
              data-description="<?= e($blurb) ?>"
              data-notes="<?= e($notes) ?>"
              data-meta="<?= e($meta) ?>"
              data-image="<?= e($bannerSrc) ?>"
              data-apply="<?= e(workshop_apply_url((string) ($promo['id'] ?? ''))) ?>"
              data-can-apply="<?= $canApply ? '1' : '0' ?>"
            >
              <img src="<?= e($bannerSrc) ?>" alt="<?= e((string) ($promo['title'] ?? 'بنر دوره')) ?>">
              <span class="home-workshop-caption">
                <strong><?= e((string) ($promo['title'] ?? 'دوره')) ?></strong>
              </span>
              <span class="home-workshop-badges">
                <span class="home-workshop-badge home-workshop-badge--type-<?= e($typeKey) ?>"><?= e(workshop_type_label((string) ($promo['type'] ?? ''))) ?></span>
                <span class="home-workshop-badge home-workshop-badge--<?= e($phase) ?>"><?= e(workshop_promo_phase_label($phase)) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
          <p class="muted home-workshop-empty">به‌زودی دوره‌های جدید اینجا اعلام می‌شود.</p>
        <?php endif; ?>
      </section>

      <div id="home-workshop-modal" class="home-workshop-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="home-workshop-modal-title">
        <div class="home-workshop-modal-backdrop" data-workshop-close tabindex="-1"></div>
        <div class="home-workshop-modal-panel">
          <div class="home-workshop-modal-header">
            <h2 id="home-workshop-modal-title"></h2>
            <button type="button" class="home-workshop-modal-close" data-workshop-close aria-label="بستن">×</button>
          </div>
          <div class="home-workshop-modal-body" id="home-workshop-modal-body"></div>
          <div class="home-workshop-modal-actions">
            <a class="btn btn-primary" id="home-workshop-modal-cta" href="#">ثبت‌نام در دوره</a>
            <button type="button" class="btn btn-outline" data-workshop-close>بستن</button>
          </div>
        </div>
      </div>
