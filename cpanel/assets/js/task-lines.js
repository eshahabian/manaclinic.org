(function () {
  "use strict";
  if (window.__taskLines) return;
  window.__taskLines = true;

  function addLine(box) {
    if (!box || box.querySelectorAll("[data-task-line]").length >= 20) return;
    var row = document.createElement("div");
    row.style.cssText = "display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.45rem;align-items:center";
    row.innerHTML = '<input class="input" name="body[]" maxlength="500" data-task-line autocomplete="off" placeholder="کار بعدی" aria-label="کار بعدی">'
      + '<button type="button" class="btn btn-outline" data-task-line-remove aria-label="حذف این کار" style="min-width:2.75rem">×</button>';
    box.appendChild(row);
    var field = row.querySelector("[data-task-line]");
    if (field) field.focus();
  }

  document.addEventListener("keydown", function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches("[data-task-line]")) return;
    if (e.key !== "Enter" || e.shiftKey || e.isComposing) return;
    e.preventDefault();
    addLine(input.closest("[data-task-lines]"));
  });

  document.addEventListener("click", function (e) {
    var button = e.target && e.target.closest ? e.target.closest("[data-task-line-remove]") : null;
    if (!button) return;
    var row = button.parentNode;
    if (row && row.parentNode) row.parentNode.removeChild(row);
  });

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches("[data-task-composer]")) return;
    var lines = form.querySelectorAll("[data-task-line]");
    var any = false;
    lines.forEach(function (input) {
      if (input.value.trim() !== "") any = true;
    });
    if (!any) {
      e.preventDefault();
      if (lines[0]) lines[0].focus();
    }
  });
})();
