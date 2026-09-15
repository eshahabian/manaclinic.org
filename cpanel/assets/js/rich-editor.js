(function (global) {
  function wrapSelection(editor, tagName, styles) {
    var sel = global.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return false;
    var range = sel.getRangeAt(0);
    if (!editor.contains(range.commonAncestorContainer)) return false;
    var el = document.createElement(tagName);
    if (styles) {
      Object.keys(styles).forEach(function (k) {
        el.style[k] = styles[k];
      });
    }
    try {
      range.surroundContents(el);
    } catch (err) {
      var frag = range.extractContents();
      el.appendChild(frag);
      range.insertNode(el);
    }
    sel.removeAllRanges();
    var r = document.createRange();
    r.selectNodeContents(el);
    sel.addRange(r);
    return true;
  }

  function resolveEl(value, root) {
    if (!value) return null;
    if (typeof value === "string") {
      return (root || document).querySelector(value);
    }
    return value;
  }

  function insertTable(editor) {
    focusNode(editor);
    var html =
      '<table style="border-collapse:collapse;width:100%">' +
      "<tbody>" +
      [0, 1, 2]
        .map(function () {
          return (
            "<tr>" +
            [0, 1, 2]
              .map(function () {
                return '<td style="border:1px solid #ccc;padding:6px">&nbsp;</td>';
              })
              .join("") +
            "</tr>"
          );
        })
        .join("") +
      "</tbody></table><p><br></p>";
    if (document.queryCommandSupported && document.queryCommandSupported("insertHTML")) {
      document.execCommand("insertHTML", false, html);
      return;
    }
    var sel = global.getSelection();
    if (!sel || !sel.rangeCount) return;
    var range = sel.getRangeAt(0);
    range.deleteContents();
    var tmp = document.createElement("div");
    tmp.innerHTML = html;
    var frag = document.createDocumentFragment();
    var node;
    while ((node = tmp.firstChild)) {
      frag.appendChild(node);
    }
    range.insertNode(frag);
  }

  function focusNode(editor) {
    if (editor && typeof editor.focus === "function") editor.focus();
  }

  function applyToolbarCommand(editor, btn) {
    if (!editor || !btn) return;
    focusNode(editor);

    if (btn.dataset.cmd === "bold") {
      document.execCommand("bold", false, null);
      return;
    }
    if (btn.dataset.cmd === "underline") {
      document.execCommand("underline", false, null);
      return;
    }
    if (btn.dataset.cmd === "insertTable") {
      insertTable(editor);
      return;
    }
    if (btn.dataset.cmd === "removeFormat") {
      document.execCommand("removeFormat", false, null);
      var sel = global.getSelection();
      if (sel && sel.rangeCount && !sel.isCollapsed) {
        document.execCommand("styleWithCSS", true, null);
        document.execCommand("hiliteColor", false, "transparent");
        document.execCommand("foreColor", false, "inherit");
      }
      return;
    }
    if (btn.dataset.fontsize) {
      wrapSelection(editor, "span", { fontSize: btn.dataset.fontsize + "px" });
      return;
    }
    if (btn.dataset.hl) {
      document.execCommand("styleWithCSS", true, null);
      var ok = document.execCommand("hiliteColor", false, btn.dataset.hl);
      if (!ok) {
        wrapSelection(editor, "span", { backgroundColor: btn.dataset.hl });
      }
      return;
    }
    if (btn.dataset.color) {
      document.execCommand("styleWithCSS", true, null);
      var okColor = document.execCommand("foreColor", false, btn.dataset.color);
      if (!okColor) {
        wrapSelection(editor, "span", { color: btn.dataset.color });
      }
    }
  }

  global.initRichEditor = function (opts) {
    var form = resolveEl(opts.form);
    var scope = form || document;
    var editor = resolveEl(opts.editor, scope);
    var toolbar = resolveEl(opts.toolbar, scope);
    var hidden = resolveEl(opts.hidden, scope);
    if (!editor || !toolbar) return;

    toolbar.addEventListener("mousedown", function (e) {
      if (e.target.closest("button")) e.preventDefault();
    });

    toolbar.addEventListener("click", function (e) {
      var btn = e.target.closest("button");
      if (!btn) return;
      applyToolbarCommand(editor, btn);
    });

    if (form && hidden) {
      form.addEventListener("submit", function () {
        hidden.value = editor.innerHTML;
      });
    }
  };

  /** یک نوار ابزار برای چند ادیتور؛ روی آخرین فوکوس اعمال می‌شود */
  global.initSharedRichToolbar = function (opts) {
    var root = resolveEl(opts.root) || document;
    var toolbar = resolveEl(opts.toolbar, root);
    if (!toolbar) return;
    var selector = opts.editorSelector || "[data-rich-editor]";
    var active = null;

    function currentEditor() {
      if (active && root.contains(active)) return active;
      var focused = root.querySelector(selector + ":focus");
      if (focused) return focused;
      return root.querySelector(selector);
    }

    root.querySelectorAll(selector).forEach(function (ed) {
      ed.addEventListener("focus", function () {
        active = ed;
      });
    });

    toolbar.addEventListener("mousedown", function (e) {
      if (e.target.closest("button")) e.preventDefault();
    });

    toolbar.addEventListener("click", function (e) {
      var btn = e.target.closest("button");
      if (!btn) return;
      applyToolbarCommand(currentEditor(), btn);
    });
  };

  global.initRichEditors = function (root) {
    var scope = root || document;
    scope.querySelectorAll("form[data-rich-note]").forEach(function (form) {
      global.initRichEditor({
        form: form,
        editor: form.querySelector("[data-rich-editor]"),
        toolbar: form.querySelector("[data-rich-toolbar]"),
        hidden: form.querySelector("[data-rich-hidden]"),
      });
    });
  };
})(window);
