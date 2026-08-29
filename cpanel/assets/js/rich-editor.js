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

  global.initRichEditor = function (opts) {
    var editor = document.querySelector(opts.editor);
    var toolbar = document.querySelector(opts.toolbar);
    var form = document.querySelector(opts.form);
    var hidden = document.querySelector(opts.hidden);
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
})(window);
