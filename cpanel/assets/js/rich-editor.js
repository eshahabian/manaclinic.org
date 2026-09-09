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

  global.initRichEditor = function (opts) {
    var form = resolveEl(opts.form);
    var scope = form || document;
    var editor = resolveEl(opts.editor, scope);
    var toolbar = resolveEl(opts.toolbar, scope);
    var hidden = resolveEl(opts.hidden, scope);
    if (!editor || !toolbar || !form || !hidden) return;

    function focusEditor() {
      editor.focus();
    }

    toolbar.addEventListener("mousedown", function (e) {
      if (e.target.closest("button")) e.preventDefault();
    });

    toolbar.addEventListener("click", function (e) {
      var btn = e.target.closest("button");
      if (!btn) return;
      focusEditor();

      if (btn.dataset.cmd === "bold") {
        document.execCommand("bold", false, null);
        return;
      }
      if (btn.dataset.cmd === "removeFormat") {
        document.execCommand("removeFormat", false, null);
        var sel = global.getSelection();
        if (sel && sel.rangeCount && !sel.isCollapsed) {
          document.execCommand("hiliteColor", false, "transparent");
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
      }
    });

    form.addEventListener("submit", function () {
      hidden.value = editor.innerHTML;
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
