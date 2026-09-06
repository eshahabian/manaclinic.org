(function () {
  var modal = document.getElementById("workshop-overview-modal");
  if (!modal) return;
  var titleEl = document.getElementById("workshop-overview-title");
  var bodyEl = document.getElementById("workshop-overview-body");

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s == null ? "" : String(s);
    return d.innerHTML;
  }

  function closeModal() {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }

  function openPayload(data) {
    titleEl.textContent = data.title || "کارگاه";
    var html = "";
    html += '<div class="workshop-overview-meta">';
    html += '<span class="badge">' + esc(data.type || "") + "</span>";
    if (data.doctor) html += '<div class="muted" style="margin-top:.45rem">' + esc(data.doctor) + "</div>";
    html += '<div style="margin-top:.45rem">' + esc(data.when || "") + "</div>";
    html += '<div class="muted" style="margin-top:.35rem">' + esc(data.price || "") + "</div>";
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
      html += '<h3 style="margin:1.1rem 0 .45rem;font-size:.95rem">روزهای برگزاری</h3><ul class="workshop-overview-days">';
      data.days.forEach(function (day) {
        html += "<li><strong>" + esc(day.date_fa || day.title || "") + "</strong>";
        if (data.member && day.files && day.files.length) {
          html += '<div class="muted" style="font-size:.8rem;margin-top:.2rem">فایل‌ها: ' + esc(day.files.join("، ")) + "</div>";
        }
        html += "</li>";
      });
      html += "</ul>";
    }
    if (data.member) {
      html += '<p class="muted" style="margin:.85rem 0 0;font-size:.85rem">عضویت شما تأیید شده است. فایل هر جلسه را فقط داخل حساب خود ببینید.</p>';
      if (data.mediaUrl) {
        html += '<a class="btn btn-primary btn-sm" style="margin-top:.75rem" href="' + esc(data.mediaUrl) + '">مشاهده فایل جلسات</a>';
      }
    } else if (data.pending) {
      html += '<p class="muted" style="margin:.9rem 0 0">درخواست عضویت ثبت شده. بعد از تأیید منشی، درمانگر یا مدیر، فایل‌ها برای شما باز می‌شود.</p>';
    } else {
      html += '<p class="muted" style="margin:.9rem 0 0">فایل جلسات فقط بعد از عضویت و تأیید نمایش داده می‌شود.</p>';
    }
    bodyEl.innerHTML = html;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
  }

  document.addEventListener("click", function (e) {
    if (e.target.closest("[data-workshop-close]")) {
      closeModal();
      return;
    }
    var openBtn = e.target.closest("[data-workshop-open]");
    if (!openBtn) return;
    var raw = openBtn.getAttribute("data-workshop");
    if (!raw) return;
    try {
      openPayload(JSON.parse(raw));
    } catch (err) {}
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeModal();
  });
})();
