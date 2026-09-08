(function () {
  var root = document.getElementById("home-workshop-banners");
  if (!root) return;
  var modal = document.getElementById("home-workshop-modal");
  if (!modal) return;
  var titleEl = document.getElementById("home-workshop-modal-title");
  var bodyEl = document.getElementById("home-workshop-modal-body");
  var ctaEl = document.getElementById("home-workshop-modal-cta");

  function openModal(btn) {
    if (titleEl) titleEl.textContent = btn.getAttribute("data-title") || "";
    if (bodyEl) {
      var desc = btn.getAttribute("data-description") || "";
      var meta = btn.getAttribute("data-meta") || "";
      var image = btn.getAttribute("data-image") || "";
      bodyEl.innerHTML = "";
      if (image) {
        var img = document.createElement("img");
        img.src = image;
        img.alt = btn.getAttribute("data-title") || "";
        img.className = "home-workshop-modal-photo";
        bodyEl.appendChild(img);
      }
      if (meta) {
        var p = document.createElement("p");
        p.className = "muted";
        p.textContent = meta;
        bodyEl.appendChild(p);
      }
      if (desc) {
        var goal = document.createElement("p");
        goal.className = "home-workshop-goal-label";
        goal.textContent = "اهداف دوره";
        bodyEl.appendChild(goal);
      }
      desc.split(/\n+/).forEach(function (line) {
        line = line.trim();
        if (!line) return;
        var d = document.createElement("p");
        d.textContent = line;
        bodyEl.appendChild(d);
      });
      if (!bodyEl.childElementCount) {
        var empty = document.createElement("p");
        empty.className = "muted";
        empty.textContent = "توضیح بیشتری ثبت نشده است.";
        bodyEl.appendChild(empty);
      }
    }
    if (ctaEl) {
      var href = btn.getAttribute("data-apply") || "";
      var can = btn.getAttribute("data-can-apply") === "1";
      ctaEl.href = href;
      ctaEl.hidden = !href;
      ctaEl.textContent = can ? "ثبت‌نام در دوره" : "مشاهده جزئیات ثبت‌نام";
    }
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("home-workshop-locked");
  }

  function closeModal() {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("home-workshop-locked");
  }

  root.querySelectorAll("[data-workshop-banner]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      openModal(btn);
    });
  });
  modal.querySelectorAll("[data-workshop-close]").forEach(function (el) {
    el.addEventListener("click", closeModal);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && modal.classList.contains("is-open")) closeModal();
  });
})();
