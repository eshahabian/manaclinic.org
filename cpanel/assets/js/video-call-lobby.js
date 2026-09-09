(function () {
  var wrap = document.querySelector("[data-vc-search]");
  var callRoot = document.querySelector("[data-video-call]");
  var signalUrl = "/video-signal";
  if (wrap && wrap.getAttribute("data-signal-url")) signalUrl = wrap.getAttribute("data-signal-url");
  else if (callRoot && callRoot.getAttribute("data-signal-url")) signalUrl = callRoot.getAttribute("data-signal-url");
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var timer = null;
  var picked = {};
  var input = wrap ? wrap.querySelector("[data-vc-search-input]") : null;
  var drop = wrap ? wrap.querySelector("[data-vc-search-drop]") : null;

  function post(body) {
    return fetch(signalUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": token,
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  var opening = false;
  function openInTile(opts) {
    if (opening) return;
    opening = true;
    post(Object.assign({ action: "open" }, opts)).then(function (data) {
      if (!data || !data.ok) return;
      data.autoStart = true;
      if (window.ManaVideoCall && window.ManaVideoCall.start) window.ManaVideoCall.start(data);
    }).catch(function () {}).then(function () {
      opening = false;
    });
  }

  document.addEventListener("click", function (ev) {
    var callBtn = ev.target.closest("[data-vc-call]");
    if (callBtn) {
      ev.preventDefault();
      openInTile({
        peer: callBtn.getAttribute("data-peer") || "",
        media: callBtn.getAttribute("data-media") || "video"
      });
      return;
    }
    var roomLink = ev.target.closest("[data-vc-room]");
    if (roomLink) {
      ev.preventDefault();
      openInTile({ room: roomLink.getAttribute("data-vc-room") || "" });
    }
  });

  if (!wrap) return;

  function rememberChecks() {
    if (!drop) return;
    drop.querySelectorAll(".vc-pick").forEach(function (el) {
      if (el.checked) picked[el.value] = true;
      else delete picked[el.value];
    });
  }

  function rowHtml(item) {
    var letter = item.letter || (item.name ? item.name.charAt(0) : "م");
    var checked = picked[item.id] ? " checked" : "";
    var peer = item.peer || item.id;
    return (
      '<div class="vc-person" data-user-id="' + item.id + '">' +
        '<label class="vc-pick-wrap" title="انتخاب برای گروه">' +
          '<input class="vc-pick" type="checkbox" form="vc-group-form" name="members[]" value="' + item.id + '"' + checked + ">" +
        "</label>" +
        '<span class="vc-avatar vc-avatar-md">' +
          '<span class="vc-avatar-fallback">' + letter + "</span>" +
          '<span class="vc-dot' + (item.online ? " is-online" : " is-offline") + '"></span>' +
        "</span>" +
        '<span class="vc-person-meta"><strong></strong><span class="muted">' + (item.online ? "آنلاین" : "آفلاین") + " · مراجعه‌کننده</span></span>" +
        '<span class="vc-person-calls">' +
          '<button type="button" class="btn btn-primary btn-sm" data-vc-call data-peer="' + peer + '" data-media="video">تصویری</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-vc-call data-peer="' + peer + '" data-media="audio">صوتی</button>' +
        "</span>" +
      "</div>"
    );
  }

  function render(items) {
    rememberChecks();
    if (!drop) return;
    if (!items || !items.length) {
      drop.innerHTML = '<li class="vc-search-empty muted">مراجعه‌کننده‌ای یافت نشد.</li>';
      return;
    }
    drop.innerHTML = items.map(function (item) {
      var li = document.createElement("li");
      li.innerHTML = rowHtml(item);
      var strong = li.querySelector("strong");
      if (strong) strong.textContent = item.name || "مراجعه‌کننده";
      return li.outerHTML;
    }).join("");
  }

  function search(q) {
    post({ action: "contacts", q: q || "" }).then(function (data) {
      if (data && data.ok) render(data.items || []);
    }).catch(function () {});
  }

  if (input) {
    input.addEventListener("focus", function () {
      wrap.classList.add("is-open");
    });
    input.addEventListener("input", function () {
      wrap.classList.add("is-open");
      clearTimeout(timer);
      timer = setTimeout(function () { search(input.value); }, 220);
    });
  }
  document.addEventListener("click", function (ev) {
    if (!wrap.contains(ev.target)) wrap.classList.remove("is-open");
  });
  var form = document.getElementById("vc-group-form");
  if (form) {
    form.addEventListener("submit", function () {
      rememberChecks();
      Object.keys(picked).forEach(function (id) {
        if (form.querySelector('input[name="members[]"][value="' + id + '"]')) return;
        var h = document.createElement("input");
        h.type = "hidden";
        h.name = "members[]";
        h.value = id;
        form.appendChild(h);
      });
    });
  }
})();
