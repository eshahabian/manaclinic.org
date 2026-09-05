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
    return String(value).replace(/[0-9]/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
  }

  function formatDuration(seconds) {
    seconds = Math.max(0, seconds | 0);
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    return faDigits(h) + " ساعت و " + faDigits(m) + " دقیقه";
  }

  function tickClock() {
    var box = document.getElementById("staff-clock");
    var el = document.getElementById("staff-clock-elapsed");
    if (!box || !el) return;
    var started = box.getAttribute("data-started");
    if (!started) return;
    var startTs = Date.parse(started.replace(" ", "T"));
    if (!startTs) return;
    el.textContent = formatDuration(Math.floor((Date.now() - startTs) / 1000));
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
