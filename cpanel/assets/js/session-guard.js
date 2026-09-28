(function () {
  var body = document.body;
  if (!body || body.getAttribute("data-session-guard") !== "1") return;

  var idleMs = 10 * 60 * 1000;
  var pingUrl = body.getAttribute("data-session-ping") || "";
  var logoutUrl = body.getAttribute("data-logout") || "/logout";
  var last = Date.now();
  var leaving = false;

  function bump() {
    last = Date.now();
  }

  ["click", "keydown", "scroll", "touchstart", "mousemove"].forEach(function (evt) {
    window.addEventListener(evt, bump, { passive: true });
  });

  function inLiveCall() {
    if (document.querySelector(".video-call-stage.is-live")) return true;
    var nodes = document.querySelectorAll("video, audio");
    var i;
    for (i = 0; i < nodes.length; i++) {
      if (nodes[i].srcObject) return true;
    }
    return false;
  }

  function withFlag(flag) {
    return logoutUrl + (logoutUrl.indexOf("?") === -1 ? "?" : "&") + flag;
  }

  function goIdle() {
    if (leaving) return;
    leaving = true;
    window.location.href = withFlag("idle=1");
  }

  function goReplaced() {
    if (leaving) return;
    leaving = true;
    window.location.href = withFlag("replaced=1");
  }

  function ping() {
    if (leaving) return;
    if (inLiveCall()) bump();
    if (!pingUrl) {
      if (Date.now() - last >= idleMs) goIdle();
      return;
    }
    var active = Date.now() - last < 60000 ? "1" : "0";
    var headers = { "Content-Type": "application/x-www-form-urlencoded" };
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (csrf && csrf.getAttribute("content")) headers["X-CSRF-Token"] = csrf.getAttribute("content");
    fetch(pingUrl, {
      method: "POST",
      headers: headers,
      body: "active=" + active,
      credentials: "same-origin"
    })
      .then(function (r) {
        return r.json().catch(function () { return null; });
      })
      .then(function (data) {
        if (!data) return;
        if (data.replaced) goReplaced();
        else if (data.expired) goIdle();
      })
      .catch(function () {});
  }

  setTimeout(ping, 4000);
  setInterval(ping, 20000);
})();
