(function () {
  var wrap = document.querySelector("[data-vc-search]");
  if (!wrap) return;
  var input = wrap.querySelector("[data-vc-search-input]");
  var drop = wrap.querySelector("[data-vc-search-drop]");
  var signalUrl = wrap.getAttribute("data-signal-url") || "/video-signal";
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
  var timer = null;
  var picked = {};

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
          '<a class="btn btn-primary btn-sm" href="' + item.videoUrl + '">تصویری</a>' +
          '<a class="btn btn-outline btn-sm" href="' + item.audioUrl + '">صوتی</a>' +
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
    fetch(signalUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": token,
        "X-Requested-With": "XMLHttpRequest"
      },
      body: JSON.stringify({ action: "contacts", q: q || "" })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) render(data.items || []);
      })
      .catch(function () {});
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
