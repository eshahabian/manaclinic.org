(function () {
  var root = document.querySelector("[data-patient-courses]");
  if (!root) return;

  var enrollUrl = root.getAttribute("data-enroll-url") || "";
  var payUrl = root.getAttribute("data-pay-url") || "";
  var cancelUrl = root.getAttribute("data-cancel-url") || "";
  var msgEl = document.getElementById("course-msg");

  function showMsg(text, ok) {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.className = "course-flash " + (ok ? "ok" : "err");
    msgEl.style.display = "block";
    msgEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function readJsonResponse(r) {
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

  root.querySelectorAll(".enroll-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var workshopId = btn.getAttribute("data-id");
      if (!workshopId || !enrollUrl) return;
      btn.disabled = true;
      var oldLabel = btn.textContent;
      btn.textContent = "در حال ثبت‌نام...";
      var fd = new FormData();
      fd.append("workshopId", workshopId);
      fetch(enrollUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function (res) {
          if (!res.ok) {
            btn.disabled = false;
            btn.textContent = oldLabel;
            showMsg(res.j.error || "ثبت‌نام ناموفق بود", false);
            return;
          }
          if (res.j.message) showMsg(res.j.message, true);
          setTimeout(function () {
            location.reload();
          }, res.j.message ? 800 : 0);
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = oldLabel;
          showMsg("خطای شبکه — دوباره تلاش کنید", false);
        });
    });
  });

  root.querySelectorAll(".pay-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var id = btn.getAttribute("data-id");
      if (!id || !payUrl) return;
      var useWallet = root.querySelector('.use-wallet[data-id="' + id + '"]');
      var fd = new FormData();
      fd.append("enrollmentId", id);
      if (useWallet && useWallet.checked) fd.append("use_wallet", "1");
      btn.disabled = true;
      fetch(payUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function (res) {
          if (!res.ok) {
            btn.disabled = false;
            showMsg(res.j.error || "پرداخت ناموفق", false);
            return;
          }
          if (res.j.paymentUrl) {
            location.href = res.j.paymentUrl;
            return;
          }
          location.reload();
        })
        .catch(function () {
          btn.disabled = false;
          showMsg("خطای شبکه", false);
        });
    });
  });

  root.querySelectorAll(".cancel-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!confirm("ثبت‌نام لغو شود؟")) return;
      if (!cancelUrl) return;
      var fd = new FormData();
      fd.append("enrollmentId", btn.getAttribute("data-id"));
      fetch(cancelUrl, { method: "POST", body: fd, credentials: "same-origin" })
        .then(readJsonResponse)
        .then(function (res) {
          if (!res.ok) {
            showMsg(res.j.error || "خطا", false);
            return;
          }
          location.reload();
        })
        .catch(function () {
          showMsg("خطای شبکه", false);
        });
    });
  });
})();
