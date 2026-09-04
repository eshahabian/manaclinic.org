(function () {
  function toneFromId(id) {
    id = String(id || "");
    if (id.indexOf("offline") !== -1) return "offline";
    if (id.indexOf("online") !== -1) return "online";
    if (id.indexOf("archive") !== -1) return "archive";
    if (id === "new") return "new";
    return "in-person";
  }

  function initBinder(root) {
    var tabs = root.querySelectorAll("[data-binder-tab]");
    var panels = root.querySelectorAll("[data-binder-panel]");
    if (!tabs.length || !panels.length) return;
    var useHash = root.getAttribute("data-binder-hash") !== "0";

    function activate(id) {
      if (!id) return;
      var found = false;
      panels.forEach(function (panel) {
        if (panel.getAttribute("data-binder-panel") === id) found = true;
      });
      if (!found) return;
      tabs.forEach(function (tab) {
        var on = tab.getAttribute("data-binder-tab") === id;
        tab.classList.toggle("is-active", on);
        tab.setAttribute("aria-selected", on ? "true" : "false");
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute("data-binder-panel") === id;
        panel.classList.toggle("is-active", on);
        if (on) panel.removeAttribute("hidden");
        else panel.setAttribute("hidden", "");
      });
      root.setAttribute("data-binder-tone", toneFromId(id));
      if (useHash && history.replaceState) {
        history.replaceState(null, "", "#" + id);
      }
      window.dispatchEvent(new CustomEvent("binder-tab-change", { detail: id }));
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        activate(tab.getAttribute("data-binder-tab"));
      });
    });

    var hash = (location.hash || "").replace("#", "");
    if (hash === "session-notes" || hash === "workshop-form") hash = "new";
    var initial = root.getAttribute("data-binder-initial") || "";
    if (hash) activate(hash);
    else if (initial) activate(initial);
    else {
      var current = root.querySelector("[data-binder-tab].is-active");
      root.setAttribute("data-binder-tone", toneFromId(current ? current.getAttribute("data-binder-tab") : "in-person"));
    }
  }

  document.querySelectorAll("[data-binder-tabs]").forEach(initBinder);
})();
