(function () {
  var list = document.getElementById("workshop-session-media-list");
  if (!list) return;
  var typeEl = document.getElementById("workshop-type");
  var startEl = document.getElementById("workshop-start-date");
  var endEl = document.getElementById("workshop-end-date");
  var extraWrap = document.getElementById("offline-extra-days");
  var extraView = document.getElementById("extra-session-date-view");
  var extraHidden = document.getElementById("extra-session-date");
  var extraBtn = document.getElementById("add-extra-session-day");
  var months = ["", "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];

  function toFa(n) {
    return String(n).replace(/[0-9]/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
  }
  function jalaliLabel(ymd) {
    if (!window.jalaali || !ymd) return ymd;
    var p = ymd.split("-");
    if (p.length !== 3) return ymd;
    var j = jalaali.toJalaali(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
    return toFa(j.jd) + " " + (months[j.jm] || "") + " " + toFa(j.jy);
  }
  function daysBetween(start, end) {
    var a = new Date(start + "T12:00:00");
    var b = new Date(end + "T12:00:00");
    if (isNaN(a) || isNaN(b) || b < a) return start ? [start] : [];
    var out = [];
    for (var d = new Date(a); d <= b && out.length < 60; d.setDate(d.getDate() + 1)) {
      var y = d.getFullYear();
      var m = String(d.getMonth() + 1).padStart(2, "0");
      var day = String(d.getDate()).padStart(2, "0");
      out.push(y + "-" + m + "-" + day);
    }
    return out;
  }
  function slotHtml(date, index) {
    var title = "جلسه " + toFa(index + 1) + " — " + jalaliLabel(date);
    return (
      '<div class="panel workshop-session-slot" data-session-date="' + date + '" style="padding:.85rem;background:#fff">' +
      "<strong>" + title + "</strong>" +
      '<input type="hidden" name="extra_session_dates[]" value="' + date + '">' +
      '<div class="workshop-session-slots">' +
      '<div class="workshop-session-kind"><label class="label">پی‌دی‌اف</label><input class="input" type="file" name="session_file[' + date + '][PDF]" accept=".pdf,application/pdf"></div>' +
      '<div class="workshop-session-kind"><label class="label">صوت</label><input class="input" type="file" name="session_file[' + date + '][AUDIO]" accept="audio/*,.mp3,.m4a,.wav,.ogg"></div>' +
      '<div class="workshop-session-kind"><label class="label">ویدیو</label><input class="input" type="file" name="session_file[' + date + '][VIDEO]" accept="video/*,.mp4,.webm,.mov"></div>' +
      "</div></div>"
    );
  }
  function existingDates() {
    return Array.prototype.map.call(list.querySelectorAll("[data-session-date]"), function (el) {
      return el.getAttribute("data-session-date");
    }).filter(Boolean);
  }
  function addDate(date) {
    if (!date || existingDates().indexOf(date) !== -1) return;
    list.insertAdjacentHTML("beforeend", slotHtml(date, existingDates().length));
  }
  function rebuildFromRange() {
    if (!typeEl || typeEl.value === "OFFLINE") return;
    if (list.querySelector("[data-session-date]")) return;
    var start = startEl ? startEl.value : "";
    var end = endEl ? endEl.value : start;
    if (!start) return;
    daysBetween(start, end).forEach(function (date, i) {
      list.insertAdjacentHTML("beforeend", slotHtml(date, i));
    });
  }
  function syncOfflineBox() {
    if (!extraWrap || !typeEl) return;
    extraWrap.hidden = typeEl.value !== "OFFLINE";
  }

  if (typeEl) typeEl.addEventListener("change", function () {
    syncOfflineBox();
    if (typeEl.value !== "OFFLINE") rebuildFromRange();
  });
  ["jdp:change", "change"].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      if (e.target && (e.target.id === "workshop-start-date-view" || e.target.id === "workshop-end-date-view")) {
        setTimeout(rebuildFromRange, 50);
      }
    });
  });
  if (extraBtn && extraHidden) {
    extraBtn.addEventListener("click", function () {
      if (extraHidden.value) addDate(extraHidden.value);
    });
  }
  document.querySelectorAll(".js-media-delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!confirm("این فایل حذف شود؟")) return;
      var fd = new FormData();
      fd.append("action", "delete");
      fd.append("workshop_id", btn.getAttribute("data-workshop") || "");
      fd.append("item_id", btn.getAttribute("data-item") || "");
      fetch(btn.getAttribute("data-action"), { method: "POST", body: fd, credentials: "same-origin" })
        .then(function () { location.reload(); });
    });
  });

  syncOfflineBox();
  rebuildFromRange();
})();
