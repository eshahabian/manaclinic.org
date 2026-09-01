<?php
declare(strict_types=1);

function booking_terms_accepted(): bool
{
    return post('accept_terms') === '1' || isset($_POST['accept_terms']);
}

function booking_terms_not_accepted_error(): void
{
    http_response_code(400);
    echo json_encode(['error' => 'لطفاً شرایط رزرو و پرداخت را مطالعه و تأیید کنید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function booking_terms_body_html(): string
{
    ob_start();
    ?>
    <h2 style="margin:0 0 1rem;font-size:1.15rem">شرایط رزرو جلسات مشاوره و روان‌درمانی</h2>

    <h3>لغو یا تغییر زمان جلسه</h3>
    <ul>
      <li>بیش از ۲۴ ساعت مانده به زمان جلسه: کل مبلغ جلسه به کیف پول شما در سایت بازگردانده می‌شود.</li>
      <li>از ۳ تا ۲۴ ساعت مانده به زمان جلسه: ۵۰٪ مبلغ جلسه به کیف پول شما بازگردانده می‌شود.</li>
      <li>کمتر از ۳ ساعت مانده به زمان جلسه: مبلغ جلسه قابل استرداد نیست و کل هزینه از اعتبار جلسه کسر می‌شود.</li>
      <li>در صورتی که جلسه از طرف درمانگر لغو شود کل مبلغ جلسه به کیف پول شما بازگردانده می‌شود و یا با هماهنگی شما و درمانگر، زمان دیگری برای برگزاری جلسه تعیین خواهد شد.</li>
      <li>در صورت تغییر زمان جلسه به دلیل لغو از سوی درمانگر، هزینه اضافی از مراجعه‌کننده دریافت نخواهد شد.</li>
    </ul>
    <p class="terms-note">در صورت تمایل، برای تغییر زمان جلسه بهتر است درخواست خود را در همان بازه زمانی اعلام کنید تا امکان هماهنگی با درمانگر وجود داشته باشد.</p>

    <h3>حضور و نظم در جلسات</h3>
    <ul>
      <li>لطفاً در زمان تعیین‌شده در جلسه حضور داشته باشید.</li>
      <li>زمان جلسه از ساعت مقرر محاسبه می‌شود؛ بنابراین دیرکرد مراجعه‌کننده موجب افزایش زمان جلسه نخواهد شد.</li>
      <li>در صورت عدم حضور بدون اطلاع قبلی، جلسه مطابق قوانین لغو جلسه محاسبه خواهد شد.</li>
      <li>اگر درمانگر با تأخیر وارد جلسه شود، زمان از دست‌رفته با هماهنگی درمانگر جبران خواهد شد.</li>
    </ul>

    <h3>آمادگی برای جلسات آنلاین</h3>
    <ul>
      <li>پیش از شروع جلسه آنلاین از اتصال مناسب اینترنت و شارژ کافی دستگاه اطمینان حاصل کنید.</li>
      <li>در صورت امکان از هندزفری یا هدست استفاده کنید.</li>
      <li>در محیطی آرام و خصوصی قرار بگیرید.</li>
      <li>تلفن همراه و اعلان‌های مزاحم را روی حالت بی‌صدا قرار دهید.</li>
      <li>از انجام فعالیت‌های هم‌زمان مانند رانندگی، کار با تلفن همراه یا انجام امور روزمره در طول جلسه خودداری کنید.</li>
      <li>جلسه را در محیطی برگزار کنید که امکان گفت‌وگوی آزاد و محرمانه وجود داشته باشد.</li>
    </ul>

    <h3>مدیریت زمان جلسات</h3>
    <ul>
      <li>مدت استاندارد هر جلسه ۵۰ دقیقه است.</li>
      <li>جلسه در زمان تعیین‌شده آغاز و در پایان ۵۰ دقیقه به پایان می‌رسد.</li>
      <li>تأخیر مراجعه‌کننده از زمان جلسه کسر خواهد شد و امکان تمدید جلسه به دلیل دیر رسیدن وجود ندارد.</li>
      <li>در صورتی که با توافق درمانگر، جلسه بیش از زمان استاندارد ادامه پیدا کند، زمان اضافه می‌تواند به‌عنوان جلسه یا زمان مازاد محاسبه و مشمول هزینه جداگانه باشد.</li>
      <li>در صورت نیاز به جلسه طولانی‌تر، بهتر است این موضوع پیش از جلسه با درمانگر هماهنگ شود.</li>
    </ul>

    <h3>نظم و استاندارد جلسات درمان</h3>
    <ul>
      <li>جلسات روان‌درمانی به‌طور معمول هفتگی برگزار می‌شوند؛ مگر اینکه با توجه به شرایط مراجعه‌کننده و نظر درمانگر، فاصله زمانی دیگری تعیین شود.</li>
      <li>استمرار و نظم در جلسات، بخش مهمی از روند درمان است و تغییر مکرر زمان جلسات می‌تواند روند درمان را تحت تأثیر قرار دهد.</li>
      <li>تعداد و فاصله جلسات بر اساس نیازهای درمانی و با توافق مراجعه‌کننده و درمانگر تعیین می‌شود.</li>
      <li>لطفاً موضوعات مهم مرتبط با روند درمان، تغییرات قابل توجه شرایط زندگی یا مصرف داروهای مرتبط را با درمانگر در میان بگذارید.</li>
    </ul>

    <h3>محرمانگی و حریم خصوصی</h3>
    <ul>
      <li>محتوای جلسات و اطلاعات مطرح‌شده در فرآیند درمان، مطابق اصول حرفه‌ای و ضوابط محرمانگی حفظ می‌شود.</li>
      <li>مراجعه‌کننده نیز موظف است برای حفظ محرمانگی جلسه آنلاین، محیط مناسبی برای برگزاری جلسه فراهم کند.</li>
      <li>ضبط صدا، تصویر یا محتوای جلسه توسط هر یک از طرفین، بدون اطلاع و رضایت طرف مقابل مجاز نیست. در صورت نیاز، با هماهنگی و توافق طرفین بلامانع است.</li>
    </ul>

    <p>تعهد شما به رعایت این موارد، به حفظ نظم جلسات، افزایش کیفیت ارتباط درمانی و فراهم شدن شرایط مناسب‌تر برای پیشرفت روند درمان کمک می‌کند.</p>
    <p><strong>از همراهی و اعتماد شما سپاسگزاریم.</strong></p>
    <?php
    return ob_get_clean();
}

function booking_terms_acceptance_html(string $checkboxId = 'terms-accept'): string
{
    return '
    <div class="terms-accept">
      <label class="terms-accept-label">
        <input type="checkbox" id="' . e($checkboxId) . '" class="terms-checkbox">
        <span>
          <button type="button" class="terms-open-link" data-terms-open>شرایط رزرو و پرداخت</button>
          را مطالعه کردم و می‌پذیرم.
        </span>
      </label>
    </div>';
}

function booking_terms_modal_html(string $modalId = 'terms-modal'): string
{
    return '
    <div id="' . e($modalId) . '" class="terms-modal" aria-hidden="true" role="dialog" aria-labelledby="terms-modal-title">
      <div class="terms-modal-backdrop" data-terms-close tabindex="-1"></div>
      <div class="terms-modal-panel">
        <div class="terms-modal-header">
          <h2 id="terms-modal-title" style="margin:0;font-size:1.05rem">قوانین و شرایط</h2>
          <button type="button" class="terms-modal-close" data-terms-close aria-label="بستن">×</button>
        </div>
        <div class="terms-modal-body">' . booking_terms_body_html() . '</div>
        <div class="terms-modal-footer">
          <button type="button" class="btn btn-primary btn-sm" data-terms-close>متوجه شدم</button>
        </div>
      </div>
    </div>';
}

function booking_terms_styles(): string
{
    return '
<style>
.terms-accept { margin: .75rem 0; }
.terms-accept-label {
  display: flex;
  gap: .55rem;
  align-items: flex-start;
  font-size: .88rem;
  line-height: 1.7;
  cursor: pointer;
}
.terms-checkbox { margin-top: .35rem; flex-shrink: 0; width: 1rem; height: 1rem; }
.terms-open-link {
  background: none;
  border: none;
  padding: 0;
  color: var(--primary);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
.terms-modal {
  position: fixed;
  inset: 0;
  z-index: 200000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.terms-modal.is-open { display: flex; }
.terms-modal-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 20, .55);
  backdrop-filter: blur(2px);
}
.terms-modal-panel {
  position: relative;
  z-index: 1;
  width: min(640px, 100%);
  max-height: min(85vh, 720px);
  background: #fff;
  border-radius: 1rem;
  box-shadow: 0 24px 60px rgba(0,0,0,.25);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.terms-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.15rem;
  border-bottom: 1px solid var(--line);
}
.terms-modal-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  color: var(--muted);
  padding: .15rem .35rem;
}
.terms-modal-body {
  padding: 1rem 1.15rem;
  overflow: auto;
  font-size: .88rem;
  line-height: 1.85;
}
.terms-modal-body h3 {
  margin: 1.1rem 0 .45rem;
  font-size: .95rem;
  color: var(--primary);
}
.terms-modal-body h3:first-child { margin-top: 0; }
.terms-modal-body ul {
  margin: 0;
  padding-right: 1.2rem;
}
.terms-modal-body li { margin: .35rem 0; }
.terms-modal-body .terms-note {
  font-size: .85rem;
  color: var(--muted);
  border-right: 3px solid var(--line);
  padding-right: .75rem;
  margin: .5rem 0 0;
}
.terms-modal-footer {
  padding: .85rem 1.15rem;
  border-top: 1px solid var(--line);
  text-align: left;
}
button:disabled.btn-primary { opacity: .55; cursor: not-allowed; }
</style>';
}

function booking_terms_script(string $checkboxId = 'terms-accept', string $buttonSelector = ''): string
{
    $checkboxIdJs = json_encode($checkboxId, JSON_UNESCAPED_UNICODE);
    $buttonSelectorJs = json_encode($buttonSelector, JSON_UNESCAPED_UNICODE);
    return "
<script>
(function(){
  var checkboxId = {$checkboxIdJs};
  var buttonSelector = {$buttonSelectorJs};
  var cb = document.getElementById(checkboxId);
  var modal = document.getElementById('terms-modal');
  if (!cb) return;

  function buttons(){
    if (buttonSelector) return Array.prototype.slice.call(document.querySelectorAll(buttonSelector));
    var b = document.getElementById('book-submit');
    return b ? [b] : [];
  }

  function syncButtons(){
    buttons().forEach(function(btn){
      if (!btn) return;
      btn.disabled = !cb.checked;
    });
  }

  cb.addEventListener('change', syncButtons);
  syncButtons();

  function openModal(e){
    if (e) e.preventDefault();
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(){
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-terms-open]').forEach(function(el){
    el.addEventListener('click', openModal);
  });
  if (modal) {
    modal.querySelectorAll('[data-terms-close]').forEach(function(el){
      el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
  }
})();
</script>";
}
