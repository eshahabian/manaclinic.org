(function () {
  "use strict";
  if (window.__taskLines) return;
  window.__taskLines = true;

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches("[data-task-composer]")) return;
    var fields = form.querySelectorAll("[data-task-line]");
    var any = false;
    fields.forEach(function (field) {
      if (field.value.trim() !== "") any = true;
    });
    if (!any) {
      e.preventDefault();
      if (fields[0]) fields[0].focus();
    }
  });
})();
