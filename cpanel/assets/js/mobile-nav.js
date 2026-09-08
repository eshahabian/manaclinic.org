(function () {
  var toggle = document.querySelector(".nav-toggle");
  var overlay = document.querySelector("[data-nav-overlay]");
  var drawer = document.getElementById("mobile-nav");
  if (!toggle || !overlay || !drawer) return;

  var panelSlot = drawer.querySelector("[data-mobile-panel]");
  var siteSlot = drawer.querySelector("[data-mobile-site]");
  var siteHeading = drawer.querySelector("[data-mobile-site-heading]");
  var siteNav = document.querySelector(".nav-links");
  var sideNav = document.querySelector(".side-nav");
  var mqDesktop = window.matchMedia("(min-width: 768px)");
  var open = false;

  function fillDrawer() {
    if (sideNav && panelSlot && !panelSlot.dataset.filled) {
      var title = sideNav.querySelector(".side-nav-title");
      var nav = sideNav.querySelector("nav");
      if (title) panelSlot.appendChild(title.cloneNode(true));
      if (nav) panelSlot.appendChild(nav.cloneNode(true));
      panelSlot.hidden = false;
      panelSlot.dataset.filled = "1";
      if (siteHeading) siteHeading.hidden = false;
    }
    if (siteNav && siteSlot && !siteSlot.dataset.filled) {
      var clone = document.createElement("nav");
      clone.className = "mobile-nav-links";
      Array.prototype.forEach.call(siteNav.children, function (child) {
        clone.appendChild(child.cloneNode(true));
      });
      siteSlot.appendChild(clone);
      siteSlot.dataset.filled = "1";
    }
  }

  function setOpen(next) {
    if (mqDesktop.matches) next = false;
    open = !!next;
    document.body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    overlay.classList.toggle("is-open", open);
    overlay.setAttribute("aria-hidden", open ? "false" : "true");
    drawer.classList.toggle("is-open", open);
    drawer.setAttribute("aria-hidden", open ? "false" : "true");
    if (open) {
      drawer.removeAttribute("inert");
      var first = drawer.querySelector("a");
      if (first) first.focus();
    } else {
      drawer.setAttribute("inert", "");
      if (document.activeElement && drawer.contains(document.activeElement)) {
        toggle.focus();
      }
    }
  }

  fillDrawer();

  toggle.addEventListener("click", function () {
    setOpen(!open);
  });

  overlay.addEventListener("click", function () {
    setOpen(false);
  });

  drawer.addEventListener("click", function (event) {
    var link = event.target.closest("a");
    if (link) setOpen(false);
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && open) {
      event.preventDefault();
      setOpen(false);
    }
  });

  function onMq(event) {
    if (event.matches) setOpen(false);
  }
  if (mqDesktop.addEventListener) mqDesktop.addEventListener("change", onMq);
  else if (mqDesktop.addListener) mqDesktop.addListener(onMq);
})();
