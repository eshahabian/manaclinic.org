      <label class="label" for="footer-request-kind-input">نوع درخواست</label>
      <div class="footer-consult contact-request-fields">
      <div class="search-select" id="footer-request-kind-pick">
        <input class="input search-select-input" id="footer-request-kind-input" type="text" readonly placeholder="انتخاب کنید" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="footer-request-kind-list" style="cursor:pointer">
        <ul class="search-select-list" id="footer-request-kind-list" hidden>
          <li class="search-select-option" role="option" data-value="consult">درخواست مشاوره</li>
          <li class="search-select-option" role="option" data-value="collab">درخواست همکاری</li>
        </ul>
      </div>
      <div id="footer-request-consult-pane">
          <form class="footer-consult" method="post" action="<?= e(url('/consult-request')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e((string) ($GLOBALS['path'] ?? '/')) ?>">
            <input class="footer-consult-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sr-only" for="footer-request-consult-name">نام و نام خانوادگی، الزامی</label>
            <input class="input" id="footer-request-consult-name" name="name" required maxlength="100" placeholder="نام و نام خانوادگی *" autocomplete="name">
            <label class="sr-only" for="footer-request-consult-phone">شماره تماس، الزامی</label>
            <input class="input input-rtl" id="footer-request-consult-phone" name="phone" required inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
            <label class="sr-only" for="footer-request-consult-message">توضیح درخواست، الزامی</label>
            <textarea class="input" id="footer-request-consult-message" name="message" required maxlength="1000" rows="4" placeholder="توضیح مختصر درباره درخواست شما *"></textarea>
            <button type="submit" class="btn btn-primary">ارسال درخواست مشاوره</button>
          </form>
      </div>
      <div id="footer-request-collab-pane" hidden>
          <form class="footer-consult" method="post" action="<?= e(url('/collaborate')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e((string) ($GLOBALS['path'] ?? '/')) ?>">
            <input class="footer-consult-hp" type="text" name="mana_hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sr-only" for="footer-request-collab-name">نام و نام خانوادگی، الزامی</label>
            <input class="input" id="footer-request-collab-name" name="name" required maxlength="120" placeholder="نام و نام خانوادگی *" autocomplete="name">
            <label class="sr-only" for="footer-request-collab-phone">شماره تماس، الزامی</label>
            <input class="input input-rtl" id="footer-request-collab-phone" name="phone" required inputmode="tel" maxlength="20" dir="rtl" placeholder="شماره تماس *" autocomplete="tel">
            <label class="sr-only" for="footer-request-collab-specialty">تخصص یا زمینه همکاری، الزامی</label>
            <input class="input" id="footer-request-collab-specialty" name="specialty" required maxlength="160" placeholder="تخصص یا زمینه همکاری *">
            <label class="sr-only" for="footer-request-collab-message">توضیح درخواست، الزامی</label>
            <textarea class="input" id="footer-request-collab-message" name="message" required maxlength="2000" rows="4" placeholder="توضیح سابقه، روزهای حضور یا نوع همکاری *"></textarea>
            <button type="submit" class="btn btn-primary">ارسال درخواست همکاری</button>
          </form>
      </div>
      </div>
<script>
(function () {
  var pick = document.getElementById("footer-request-kind-pick");
  var input = document.getElementById("footer-request-kind-input");
  var list = document.getElementById("footer-request-kind-list");
  var consult = document.getElementById("footer-request-consult-pane");
  var collab = document.getElementById("footer-request-collab-pane");
  if (!pick || !input || !list) return;
  function close() {
    list.hidden = true;
    input.setAttribute("aria-expanded", "false");
    pick.classList.remove("is-open");
  }
  function open() {
    list.hidden = false;
    input.setAttribute("aria-expanded", "true");
    pick.classList.add("is-open");
  }
  function choose(value, label) {
    input.value = label;
    if (consult) consult.hidden = value !== "consult";
    if (collab) collab.hidden = value !== "collab";
    list.querySelectorAll(".search-select-option").forEach(function (item) {
      item.classList.toggle("is-selected", item.getAttribute("data-value") === value);
    });
    close();
  }
  input.addEventListener("click", function () {
    if (list.hidden) open();
    else close();
  });
  input.addEventListener("keydown", function (e) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      if (list.hidden) open();
      else close();
    }
    if (e.key === "Escape") close();
  });
  list.querySelectorAll(".search-select-option").forEach(function (item) {
    item.addEventListener("mousedown", function (e) {
      e.preventDefault();
      choose(item.getAttribute("data-value") || "", item.textContent.trim());
    });
  });
  document.addEventListener("click", function (e) {
    if (!pick.contains(e.target)) close();
  });
  if (window.location.hash === "#collaborate-request") {
    choose("collab", "درخواست همکاری");
  }
})();
</script>
