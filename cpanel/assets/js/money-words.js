(function (global) {
  var ones = ["", "یک", "دو", "سه", "چهار", "پنج", "شش", "هفت", "هشت", "نه"];
  var teens = ["ده", "یازده", "دوازده", "سیزده", "چهارده", "پانزده", "شانزده", "هفده", "هجده", "نوزده"];
  var tens = ["", "", "بیست", "سی", "چهل", "پنجاه", "شصت", "هفتاد", "هشتاد", "نود"];
  var hundreds = ["", "صد", "دویست", "سیصد", "چهارصد", "پانصد", "ششصد", "هفتصد", "هشتصد", "نهصد"];
  var scales = ["", "هزار", "میلیون", "میلیارد"];

  function digitsOnly(value) {
    return String(value || "")
      .replace(/[۰-۹]/g, function (d) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)); })
      .replace(/[٠-٩]/g, function (d) { return String("٠١٢٣٤٥٦٧٨٩".indexOf(d)); })
      .replace(/\D/g, "");
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
    if (!n) return "صفر تومان";
    var parts = [];
    var i = 0;
    while (n > 0 && i < scales.length) {
      var chunk = n % 1000;
      if (chunk) parts.unshift(three(chunk) + (scales[i] ? " " + scales[i] : ""));
      n = Math.floor(n / 1000);
      i++;
    }
    return parts.join(" و ") + " تومان";
  }

  function bindMoney(input) {
    if (!input || input.getAttribute("data-money-bound") === "1") return;
    input.setAttribute("data-money-bound", "1");
    var out = input.parentNode ? input.parentNode.querySelector("[data-money-words]") : null;
    if (!out) {
      out = document.createElement("p");
      out.className = "muted";
      out.setAttribute("data-money-words", "");
      out.style.cssText = "margin:.35rem 0 0;font-size:.85rem;line-height:1.7";
      input.insertAdjacentElement("afterend", out);
    }
    function paint() {
      var digits = digitsOnly(input.value);
      if (!digits) {
        input.value = "";
        out.textContent = "";
        return;
      }
      var grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      var next = faDigits(grouped);
      if (input.value !== next) input.value = next;
      out.textContent = words(parseInt(digits, 10));
    }
    input.addEventListener("input", paint);
    paint();
  }

  function init() {
    document.querySelectorAll("[data-money]").forEach(bindMoney);
  }

  global.manaBindMoney = bindMoney;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(window);
