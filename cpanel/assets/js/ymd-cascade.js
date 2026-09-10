(function () {
  function opt(value, label, count) {
    var o = document.createElement("option");
    o.value = value;
    o.textContent = count ? (label + " (" + count + ")") : label;
    return o;
  }
  function fillSelect(sel, items, selected) {
    if (!sel) return;
    sel.innerHTML = "";
    items.forEach(function (item) {
      sel.appendChild(opt(item.id, item.label, item.count));
    });
    if (selected && items.some(function (i) { return i.id === selected; })) {
      sel.value = selected;
    } else if (items[0]) {
      sel.value = items[0].id;
    }
  }
  function list(scope, sel) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(sel));
  }
  function monthsOf(yearEl) {
    return list(yearEl, ":scope > [data-ymd-month]").map(function (el) {
      return {
        id: el.getAttribute("data-ymd-month") || "",
        label: el.getAttribute("data-ymd-label") || "",
        count: el.getAttribute("data-ymd-count") || "",
        el: el
      };
    });
  }
  function daysOf(monthEl) {
    return list(monthEl, ":scope > [data-ymd-day]").map(function (el) {
      return {
        id: el.getAttribute("data-ymd-day") || "",
        label: el.getAttribute("data-ymd-label") || "",
        count: el.getAttribute("data-ymd-count") || "",
        el: el
      };
    });
  }
  function showOnly(nodes, attr, id) {
    nodes.forEach(function (el) {
      var on = el.getAttribute(attr) === id;
      el.hidden = !on;
      el.classList.toggle("is-active", on);
    });
  }
  function init(root) {
    if (!root || root.getAttribute("data-ymd-ready") === "1") return;
    root.setAttribute("data-ymd-ready", "1");
    var yearSel = root.querySelector("[data-ymd-select=year]");
    var monthSel = root.querySelector("[data-ymd-select=month]");
    var daySel = root.querySelector("[data-ymd-select=day]");
    var yearEls = list(root, ":scope > .ymd-cascade-body > [data-ymd-year]");
    var initialYear = root.getAttribute("data-ymd-initial-year") || "";
    var initialMonth = root.getAttribute("data-ymd-initial-month") || "";
    var initialDay = root.getAttribute("data-ymd-initial-day") || "";

    function currentYearEl() {
      var id = yearSel ? yearSel.value : initialYear;
      return yearEls.filter(function (el) { return el.getAttribute("data-ymd-year") === id; })[0] || yearEls[0];
    }
    function currentMonthEl() {
      var y = currentYearEl();
      if (!y) return null;
      var id = monthSel ? monthSel.value : initialMonth;
      var months = list(y, ":scope > [data-ymd-month]");
      return months.filter(function (el) { return el.getAttribute("data-ymd-month") === id; })[0] || months[0];
    }
    function applyDay() {
      var m = currentMonthEl();
      if (!m || !daySel) return;
      showOnly(list(m, ":scope > [data-ymd-day]"), "data-ymd-day", daySel.value);
    }
    function applyMonth(keepDay) {
      var y = currentYearEl();
      if (!y) return;
      var months = monthsOf(y);
      showOnly(list(y, ":scope > [data-ymd-month]"), "data-ymd-month", monthSel ? monthSel.value : "");
      var m = currentMonthEl();
      var days = m ? daysOf(m) : [];
      var wantDay = (keepDay && daySel && daySel.value) ? daySel.value : initialDay;
      fillSelect(daySel, days, wantDay);
      applyDay();
    }
    function applyYear(keepMonth) {
      var id = yearSel ? yearSel.value : initialYear;
      showOnly(yearEls, "data-ymd-year", id);
      var y = currentYearEl();
      var months = y ? monthsOf(y) : [];
      var wantMonth = (keepMonth && monthSel && monthSel.value) ? monthSel.value : initialMonth;
      fillSelect(monthSel, months, wantMonth);
      applyMonth(false);
    }

    if (yearSel) {
      yearSel.addEventListener("change", function () { applyYear(false); });
    }
    if (monthSel) {
      monthSel.addEventListener("change", function () { applyMonth(false); });
    }
    if (daySel) {
      daySel.addEventListener("change", applyDay);
    }
    applyYear(true);
  }
  function boot(scope) {
    var base = scope && scope.nodeType === 1 ? scope : document;
    if (base.hasAttribute && base.hasAttribute("data-ymd-cascade")) init(base);
    list(base, "[data-ymd-cascade]").forEach(init);
  }
  window.initYmdCascade = boot;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { boot(document); });
  } else {
    boot(document);
  }
})();
