(function () {
  function ensureModal() {
    var modal = document.getElementById("workshop-overview-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "workshop-overview-modal";
      modal.className = "workshop-overview";
      modal.setAttribute("aria-hidden", "true");
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      modal.setAttribute("aria-labelledby", "workshop-overview-title");
      modal.innerHTML =
        '<div class="workshop-overview-backdrop" data-workshop-close tabindex="-1"></div>' +
        '<div class="workshop-overview-panel">' +
        '<div class="workshop-overview-header">' +
        '<h2 id="workshop-overview-title"></h2>' +
        '<button type="button" class="workshop-overview-close" data-workshop-close aria-label="بستن">×</button>' +
        "</div>" +
        '<div class="workshop-overview-body" id="workshop-overview-body"></div>' +
        "</div>";
    }
    if (document.body && modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }
    return modal;
  }

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s == null ? "" : String(s);
    return d.innerHTML;
  }

  function renderPeopleGroup(title, names) {
    var list = Array.isArray(names) ? names.filter(Boolean) : [];
    var html = '<div class="workshop-overview-people">';
    html += "<h3>" + esc(title) + " (" + esc(String(list.length).replace(/[0-9]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[d]; })) + ")</h3>";
    if (!list.length) {
      html += '<p class="muted">هنوز کسی در این گروه نیست.</p>';
    } else {
      html += "<ul>";
      list.forEach(function (name) {
        html += "<li>" + esc(name) + "</li>";
      });
      html += "</ul>";
    }
    html += "</div>";
    return html;
  }

  function readPayload(openEl) {
    var script = openEl.querySelector("script.js-workshop-payload");
    if (script && script.textContent) {
      try {
        return JSON.parse(script.textContent);
      } catch (err) {}
    }
    var raw = openEl.getAttribute("data-workshop");
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (err) {
      var ta = document.createElement("textarea");
      ta.innerHTML = raw;
      try {
        return JSON.parse(ta.value);
      } catch (err2) {
        return null;
      }
    }
  }

  function closeModal() {
    var modal = document.getElementById("workshop-overview-modal");
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("workshop-overview-locked");
  }

  function openPayload(data) {
    if (!data) return;
    var modal = ensureModal();
    var titleEl = document.getElementById("workshop-overview-title");
    var bodyEl = document.getElementById("workshop-overview-body");
    if (!titleEl || !bodyEl) return;

    titleEl.textContent = data.title || "کارگاه";
    var html = "";
    html += '<div class="workshop-overview-meta">';
    if (data.type) html += '<span class="badge">' + esc(data.type) + "</span>";
    if (data.interval) html += '<span class="badge" style="margin-right:.35rem">' + esc(data.interval) + "</span>";
    if (data.doctor) html += '<div class="muted" style="margin-top:.45rem">' + esc(data.doctor) + "</div>";
    if (data.when) html += '<div style="margin-top:.45rem">' + esc(data.when) + "</div>";
    if (data.price) html += '<div class="muted" style="margin-top:.35rem">' + esc(data.price) + "</div>";
    html += "</div>";
    if (data.location) {
      html += '<p style="margin:.85rem 0 0"><strong>محل:</strong> ' + esc(data.location) + "</p>";
    }
    if (data.items) {
      html += '<p style="margin:.75rem 0 0"><strong>همراه داشته باشید:</strong> ' + esc(data.items) + "</p>";
    }
    if (data.description) {
      html += '<p class="muted" style="margin:.75rem 0 0;line-height:1.8">' + esc(data.description) + "</p>";
    }
    if (data.days && data.days.length) {
      html += '<h3 class="workshop-overview-days-title">روزهای برگزاری</h3><ul class="workshop-overview-days">';
      data.days.forEach(function (day) {
        html += "<li><strong>" + esc(day.title || day.date_fa || "") + "</strong>";
        if ((data.member || data.staff) && day.files && day.files.length) {
          html += '<div class="muted" style="font-size:.8rem;margin-top:.2rem">فایل‌ها: ' + esc(day.files.join("، ")) + "</div>";
        }
        html += "</li>";
      });
      html += "</ul>";
    }
    if (data.staff) {
      html += renderPeopleGroup("تأیید شده‌ها", data.approvedPeople);
      html += renderPeopleGroup("منتظر تأیید", data.pendingPeople);
      html += '<p class="muted" style="margin:.85rem 0 0;font-size:.85rem">از همین پنجره کلیات را ببینید؛ برای فایل هر جلسه وارد ویرایش شوید.</p>';
      if (data.editUrl) {
        html += '<button type="button" class="btn btn-primary btn-sm" style="margin-top:.75rem" data-workshop-go="' + esc(data.editUrl) + '">ویرایش و فایل جلسات</button>';
      }
    } else if (data.member) {
      html += '<p class="muted" style="margin:.85rem 0 0;font-size:.85rem">عضویت شما تأیید شده است. فایل هر جلسه را فقط داخل حساب خود ببینید.</p>';
      if (data.mediaUrl) {
        html += '<button type="button" class="btn btn-primary btn-sm" style="margin-top:.75rem" data-workshop-go="' + esc(data.mediaUrl) + '">مشاهده فایل جلسات</button>';
      }
    } else if (data.pending) {
      html += '<p class="muted" style="margin:.9rem 0 0">درخواست عضویت ثبت شده. بعد از تأیید منشی، درمانگر یا مدیر، فایل‌ها برای شما باز می‌شود.</p>';
    } else {
      html += '<p class="muted" style="margin:.9rem 0 0">فایل جلسات فقط بعد از عضویت و تأیید نمایش داده می‌شود.</p>';
    }
    bodyEl.innerHTML = html;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("workshop-overview-locked");
  }

  document.addEventListener("click", function (e) {
    var goBtn = e.target.closest("[data-workshop-go]");
    if (goBtn) {
      var href = goBtn.getAttribute("data-workshop-go") || "";
      e.preventDefault();
      e.stopPropagation();
      closeModal();
      if (href) window.location.assign(href);
      return;
    }
    if (e.target.closest("[data-workshop-close]")) {
      e.preventDefault();
      closeModal();
      return;
    }
    if (e.target.closest("a, button, input, select, textarea, label")) {
      return;
    }
    var openEl = e.target.closest("[data-workshop-open]");
    if (!openEl) return;
    var data = readPayload(openEl);
    if (!data) return;
    e.preventDefault();
    openPayload(data);
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeModal();
      return;
    }
    if (e.key !== "Enter" && e.key !== " ") return;
    var el = document.activeElement;
    if (!el || !el.closest || !el.hasAttribute("data-workshop-open")) return;
    if (e.target.closest("a, button, input, select, textarea, label")) return;
    var data = readPayload(el);
    if (!data) return;
    e.preventDefault();
    openPayload(data);
  });
})();
