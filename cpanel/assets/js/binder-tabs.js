(function () {
  function toneFromId(id) {
    id = String(id || "");
    if (id === "appts" || id === "appointments") return "appts";
    if (id === "workshops") return "workshops";
    if (id.indexOf("offline") !== -1) return "offline";
    if (id.indexOf("online") !== -1) return "online";
    if (id.indexOf("archive") !== -1) return "archive";
    if (id === "new") return "new";
    return "in-person";
  }

  function toneFromTab(tab, fallbackId) {
    if (tab) {
      var explicit = tab.getAttribute("data-binder-tone");
      if (explicit) return explicit;
      fallbackId = tab.getAttribute("data-binder-tab") || fallbackId;
    }
    return toneFromId(fallbackId);
  }

  function list(root, childClass, attr) {
    var parent = root.querySelector(":scope > " + childClass);
    if (!parent) return [];
    return Array.prototype.slice.call(parent.querySelectorAll(":scope > [" + attr + "]"));
  }

  function initBinder(root) {
    var tabs = list(root, ".binder-tabs", "data-binder-tab");
    var panels = list(root, ".binder-body", "data-binder-panel");
    if (!tabs.length || !panels.length) return;
    var useHash = root.getAttribute("data-binder-hash") !== "0";

    function hasPanel(id) {
      return panels.some(function (panel) {
        return panel.getAttribute("data-binder-panel") === id;
      });
    }

    function activate(id) {
      if (!id || !hasPanel(id)) return false;
      var activeTab = null;
      tabs.forEach(function (tab) {
        var on = tab.getAttribute("data-binder-tab") === id;
        tab.classList.toggle("is-active", on);
        tab.setAttribute("aria-selected", on ? "true" : "false");
        if (on) activeTab = tab;
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute("data-binder-panel") === id;
        panel.classList.toggle("is-active", on);
        if (on) panel.removeAttribute("hidden");
        else panel.setAttribute("hidden", "");
      });
      root.setAttribute("data-binder-tone", toneFromTab(activeTab, id));
      if (useHash && history.replaceState) {
        history.replaceState(null, "", "#" + id);
      }
      window.dispatchEvent(new CustomEvent("binder-tab-change", { detail: id }));
      return true;
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        activate(tab.getAttribute("data-binder-tab"));
      });
    });

    var hash = useHash ? (location.hash || "").replace("#", "") : "";
    if (hash === "session-notes" || hash === "workshop-form") hash = "new";
    if (hash === "appointments") hash = "appts";
    var initial = root.getAttribute("data-binder-initial") || "";
    if (hash && activate(hash)) return;
    if (initial && activate(initial)) return;
    var current = tabs.find(function (tab) {
      return tab.classList.contains("is-active");
    });
    root.setAttribute("data-binder-tone", toneFromTab(current, current ? current.getAttribute("data-binder-tab") : "in-person"));
  }

  document.querySelectorAll("[data-binder-tabs]").forEach(initBinder);
})();
