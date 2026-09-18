(function () {
  var KEY = "mana-theme";
  var html = document.documentElement;
  var themeBtn = document.getElementById("theme-toggle");
  var topBtn = document.getElementById("back-to-top");

  function currentTheme() {
    return html.getAttribute("data-theme") === "dark" ? "dark" : "light";
  }

  function setTheme(theme) {
    var next = theme === "dark" ? "dark" : "light";
    html.setAttribute("data-theme", next);
    try {
      localStorage.setItem(KEY, next);
    } catch (err) {}
    if (themeBtn) {
      themeBtn.setAttribute("aria-label", next === "dark" ? "حالت روز" : "حالت شب");
      themeBtn.setAttribute("title", next === "dark" ? "حالت روز" : "حالت شب");
    }
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
      meta.setAttribute("content", next === "dark" ? "#101816" : "#1a5c4a");
    }
  }

  if (themeBtn) {
    themeBtn.addEventListener("click", function () {
      setTheme(currentTheme() === "dark" ? "light" : "dark");
    });
    setTheme(currentTheme());
  }

  function syncTopBtn() {
    if (!topBtn) return;
    var show = window.scrollY > 280;
    topBtn.classList.toggle("is-hidden", !show);
  }
  if (topBtn) {
    topBtn.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
    window.addEventListener("scroll", syncTopBtn, { passive: true });
    syncTopBtn();
  }

  function bindSideNavScroll(nav) {
    if (!nav || nav.dataset.chromeScroll === "1") return;
    nav.dataset.chromeScroll = "1";
    nav.addEventListener(
      "wheel",
      function (e) {
        if (window.matchMedia("(max-width: 767px)").matches) return;
        if (window.getComputedStyle(nav).display === "none") return;
        nav.scrollTop += e.deltaY;
        e.preventDefault();
      },
      { passive: false }
    );
  }

  document.querySelectorAll(".side-nav").forEach(bindSideNavScroll);
})();
