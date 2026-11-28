(function (global) {
  var ones = ["", "یک", "دو", "سه", "چهار", "پنج", "شش", "هفت", "هشت", "نه"];
  var teens = ["ده", "یازده", "دوازده", "سیزده", "چهارده", "پانزده", "شانزده", "هفده", "هجده", "نوزده"];
  var tens = ["", "", "بیست", "سی", "چهل", "پنجاه", "شصت", "هفتاد", "هشتاد", "نود"];
  var hundreds = ["", "صد", "دویست", "سیصد", "چهارصد", "پانصد", "ششصد", "هفتصد", "هشتصد", "نهصد"];
  var scales = ["", "هزار", "میلیون", "میلیارد"];
  var refreshMoney = new WeakMap();

  function englishDigits(value) {
    return String(value || "")
      .replace(/[۰-۹]/g, function (d) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); });
  }

  function faDigits(value) {
    return String(value).replace(/[0-9]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[d]; });
  }

  function three(n) {
    var parts = [];
    var h = Math.floor(n / 100);
    var r = n % 100;
    if (h) parts.push(hundreds[h]);
    if (r >= 10 && r < 20) parts.push(teens[r - 10]);
    else {
      var t = Math.floor(r / 10);
      var o = r % 10;
      if (t) parts.push(tens[t]);
      if (o) parts.push(ones[o]);
    }
    return parts.join(" و ");
  }

  function words(n) {
    if (!n) return "صفر";
    var parts = [];
    var i = 0;
    while (n > 0 && i < scales.length) {
      var chunk = n % 1000;
      if (chunk) parts.unshift(three(chunk) + (scales[i] ? " " + scales[i] : ""));
      n = Math.floor(n / 1000);
      i++;
    }
    return parts.join(" و ");
  }

  function parseVisitAmount(value, foreign) {
    var text = englishDigits(value).trim().replace(/[,٬]/g, "");
    if (foreign) text = text.replace(/[٫،]/g, ".");
    if (!(foreign ? /^[0-9]+(?:\.[0-9]{0,2})?$/ : /^[0-9]+$/).test(text)) return null;
    var parts = text.split(".");
    return { whole: parts[0], fraction: parts[1] };
  }

  function bindMoney(input) {
    if (!input || input.getAttribute("data-money-bound") === "1") return;
    input.setAttribute("data-money-bound", "1");
    var visit = input.hasAttribute("data-visit-amount");
    var residence = visit && input.form ? input.form.elements.namedItem("fee_residence") : null;
    var out = input.parentNode ? input.parentNode.querySelector("[data-money-words]") : null;
    if (!out) {
      out = document.createElement("span");
      out.className = "muted";
      out.setAttribute("data-money-words", "");
      out.style.cssText = "display:block;margin:.35rem 0 0;font-size:.85rem;line-height:1.7";
      input.insertAdjacentElement("afterend", out);
    }
    function paint(event) {
      var currency = residence ? residence.value : "";
      var foreign = currency === "abroad" || currency === "abroad_gbp";
      var raw = input.value;
      if (foreign && event && raw.indexOf(".") === -1) {
        if (event.data === "," && raw.endsWith(",")) {
          raw = raw.slice(0, -1) + ".";
        } else if (event.inputType === "insertFromPaste") {
          raw = englishDigits(raw).replace(/,([0-9]{1,2})$/, ".$1");
        }
      }
      var amount = visit
        ? parseVisitAmount(raw, foreign)
        : { whole: englishDigits(raw).replace(/\D/g, "") };
      if (!amount || !amount.whole) {
        if (!visit) input.value = "";
        out.textContent = "";
        return;
      }
      var grouped = amount.whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      var next = faDigits(grouped + (amount.fraction === undefined ? "" : "." + amount.fraction));
      if (input.value !== next) input.value = next;
      var whole = Number(amount.whole);
      var fraction = Number((amount.fraction || "").padEnd(2, "0"));
      var unit = currency === "abroad_gbp" ? "پوند" : (foreign ? "دلار" : "تومان");
      var subunit = currency === "abroad_gbp" ? "پنس" : "سنت";
      out.textContent = whole < 1e12 ? words(whole) + " " + unit + (fraction ? " و " + words(fraction) + " " + subunit : "") : "";
    }
    input.addEventListener("input", paint);
    if (residence) residence.addEventListener("change", paint);
    refreshMoney.set(input, paint);
    paint();
  }

  function init() {
    document.querySelectorAll("[data-money]").forEach(bindMoney);
  }

  global.manaBindMoney = bindMoney;
  global.manaRefreshMoney = function (input) {
    var paint = refreshMoney.get(input);
    if (paint) paint();
    else bindMoney(input);
  };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window);
