(function () {
  var app = document.querySelector("[data-vc-app]");
  var callRoot = document.querySelector("[data-video-call]");
  var signalUrl = "/video-signal";
  if (app && app.getAttribute("data-signal-url")) signalUrl = app.getAttribute("data-signal-url");
  else if (callRoot && callRoot.getAttribute("data-signal-url")) signalUrl = callRoot.getAttribute("data-signal-url");
  var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || "";

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
      if (!data || !data.ok) {
        if (data && data.error) window.alert(data.error);
        return;
      }
      data.autoStart = true;
      if (window.ManaVideoCall && window.ManaVideoCall.start) window.ManaVideoCall.start(data);
    }).catch(function () {}).then(function () {
      opening = false;
    });
  }

  document.addEventListener("click", function (ev) {
    if (ev.target.closest("[data-vc-delete-room]")) return;
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

  if (!app) return;

  var composer = app.querySelector("[data-vc-composer]");
  var overlay = app.querySelector("[data-vc-overlay]");
  var overlaySource = app.querySelector("[data-vc-overlay-source]");
  var overlayPickedEl = app.querySelector("[data-vc-overlay-picked]");
  var pickedEl = app.querySelector("[data-vc-picked]");
  var dropHint = app.querySelector("[data-vc-drop-hint]");
  var titleEl = app.querySelector("[data-vc-composer-title]");
  var groupTitle = app.querySelector("[data-vc-group-title]");
  var groupWorkshop = app.querySelector("[data-vc-group-workshop]");
  var videoBtn = app.querySelector("[data-vc-video]");
  var audioBtn = app.querySelector("[data-vc-audio]");
  var saveBtn = app.querySelector("[data-vc-save-open]");
  var searchInput = app.querySelector("[data-vc-search-input]");
  var searchWrap = app.querySelector("[data-vc-search]");
  var searchDrop = app.querySelector("[data-vc-search-drop]");
  var tab = "home";
  var members = [];
  var overlayPicked = {};
  var overlayHighlight = { source: "", picked: "" };
  var savedRoomKey = "";
  var timer = null;
  var searchSeq = 0;
  var suggestItems = [];
  var suggestIndex = -1;

  function esc(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/"/g, "&quot;");
  }

  function parseSelect(el) {
    if (!el) return null;
    var raw = el.getAttribute("data-vc-select") || "";
    try {
      var item = JSON.parse(raw);
      if (item && item.id) return item;
    } catch (e) {}
    return null;
  }

  function catalog() {
    var out = [];
    app.querySelectorAll("[data-vc-home-list] [data-vc-select]").forEach(function (el) {
      var item = parseSelect(el);
      if (item) out.push(item);
    });
    return out;
  }

  function personHtml(item, cls) {
    var letter = item.letter || (item.name ? String(item.name).charAt(0) : "م");
    var payload = esc(JSON.stringify({
      id: item.id,
      name: item.name,
      online: !!item.online,
      role: item.role || "PATIENT",
      videoUrl: item.videoUrl || "",
      audioUrl: item.audioUrl || "",
      letter: letter,
      peer: item.peer || item.id
    }));
    return (
      '<div class="vc-person' + (cls ? " " + cls : "") + '" data-user-id="' + esc(item.id) + '" data-id="' + esc(item.id) + '" data-vc-select="' + payload + '">' +
        '<span class="vc-avatar vc-avatar-sm">' +
          '<span class="vc-avatar-fallback">' + esc(letter) + "</span>" +
          '<span class="vc-dot' + (item.online ? " is-online" : " is-offline") + '"></span>' +
        "</span>" +
        '<span class="vc-person-meta"><strong>' + esc(item.name || "مخاطب") + "</strong>" +
        '<span class="muted">' + (item.online ? "آنلاین" : "آفلاین") + (item.role === "DOCTOR" ? " · درمانگر" : " · مراجعه‌کننده") + "</span></span>" +
      "</div>"
    );
  }

  function fetchContacts(q, limit, roles) {
    return post({
      action: "contacts",
      q: String(q || ""),
      limit: limit || 8,
      roles: roles || "PATIENT"
    }).then(function (data) {
      return (data && data.ok && data.items) ? data.items : [];
    });
  }

  function closeSuggest() {
    suggestItems = [];
    suggestIndex = -1;
    if (searchWrap) searchWrap.classList.remove("is-open");
    if (searchInput) searchInput.setAttribute("aria-expanded", "false");
    if (searchDrop) searchDrop.innerHTML = "";
  }

  function renderSuggest(items, q) {
    suggestItems = items || [];
    suggestIndex = suggestItems.length ? 0 : -1;
    if (!searchDrop || !searchWrap) return;
    if (!String(q || "").trim()) {
      closeSuggest();
      return;
    }
    if (!suggestItems.length) {
      searchDrop.innerHTML = '<li class="muted vc-search-empty">کسی با این نام پیدا نشد.</li>';
    } else {
      searchDrop.innerHTML = suggestItems.map(function (item, i) {
        return "<li>" + personHtml(item, i === suggestIndex ? "is-on" : "") + "</li>";
      }).join("");
    }
    searchWrap.classList.add("is-open");
    if (searchInput) searchInput.setAttribute("aria-expanded", "true");
  }

  function highlightSuggest(i) {
    if (!searchDrop) return;
    var rows = searchDrop.querySelectorAll(".vc-person");
    if (!rows.length) return;
    suggestIndex = (i + rows.length) % rows.length;
    rows.forEach(function (el, idx) {
      el.classList.toggle("is-on", idx === suggestIndex);
    });
    if (rows[suggestIndex] && rows[suggestIndex].scrollIntoView) {
      rows[suggestIndex].scrollIntoView({ block: "nearest" });
    }
  }

  function pickContact(item) {
    if (!item || !item.id) return;
    closeSuggest();
    if (searchInput) searchInput.value = item.name || "";
    if (overlay && !overlay.hidden) {
      overlayPicked[item.id] = item;
      overlayHighlight.picked = item.id;
      overlayHighlight.source = "";
      var seen = false;
      overlayCatalog.forEach(function (row) { if (row.id === item.id) seen = true; });
      if (!seen) overlayCatalog.unshift(item);
      renderOverlayLists(overlayCatalog);
      return;
    }
    members = [item];
    savedRoomKey = "";
    if (groupTitle) groupTitle.value = "";
    if (groupWorkshop) groupWorkshop.value = "";
    renderPicked();
  }

  function runSearch(q) {
    q = String(q || "").trim();
    var seq = ++searchSeq;
    if (!q) {
      closeSuggest();
      return;
    }
    fetchContacts(q, 8, "PATIENT").then(function (items) {
      if (seq !== searchSeq) return;
      renderSuggest(items, q);
    }).catch(function () {
      if (seq !== searchSeq) return;
      renderSuggest([], q);
    });
  }

  function renderPicked() {
    if (!pickedEl) return;
    pickedEl.innerHTML = members.map(function (item) {
      return "<li>" + personHtml(item) + "</li>";
    }).join("");
    if (dropHint) dropHint.hidden = members.length > 0;
    syncActions();
  }

  function syncActions() {
    var n = members.length;
    var groupish = n > 1 || !!(groupWorkshop && groupWorkshop.value);
    if (videoBtn) {
      videoBtn.disabled = n < 1;
      videoBtn.textContent = "تماس تصویری";
    }
    if (audioBtn) {
      audioBtn.disabled = n < 1;
      audioBtn.textContent = n > 1 ? "تماس گروهی" : "تماس";
    }
    if (saveBtn) saveBtn.hidden = n < 1 || tab === "calls";
    if (composer) {
      composer.classList.toggle("is-groups", tab === "groups");
      composer.classList.toggle("is-calls", tab === "calls");
    }
    if (titleEl && tab === "home") {
      titleEl.textContent = n === 1 && members[0].name ? members[0].name : "خانه";
    }
    if (titleEl && tab === "groups") {
      titleEl.textContent = (groupTitle && groupTitle.value.trim()) || "گروه";
    }
    void groupish;
  }

  function setTab(name, opts) {
    opts = opts || {};
    tab = name;
    app.querySelectorAll("[data-vc-tab]").forEach(function (btn) {
      btn.classList.toggle("is-active", btn.getAttribute("data-vc-tab") === name);
    });
    app.querySelectorAll("[data-vc-pane]").forEach(function (pane) {
      var on = pane.getAttribute("data-vc-pane") === name;
      pane.hidden = !on;
      pane.classList.toggle("is-active", on);
    });
    if (name === "groups") {
      if (!opts.keepMembers) {
        members = [];
        savedRoomKey = "";
        if (groupTitle) groupTitle.value = "";
        if (groupWorkshop) groupWorkshop.value = "";
      }
      if (titleEl) titleEl.textContent = (groupTitle && groupTitle.value.trim()) || "گروه";
      if (dropHint) {
        dropHint.hidden = false;
        dropHint.textContent = "برای ساخت گروه روی + بزنید و افراد را انتخاب کنید.";
      }
    } else if (name === "calls") {
      if (titleEl) titleEl.textContent = "تماس‌ها";
      if (dropHint) {
        dropHint.hidden = false;
        dropHint.textContent = "یک جلسه ذخیره‌شده را از فهرست باز کنید.";
      }
    } else {
      if (titleEl) titleEl.textContent = members.length === 1 && members[0].name ? members[0].name : "خانه";
      if (dropHint) {
        dropHint.hidden = members.length > 0;
        dropHint.textContent = "مخاطب را از فهرست انتخاب کنید، یا برای گروه روی آیکون گروه بزنید و + را بزنید.";
      }
    }
    if (composer) composer.hidden = false;
    renderPicked();
  }

  function renderOverlayLists(items) {
    if (!overlaySource || !overlayPickedEl) return;
    var sourceHtml = [];
    var pickedHtml = [];
    (items || []).forEach(function (item) {
      if (!item || !item.id) return;
      if (overlayPicked[item.id]) {
        pickedHtml.push("<li>" + personHtml(item, item.id === overlayHighlight.picked ? "is-on" : "") + "</li>");
      } else {
        sourceHtml.push("<li>" + personHtml(item, item.id === overlayHighlight.source ? "is-on" : "") + "</li>");
      }
    });
    overlaySource.innerHTML = sourceHtml.join("") || '<li class="muted vc-empty">مخاطبی نیست.</li>';
    overlayPickedEl.innerHTML = pickedHtml.join("") || '<li class="muted vc-empty">هنوز کسی اضافه نشده.</li>';
  }

  var overlayCatalog = [];
  var overlaySearchTimer = null;
  var overlaySearchEl = app.querySelector("[data-vc-overlay-search]");
  function loadOverlayPeople(q) {
    q = String(q || "").trim();
    fetchContacts(q, q ? 12 : 4, "ALL").then(function (items) {
      overlayCatalog = items;
      renderOverlayLists(overlayCatalog);
    }).catch(function () {
      overlayCatalog = catalog().slice(0, 4);
      renderOverlayLists(overlayCatalog);
    });
  }
  function openOverlay() {
    if (!overlay) return;
    overlayPicked = {};
    members.forEach(function (m) { overlayPicked[m.id] = m; });
    overlayHighlight = { source: "", picked: "" };
    overlayCatalog = catalog().slice(0, 4);
    renderOverlayLists(overlayCatalog);
    overlay.hidden = false;
    if (overlaySearchEl) overlaySearchEl.value = "";
    loadOverlayPeople("");
  }

  function closeOverlay() {
    if (overlay) overlay.hidden = true;
  }

  function rememberSavedRoom(data) {
    savedRoomKey = (data && data.room) || "";
    if (!savedRoomKey) return;
    var title = (data && data.title) || (groupTitle && groupTitle.value.trim()) || "گروه";
    ["groups", "calls"].forEach(function (paneName) {
      var pane = app.querySelector('[data-vc-pane="' + paneName + '"]');
      if (!pane) return;
      if (pane.querySelector('[data-vc-room="' + savedRoomKey + '"]')) return;
      var empty = pane.querySelector(".vc-empty");
      if (empty) empty.remove();
      var ul = pane.querySelector(".vc-people");
      if (!ul) {
        ul = document.createElement("ul");
        ul.className = "vc-people";
        pane.appendChild(ul);
      }
      var li = document.createElement("li");
      var kindLabel = paneName === "calls" && data.kind === "workshop" ? "کارگاه" : "گروه ذخیره‌شده";
      li.innerHTML =
        '<div class="vc-person vc-room-row">' +
          '<a class="vc-person-meta" href="#" data-vc-room="' + esc(savedRoomKey) + '">' +
            "<strong>" + esc(title) + "</strong>" +
            '<span class="muted">' + kindLabel + "</span></a>" +
          (data.kind === "workshop" ? "" : '<button type="button" class="vc-room-del" data-vc-delete-room="' + esc(savedRoomKey) + '" title="حذف گروه">حذف</button>') +
        "</div>";
      ul.insertBefore(li, ul.firstChild);
    });
  }

  function overlayItemById(id) {
    var found = null;
    overlayCatalog.forEach(function (item) {
      if (item.id === id) found = item;
    });
    members.forEach(function (item) {
      if (item.id === id) found = item;
    });
    return found;
  }

  function applyOverlayToComposer() {
    members = Object.keys(overlayPicked).map(function (id) {
      return overlayPicked[id] || overlayItemById(id);
    }).filter(Boolean);
    savedRoomKey = "";
    closeOverlay();
    if (tab === "home" && members.length > 1) setTab("groups", { keepMembers: true });
    else renderPicked();
  }

  function startCall(media) {
    if (!members.length || opening) return;
    var ids = members.map(function (m) { return m.id; });
    var workshop = groupWorkshop ? String(groupWorkshop.value || "") : "";
    var title = groupTitle ? groupTitle.value.trim() : "";
    var one = members.length === 1 && !workshop;
    opening = true;
    var req;
    if (savedRoomKey) {
      req = post({ action: "open", room: savedRoomKey, media: media });
    } else if (one) {
      req = post({ action: "open", peer: ids[0], media: media });
    } else {
      req = post({
        action: "create_group",
        title: title,
        members: ids,
        workshop: workshop,
        media: media
      });
    }
    req.then(function (data) {
      if (!data || !data.ok) {
        window.alert((data && data.error) || "تماس شروع نشد.");
        return;
      }
      if (data.room) {
        savedRoomKey = data.room;
        if (data.kind === "group" || data.kind === "workshop") rememberSavedRoom(data);
      }
      data.autoStart = true;
      if (window.ManaVideoCall && window.ManaVideoCall.start) window.ManaVideoCall.start(data);
    }).catch(function () {
      window.alert("تماس شروع نشد.");
    }).then(function () {
      opening = false;
    });
  }

  function saveGroup() {
    if (!members.length) {
      openOverlay();
      return;
    }
    var title = groupTitle ? groupTitle.value.trim() : "";
    if (!title) {
      openOverlay();
      if (groupTitle) groupTitle.focus();
      return;
    }
    if (savedRoomKey) {
      window.location.reload();
      return;
    }
    opening = true;
    post({
      action: "create_group",
      title: title,
      members: members.map(function (m) { return m.id; }),
      workshop: groupWorkshop ? groupWorkshop.value : ""
    }).then(function (data) {
      if (!data || !data.ok) {
        window.alert((data && data.error) || "گروه ذخیره نشد.");
        return;
      }
      savedRoomKey = data.room || "";
      window.location.reload();
    }).catch(function () {
      window.alert("گروه ذخیره نشد.");
    }).then(function () {
      opening = false;
    });
  }

  app.querySelectorAll("[data-vc-tab]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      setTab(btn.getAttribute("data-vc-tab") || "home");
    });
  });

  app.addEventListener("click", function (ev) {
    var suggestPerson = ev.target.closest("[data-vc-search-drop] [data-vc-select]");
    if (suggestPerson) {
      ev.preventDefault();
      pickContact(parseSelect(suggestPerson));
      return;
    }
    var person = ev.target.closest("[data-vc-home-list] [data-vc-select]");
    if (person) {
      ev.preventDefault();
      var item = parseSelect(person);
      if (!item) return;
      members = [item];
      savedRoomKey = "";
      if (groupTitle) groupTitle.value = "";
      if (groupWorkshop) groupWorkshop.value = "";
      renderPicked();
      return;
    }
    var plus = ev.target.closest("[data-vc-plus]");
    if (plus) {
      ev.preventDefault();
      openOverlay();
      return;
    }
    if (ev.target.closest("[data-vc-save-open]")) {
      ev.preventDefault();
      saveGroup();
      return;
    }
    if (ev.target.closest("[data-vc-video]")) {
      ev.preventDefault();
      startCall("video");
      return;
    }
    if (ev.target.closest("[data-vc-audio]")) {
      ev.preventDefault();
      startCall("audio");
      return;
    }
    if (ev.target.closest("[data-vc-overlay-cancel]")) {
      ev.preventDefault();
      closeOverlay();
      return;
    }
    if (ev.target.closest("[data-vc-overlay-add]")) {
      ev.preventDefault();
      applyOverlayToComposer();
      var title = groupTitle ? groupTitle.value.trim() : "";
      var workshop = groupWorkshop ? String(groupWorkshop.value || "") : "";
      if (members.length && (title || workshop)) {
        post({
          action: "create_group",
          title: title,
          members: members.map(function (m) { return m.id; }),
          workshop: workshop
        }).then(function (data) {
          if (data && data.ok && data.room) rememberSavedRoom(data);
        }).catch(function () {});
      }
      return;
    }
    if (ev.target.closest("[data-vc-overlay-select]")) {
      ev.preventDefault();
      if (overlayHighlight.source && overlayItemById(overlayHighlight.source)) {
        overlayPicked[overlayHighlight.source] = overlayItemById(overlayHighlight.source);
        overlayHighlight.picked = overlayHighlight.source;
        overlayHighlight.source = "";
        renderOverlayLists(overlayCatalog);
      }
      return;
    }
    if (ev.target.closest("[data-vc-overlay-remove]")) {
      ev.preventDefault();
      if (overlayHighlight.picked) {
        delete overlayPicked[overlayHighlight.picked];
        overlayHighlight.source = overlayHighlight.picked;
        overlayHighlight.picked = "";
        renderOverlayLists(overlayCatalog);
      }
      return;
    }
    var srcPerson = ev.target.closest("[data-vc-overlay-source] .vc-person[data-id]");
    if (srcPerson) {
      overlayHighlight.source = srcPerson.getAttribute("data-id") || "";
      overlayHighlight.picked = "";
      renderOverlayLists(overlayCatalog);
      return;
    }
    var pkPerson = ev.target.closest("[data-vc-overlay-picked] .vc-person[data-id]");
    if (pkPerson) {
      overlayHighlight.picked = pkPerson.getAttribute("data-id") || "";
      overlayHighlight.source = "";
      renderOverlayLists(overlayCatalog);
      return;
    }
    var del = ev.target.closest("[data-vc-delete-room]");
    if (del) {
      ev.preventDefault();
      var room = del.getAttribute("data-vc-delete-room") || "";
      if (!room) return;
      if (!window.confirm("این گروه حذف شود؟ این کار قابل بازگشت نیست.")) return;
      post({ action: "delete_group", room: room }).then(function (data) {
        if (!data || !data.ok) {
          window.alert((data && data.error) || "حذف انجام نشد.");
          return;
        }
        var row = del.closest("li");
        if (row) row.remove();
        if (savedRoomKey === room) savedRoomKey = "";
      }).catch(function () {
        window.alert("حذف انجام نشد.");
      });
    }
  });

  if (overlay) {
    overlay.addEventListener("click", function (ev) {
      if (ev.target === overlay) closeOverlay();
    });
    overlay.addEventListener("dblclick", function (ev) {
      var src = ev.target.closest("[data-vc-overlay-source] .vc-person[data-id]");
      if (src) {
        var sid = src.getAttribute("data-id") || "";
        if (sid && overlayItemById(sid)) {
          overlayPicked[sid] = overlayItemById(sid);
          overlayHighlight.picked = sid;
          overlayHighlight.source = "";
          renderOverlayLists(overlayCatalog);
        }
        return;
      }
      var pk = ev.target.closest("[data-vc-overlay-picked] .vc-person[data-id]");
      if (pk) {
        var pid = pk.getAttribute("data-id") || "";
        if (pid) {
          delete overlayPicked[pid];
          overlayHighlight.source = pid;
          overlayHighlight.picked = "";
          renderOverlayLists(overlayCatalog);
        }
      }
    });
  }
  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Escape") {
      if (searchWrap && searchWrap.classList.contains("is-open")) {
        closeSuggest();
        return;
      }
      if (overlay && !overlay.hidden) closeOverlay();
      return;
    }
    if (!searchWrap || !searchWrap.classList.contains("is-open")) return;
    if (ev.key === "ArrowDown") {
      ev.preventDefault();
      highlightSuggest(suggestIndex + 1);
    } else if (ev.key === "ArrowUp") {
      ev.preventDefault();
      highlightSuggest(suggestIndex - 1);
    } else if (ev.key === "Enter" && suggestIndex >= 0 && suggestItems[suggestIndex]) {
      ev.preventDefault();
      pickContact(suggestItems[suggestIndex]);
    }
  });
  document.addEventListener("click", function (ev) {
    if (searchWrap && !searchWrap.contains(ev.target)) closeSuggest();
  });

  if (searchInput) {
    searchInput.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(function () { runSearch(searchInput.value); }, 120);
    });
    searchInput.addEventListener("focus", function () {
      if (String(searchInput.value || "").trim()) runSearch(searchInput.value);
    });
  }

  if (overlaySearchEl) {
    overlaySearchEl.addEventListener("input", function () {
      clearTimeout(overlaySearchTimer);
      overlaySearchTimer = setTimeout(function () {
        loadOverlayPeople(overlaySearchEl.value);
      }, 140);
    });
  }

  if (groupTitle) {
    groupTitle.addEventListener("input", function () {
      savedRoomKey = "";
      syncActions();
    });
  }
  if (groupWorkshop) {
    groupWorkshop.addEventListener("change", function () {
      savedRoomKey = "";
      syncActions();
    });
  }

  setTab("home");
})();
