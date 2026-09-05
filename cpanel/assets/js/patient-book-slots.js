(function () {
  var root = document.querySelector("[data-patient-book]");
  if (!root) return;

  var bookUrl = root.getAttribute("data-book-url") || "";
  var afterUrl = root.getAttribute("data-after-url") || "";
  var termsId = root.getAttribute("data-terms-id") || "terms-accept-dash";
  var msgEl = document.getElementById("dash-book-msg");
  var termsCb = document.getElementById(termsId);

  function showMsg(text, ok) {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.className = "course-flash " + (ok ? "ok" : "err");
    msgEl.style.display = "block";
    msgEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function readJson(r) {
    return r.text().then(function (text) {
      var j = {};
      try {
        j = text ? JSON.parse(text) : {};
      } catch (e) {
        j = { error: "پاسخ نامعتبر از سرور" };
      }
      return { ok: r.ok, j: j };
    });
  }

  root.querySelectorAll(".dash-book-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (termsCb && !termsCb.checked) {
        showMsg("لطفاً شرایط رزرو را بپذیرید.", false);
        return;
      }
      var doctorId = btn.getAttribute("data-doctor") || "";
      var date = btn.getAttribute("data-date") || "";
      var time = btn.getAttribute("data-time") || "";
      if (!doctorId || !date || !time || !bookUrl) return;
      if (!confirm("این ساعت رزرو شود؟")) return;
      btn.disabled = true;
      var old = btn.textContent;
      btn.textContent = "...";
      var fd = new FormData();
      fd.append("doctorId", doctorId);
      fd.append("date", date);
      fd.append("time", time);
      fd.append("accept_terms", "1");
      fetch(bookUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJson)
        .then(function (res) {
          if (!res.ok) {
            btn.disabled = termsCb ? !termsCb.checked : false;
            btn.textContent = old;
            showMsg(res.j.error || "رزرو ناموفق بود", false);
            return;
          }
          if (res.j.paymentUrl) {
            location.href = res.j.paymentUrl;
            return;
          }
          showMsg(res.j.message || "نوبت ثبت شد.", true);
          setTimeout(function () {
            location.href = afterUrl || location.href;
          }, 700);
        })
        .catch(function () {
          btn.disabled = termsCb ? !termsCb.checked : false;
          btn.textContent = old;
          showMsg("خطای شبکه — دوباره تلاش کنید", false);
        });
    });
  });
})();
