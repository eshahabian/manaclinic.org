<?php
declare(strict_types=1);

/**
 * فیلتر لیست نوبت‌ها با نام/موبایل/ایمیل و تاریخ میلادی Y-m-d
 *
 * @param list<array<string,mixed>> $list
 * @return list<array<string,mixed>>
 */
function appointment_list_apply_search(
    array $list,
    string $searchQ,
    string $searchDay
): array {
    $normName = static function (string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = str_replace(['ي', 'ك', 'ة', 'ۀ', '‌', 'ـ', 'أ', 'إ', 'آ', 'ؤ', 'ئ'], ['ی', 'ک', 'ه', 'ه', '', '', 'ا', 'ا', 'ا', 'و', 'ی'], $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    };
    $normDigits = static function (string $s): string {
        $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
        $s = strtr($s, $map);

        return preg_replace('/\D+/', '', $s) ?? '';
    };

    $qNorm = $searchQ !== '' ? $normName($searchQ) : '';
    $qTokens = $qNorm !== '' ? array_values(array_filter(explode(' ', $qNorm), static fn(string $t): bool => $t !== '')) : [];
    $qDigits = $searchQ !== '' ? $normDigits($searchQ) : '';
    if ($qNorm === '' && $qDigits === '' && $searchDay === '') {
        return $list;
    }

    return array_values(array_filter($list, static function (array $row) use ($qNorm, $qTokens, $qDigits, $searchDay, $normName, $normDigits): bool {
        if ($searchDay !== '') {
            $ymd = substr(str_replace('T', ' ', (string) ($row['starts_at'] ?? '')), 0, 10);
            if ($ymd !== $searchDay) {
                return false;
            }
        }
        if ($qNorm === '' && $qDigits === '') {
            return true;
        }
        $hay = $normName((string) ($row['patient_name'] ?? ''));
        $phone = $normDigits((string) ($row['phone'] ?? ''));
        $email = $normName((string) ($row['email'] ?? ''));
        $doctor = $normName((string) ($row['doctor_name'] ?? ''));
        $okName = false;
        if ($qTokens !== []) {
            $okName = true;
            foreach ($qTokens as $tok) {
                if ($tok === '' || (!str_contains($hay, $tok) && !str_contains($email, $tok) && !str_contains($doctor, $tok))) {
                    $okName = false;
                    break;
                }
            }
        } elseif ($qNorm !== '') {
            $okName = ($hay !== '' && str_contains($hay, $qNorm))
                || ($email !== '' && str_contains($email, $qNorm))
                || ($doctor !== '' && str_contains($doctor, $qNorm));
        }
        $okPhone = $qDigits !== '' && $phone !== '' && str_contains($phone, $qDigits);

        return $okName || $okPhone;
    }));
}

/** @param list<array<string,mixed>> $rows @return list<string> */
function appointment_search_name_choices(array $rows): array
{
    $names = [];
    foreach ($rows as $row) {
        foreach (['patient_name', 'doctor_name'] as $key) {
            $name = trim((string) ($row[$key] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
    }
    $list = array_values($names);
    sort($list, SORT_STRING);

    return $list;
}

/**
 * فرم جستجوی نام + تاریخ (شمسی نمایشی / میلادی مخفی)
 */
function appointment_search_form_html(
    string $actionUrl,
    string $tab,
    string $searchQ,
    string $searchDay,
    string $searchJalali,
    bool $filterActive,
    string $idSuffix,
    string $namePlaceholder = 'نام مراجعه‌کننده…',
    array $nameChoices = []
): string {
    $clearUrl = $actionUrl . (str_contains($actionUrl, '?') ? '&' : '?') . 'tab=' . rawurlencode($tab);
    ob_start();
    ?>
    <form class="appt-search-bar" method="get" action="<?= e($actionUrl) ?>" id="appt-search-<?= e($idSuffix) ?>">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="appt-search-field">
        <label class="label" for="appt_search_q_<?= e($idSuffix) ?>">جستجو با نام</label>
        <input
          class="input appt-search-name"
          type="search"
          id="appt_search_q_<?= e($idSuffix) ?>"
          name="q"
          value="<?= e($searchQ) ?>"
          placeholder="<?= e($namePlaceholder) ?>"
          autocomplete="off"
          data-names="<?= e(json_encode(array_values($nameChoices ?? []), JSON_UNESCAPED_UNICODE)) ?>"
        >
      </div>
      <div class="appt-search-field">
        <label class="label" for="appt_search_day_view_<?= e($idSuffix) ?>">جستجو با تاریخ</label>
        <input
          class="input"
          type="text"
          id="appt_search_day_view_<?= e($idSuffix) ?>"
          data-jdp
          data-jdp-only-date
          autocomplete="off"
          readonly
          placeholder="کلیک کنید تا تقویم باز شود"
          style="cursor:pointer"
          value="<?= e($searchJalali) ?>"
        >
        <input type="hidden" name="day" id="appt_search_day_<?= e($idSuffix) ?>" value="<?= e($searchDay) ?>">
      </div>
      <div class="appt-search-actions">
        <button class="btn btn-primary" type="submit">جستجو</button>
        <?php if ($filterActive): ?>
          <a class="btn btn-outline" href="<?= e($clearUrl) ?>">پاک کردن</a>
        <?php endif; ?>
      </div>
    </form>
    <?php
    return (string) ob_get_clean();
}

function appointment_search_datepicker_script(array $suffixes): string
{
    $json = json_encode(array_values($suffixes), JSON_UNESCAPED_UNICODE);
    return '<script src="https://cdn.jsdelivr.net/npm/jalaali-js@1.2.7/dist/jalaali.min.js"></script>'
        . '<script src="https://cdn.jsdelivr.net/npm/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>'
        . '<script>
(function(){
  var suffixes = ' . $json . ';
  function faToEn(str){ return String(str).replace(/[۰-۹]/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }); }
  function pad(n){ return (n < 10 ? "0" : "") + n; }
  function bindDay(viewId, hiddenId, formId){
    var view = document.getElementById(viewId);
    var hidden = document.getElementById(hiddenId);
    if (!view || !hidden || typeof jalaali === "undefined") return;
    function syncDay(){
      var t = faToEn(view.value).replace(/-/g, "/").trim();
      if (t === "") { hidden.value = ""; return; }
      var p = t.split("/");
      if (p.length !== 3) { hidden.value = ""; return; }
      var g = jalaali.toGregorian(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10));
      hidden.value = g.gy + "-" + pad(g.gm) + "-" + pad(g.gd);
    }
    var form = document.getElementById(formId);
    var initial = view.value;
    view.addEventListener("jdp:change", function(){
      syncDay();
      if (form && view.value !== initial) form.submit();
    });
    view.addEventListener("change", syncDay);
    syncDay();
    if (form) form.addEventListener("submit", function(){ syncDay(); });
  }
  suffixes.forEach(function(sfx){
    bindDay("appt_search_day_view_" + sfx, "appt_search_day_" + sfx, "appt-search-" + sfx);
  });
  function normName(s){
    return String(s || "").replace(/ي/g, "ی").replace(/ك/g, "ک").replace(/ة/g, "ه").replace(/\s+/g, " ").trim().toLowerCase();
  }
  document.querySelectorAll(".appt-search-name").forEach(function(input){
    var names = [];
    try { names = JSON.parse(input.getAttribute("data-names") || "[]"); } catch (e) { names = []; }
    if (!names.length) return;
    var field = input.parentNode;
    if (field) field.style.position = "relative";
    var list = document.createElement("ul");
    list.className = "appt-name-suggest";
    list.hidden = true;
    field.appendChild(list);
    function close(){ list.hidden = true; if (field) field.classList.remove("is-suggesting"); }
    function open(){
      var q = normName(input.value);
      list.innerHTML = "";
      if (!q) { close(); return; }
      var shown = 0;
      names.forEach(function(name){
        if (shown >= 8) return;
        if (normName(name).indexOf(q) === -1) return;
        var li = document.createElement("li");
        li.textContent = name;
        li.addEventListener("mousedown", function(ev){
          ev.preventDefault();
          input.value = name;
          close();
          if (input.form) input.form.submit();
        });
        list.appendChild(li);
        shown += 1;
      });
      list.hidden = shown === 0;
      if (field) field.classList.toggle("is-suggesting", shown > 0);
    }
    input.addEventListener("input", open);
    input.addEventListener("focus", open);
    input.addEventListener("blur", function(){ window.setTimeout(close, 150); });
  });
  if (typeof jalaliDatepicker !== "undefined" && !document.getElementById("sec-date-view")) {
    jalaliDatepicker.startWatch({
      selector: "[data-jdp]",
      time: false,
      hideAfterChange: true,
      showTodayBtn: true,
      showEmptyBtn: true,
      autoReadOnlyInput: true,
      persianDigits: true,
      zIndex: 100000,
      container: "body"
    });
  }
})();
</script>';
}
