<?php
declare(strict_types=1);

$pageTitle = 'تماس با ما';
$pageDescription = 'تماس با مانا کلینیک سعادت‌آباد: ۰۹۱۰۱۳۸۷۸۳۸ و ۰۲۱۲۲۰۶۵۷۷۴. آدرس مطب، درخواست مشاوره و درخواست همکاری درمانگران.';
$pageCanonical = url('/contact');
$pageKeywords = 'تماس مانا کلینیک, آدرس مانا کلینیک, روانشناس سعادت آباد, درخواست همکاری';
ob_start();
?>
<div class="container-page section contact-page">
  <h1>تماس با ما</h1>
  <p class="muted" style="margin-top:.5rem;line-height:1.9">
    برای پرسش درباره نوبت‌ها و خدمات از راه‌های زیر با ما در ارتباط باشید.
    برای مشاوره یا همکاری، بخش ثبت درخواست را باز کنید و نوع درخواست را انتخاب کنید.
  </p>

  <div class="contact-layout">
  <div class="panel stack">
    <div>
      <div class="label">شماره موبایل</div>
      <a href="tel:09101387838" style="color:var(--primary);font-weight:600" dir="ltr">۰۹۱۰ ۱۳۸ ۷۸۳۸</a>
    </div>
    <div>
      <div class="label">خط ثابت</div>
      <a href="tel:02122065774" style="color:var(--primary);font-weight:600" dir="ltr">۰۲۱ ۲۲۰۶ ۵۷۷۴</a>
    </div>
    <div>
      <div class="label">ایمیل</div>
      <a href="mailto:info@manaclinic.org" style="color:var(--primary);font-weight:600" dir="ltr">info@manaclinic.org</a>
    </div>
    <div>
      <div class="label">آدرس مطب</div>
      <p style="margin:0;line-height:1.9">
        سعادت‌آباد، خیابان ۳۱ شرقی (جندونی)، روبروی ساختمان پزشکان روزبه، پلاک ۴، واحد ۵، طبقه ۵
      </p>
    </div>
    <div>
      <div class="label">ساعات پشتیبانی</div>
      <p style="margin:0">همه روزه ۹ تا ۱۸</p>
    </div>
    <div>
      <div class="label">رزرو نوبت</div>
      <p style="margin:0">
        می‌توانید از بخش
        <a href="<?= e(url('/doctors')) ?>" style="color:var(--primary);font-weight:600">متخصصان</a>
        به‌صورت آنلاین نوبت بگیرید.
      </p>
    </div>
  </div>
  <div class="stack">
    <details class="panel consult-form-card contact-request" id="collaborate-request">
      <summary>ثبت درخواست</summary>
      <div class="contact-request-body">
        <p class="muted consult-form-lead" id="contact-request-lead">نوع درخواست را انتخاب کنید. نام، شماره تماس و توضیح برای مشاوره لازم است.</p>
        <div class="contact-kind" role="tablist" aria-label="نوع درخواست">
          <button type="button" class="btn btn-primary" data-request-kind="consult" aria-pressed="true">درخواست مشاوره</button>
          <button type="button" class="btn btn-outline" data-request-kind="collab" aria-pressed="false">درخواست همکاری</button>
        </div>
        <form class="footer-consult" id="contact-request-form" method="post" action="<?= e(url('/consult-request')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="/contact">
          <input class="footer-consult-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
          <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <label class="sr-only" for="contact-request-name">نام و نام خانوادگی، الزامی</label>
          <input class="input" id="contact-request-name" name="name" required aria-required="true" maxlength="120" placeholder="نام و نام خانوادگی *" autocomplete="name">
          <label class="sr-only" for="contact-request-phone">شماره تماس، الزامی</label>
          <input class="input input-rtl" id="contact-request-phone" name="phone" required aria-required="true" inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
          <div id="contact-specialty-wrap" hidden>
            <label class="sr-only" for="contact-request-specialty">تخصص یا زمینه همکاری، الزامی</label>
            <input class="input" id="contact-request-specialty" name="specialty" maxlength="160" placeholder="تخصص یا زمینه همکاری *" disabled>
          </div>
          <label class="sr-only" for="contact-request-message">توضیح درخواست، الزامی</label>
          <textarea class="input" id="contact-request-message" name="message" required aria-required="true" maxlength="1000" rows="4" placeholder="توضیح مختصر درباره درخواست شما *"></textarea>
          <button type="submit" class="btn btn-primary" id="contact-request-submit">ارسال درخواست مشاوره</button>
        </form>
      </div>
    </details>
  </div>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById("collaborate-request");
  var form = document.getElementById("contact-request-form");
  var lead = document.getElementById("contact-request-lead");
  var specialtyWrap = document.getElementById("contact-specialty-wrap");
  var specialty = document.getElementById("contact-request-specialty");
  var message = document.getElementById("contact-request-message");
  var submit = document.getElementById("contact-request-submit");
  if (!root || !form) return;
  var actions = {
    consult: <?= json_encode(url('/consult-request')) ?>,
    collab: <?= json_encode(url('/collaborate')) ?>
  };
  function applyKind(kind) {
    var collab = kind === "collab";
    form.action = actions[collab ? "collab" : "consult"];
    if (specialtyWrap) specialtyWrap.hidden = !collab;
    if (specialty) {
      specialty.disabled = !collab;
      specialty.required = collab;
    }
    if (message) {
      message.maxLength = collab ? 2000 : 1000;
      message.placeholder = collab
        ? "توضیح سابقه، روزهای حضور یا نوع همکاری *"
        : "توضیح مختصر درباره درخواست شما *";
    }
    if (lead) {
      lead.textContent = collab
        ? "برای همکاری، نام، شماره تماس، تخصص و توضیح را بنویسید."
        : "برای مشاوره، نام، شماره تماس و توضیح درخواست لازم است.";
    }
    if (submit) submit.textContent = collab ? "ارسال درخواست همکاری" : "ارسال درخواست مشاوره";
    root.querySelectorAll("[data-request-kind]").forEach(function (btn) {
      var on = btn.getAttribute("data-request-kind") === (collab ? "collab" : "consult");
      btn.classList.toggle("btn-primary", on);
      btn.classList.toggle("btn-outline", !on);
      btn.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }
  root.querySelectorAll("[data-request-kind]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      root.open = true;
      applyKind(btn.getAttribute("data-request-kind") || "consult");
    });
  });
  if (window.location.hash === "#collaborate-request") {
    root.open = true;
    applyKind("collab");
  }
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../includes/layout.php';
