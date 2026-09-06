(function () {
  var body = document.body;
  if (!body || body.getAttribute("data-secretary-desk") !== "1") return;

  var idleMs = 10 * 60 * 1000;
  var heartbeatUrl = body.getAttribute("data-heartbeat") || "";
  var logoutUrl = body.getAttribute("data-logout") || "/logout";
  var last = Date.now();
  var ticking = false;

  function bump() {
    last = Date.now();
  }

  ["click", "keydown", "scroll", "touchstart", "mousemove"].forEach(function (evt) {
    window.addEventListener(evt, bump, { passive: true });
  });

  function pad(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function faDigits(value) {
    return String(value)
      .replace(/[0-9]/g, function (d) {
        return "۰۱۲۳۴۵۶۷۸۹"[d];
      })
      .replace(/[٠-٩]/g, function (d) {
        return "۰۱۲۳۴۵۶۷۸۹"["٠١٢٣٤٥٦٧٨٩".indexOf(d)];
      });
  }

  function keepLatinDigits(el) {
    if (!el || !el.matches) return true;
    if (el.matches("[data-latin-digits], [lang='en'], [type='password'], [type='hidden'], [type='file'], [type='email']")) return true;
    var name = String(el.name || el.id || "");
    return /username|password|email|name_en|surname|sec-date$|sec-time$/.test(name);
  }

  document.addEventListener("input", function (e) {
    var el = e.target;
    if (!el || el.value == null || keepLatinDigits(el)) return;
    if (!/[0-9٠-٩]/.test(el.value)) return;
    var start = el.selectionStart;
    var end = el.selectionEnd;
    var next = faDigits(el.value);
    if (next === el.value) return;
    el.value = next;
    if (typeof start === "number" && el.setSelectionRange) {
      try { el.setSelectionRange(start, end); } catch (err) {}
    }
  }, true);

  function formatDuration(seconds) {
    seconds = Math.max(0, seconds | 0);
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    return faDigits(h) + " ساعت و " + faDigits(m) + " دقیقه";
  }

  function parseHm(value, fallbackHour, fallbackMin) {
    var p = String(value || "").split(":");
    var h = parseInt(p[0], 10);
    var m = parseInt(p[1], 10);
    return {
      h: isNaN(h) ? fallbackHour : h,
      m: isNaN(m) ? fallbackMin : m
    };
  }

  function splitSeconds(startTs, endTs, startHm, endHm) {
    var regular = 0;
    var cursor = startTs;
    while (cursor < endTs) {
      var day = new Date(cursor);
      var y = day.getFullYear();
      var mo = day.getMonth();
      var d = day.getDate();
      var nextMidnight = new Date(y, mo, d + 1).getTime();
      var segEnd = Math.min(endTs, nextMidnight);
      var rStart = new Date(y, mo, d, startHm.h, startHm.m, 0).getTime();
      var rEnd = new Date(y, mo, d, endHm.h, endHm.m, 0).getTime();
      var overlap = Math.min(segEnd, rEnd) - Math.max(cursor, rStart);
      if (overlap > 0) regular += overlap;
      cursor = segEnd;
    }
    var total = Math.max(0, endTs - startTs);
    regular = Math.min(total, regular);
    return { total: total, regular: regular, overtime: Math.max(0, total - regular) };
  }

  function tickClock() {
    var box = document.getElementById("staff-clock");
    var el = document.getElementById("staff-clock-elapsed");
    var splitEl = document.getElementById("staff-clock-split");
    if (!box || !el) return;
    var started = box.getAttribute("data-started");
    if (!started) return;
    var startTs = Date.parse(started.replace(" ", "T"));
    if (!startTs) return;
    var now = Date.now();
    el.textContent = formatDuration(Math.floor((now - startTs) / 1000));
    if (splitEl) {
      var startHm = parseHm(box.getAttribute("data-regular-start"), 9, 0);
      var endHm = parseHm(box.getAttribute("data-regular-end"), 20, 0);
      var part = splitSeconds(startTs, now, startHm, endHm);
      splitEl.textContent = "عادی: " + formatDuration(Math.floor(part.regular / 1000)) + " · اضافه‌کار: " + formatDuration(Math.floor(part.overtime / 1000));
    }
  }

  function goIdleLogout() {
    if (ticking) return;
    ticking = true;
    window.location.href = logoutUrl + (logoutUrl.indexOf("?") === -1 ? "?" : "&") + "idle=1";
  }

  setInterval(tickClock, 1000);
  tickClock();

  setInterval(function () {
    if (Date.now() - last >= idleMs) {
      goIdleLogout();
      return;
    }
    if (!heartbeatUrl) return;
    var active = Date.now() - last < 60000 ? "1" : "0";
    var bodyData = "active=" + active;
    fetch(heartbeatUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: bodyData,
      credentials: "same-origin",
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.expired) {
          goIdleLogout();
        }
      })
      .catch(function () {});
  }, 30000);
})();
