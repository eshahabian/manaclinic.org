(function (global) {
  var COLOR = "#0d7a6a";
  var suggestUrl = "";
  var debounceTimer = null;
  var activeEditor = null;
  var menuEl = null;

  function baseUrl() {
    var b = (global.APP_BASE || "").replace(/\/$/, "");
    return b;
  }

  function ensureMenu() {
    if (menuEl) return menuEl;
    menuEl = document.createElement("div");
    menuEl.className = "mention-menu";
    menuEl.hidden = true;
    menuEl.setAttribute("role", "listbox");
    document.body.appendChild(menuEl);
    return menuEl;
  }

  function hideMenu() {
    if (menuEl) menuEl.hidden = true;
    activeEditor = null;
  }

  function getCaretRect() {
    var sel = global.getSelection();
    if (!sel || !sel.rangeCount) return null;
    var range = sel.getRangeAt(0).cloneRange();
    range.collapse(true);
    var rects = range.getClientRects();
    if (rects.length) return rects[0];
    var span = document.createElement("span");
    span.textContent = "\u200b";
    range.insertNode(span);
    var rect = span.getBoundingClientRect();
    span.parentNode.removeChild(span);
    return rect;
  }

  function positionMenu() {
    var menu = ensureMenu();
    var rect = getCaretRect();
    if (!rect) return;
    var top = rect.bottom + global.scrollY + 6;
    var left = rect.left + global.scrollX;
    menu.style.top = top + "px";
    menu.style.left = Math.max(8, left) + "px";
  }

  function insertMention(editor, item) {
    var sel = global.getSelection();
    if (!sel || !sel.rangeCount || !editor.contains(sel.anchorNode)) return;

    var range = sel.getRangeAt(0);
    var node = range.startContainer;
    var offset = range.startOffset;
    if (node.nodeType !== Node.TEXT_NODE) return;

    var text = node.textContent || "";
    var before = text.slice(0, offset);
    var at = before.lastIndexOf("@");
    if (at < 0) return;

    var deleteFrom = at;
    range.setStart(node, deleteFrom);
    range.setEnd(node, offset);
    range.deleteContents();

    var span = document.createElement("span");
    span.className = "mention";
    span.setAttribute("data-mention-id", item.id);
    span.setAttribute("contenteditable", "false");
    span.style.color = COLOR;
    span.style.fontWeight = "600";
    span.textContent = "@" + (item.label || item.name || "user");

    range.insertNode(span);
    var space = document.createTextNode("\u00a0");
    if (span.nextSibling) {
      span.parentNode.insertBefore(space, span.nextSibling);
    } else {
      span.parentNode.appendChild(space);
    }
    var after = document.createRange();
    after.setStartAfter(space);
    after.collapse(true);
    sel.removeAllRanges();
    sel.addRange(after);
    hideMenu();
    editor.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function renderMenu(items, editor) {
    var menu = ensureMenu();
    menu.innerHTML = "";
    if (!items.length) {
      menu.hidden = true;
      return;
    }
    items.forEach(function (item, idx) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "mention-menu-item";
      btn.setAttribute("role", "option");
      if (idx === 0) btn.classList.add("is-active");
      btn.innerHTML =
        '<span class="mention-menu-name">' +
        escapeHtml(item.label || item.name || "") +
        '</span><span class="mention-menu-role">' +
        escapeHtml(item.role_label || item.role || "") +
        "</span>";
      btn.addEventListener("mousedown", function (e) {
        e.preventDefault();
        insertMention(editor, item);
      });
      menu.appendChild(btn);
    });
    activeEditor = editor;
    menu.hidden = false;
    positionMenu();
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function queryAtToken(editor) {
    var sel = global.getSelection();
    if (!sel || !sel.rangeCount || !sel.isCollapsed) return null;
    if (!editor.contains(sel.anchorNode)) return null;
    var node = sel.anchorNode;
    if (node.nodeType !== Node.TEXT_NODE) return null;
    var text = node.textContent || "";
    var offset = sel.anchorOffset;
    var before = text.slice(0, offset);
    var match = before.match(/@([^\s@]{0,40})$/);
    if (!match) return null;
    // Don't trigger if we're inside an existing mention span
    if (node.parentElement && node.parentElement.closest(".mention")) return null;
    return match[1] || "";
  }

  function fetchSuggest(q, editor) {
    var url = suggestUrl + (suggestUrl.indexOf("?") >= 0 ? "&" : "?") + "q=" + encodeURIComponent(q);
    fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          hideMenu();
          return;
        }
        if (data.color) COLOR = data.color;
        if (activeEditor !== editor && queryAtToken(editor) === null) return;
        renderMenu(data.items || [], editor);
      })
      .catch(function () {
        hideMenu();
      });
  }

  function onEditorInput(editor) {
    var token = queryAtToken(editor);
    if (token === null) {
      hideMenu();
      return;
    }
    activeEditor = editor;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () {
      fetchSuggest(token, editor);
    }, 120);
  }

  function bindEditor(editor) {
    if (!editor || editor.dataset.mentionBound === "1") return;
    editor.dataset.mentionBound = "1";
    editor.addEventListener("keyup", function () {
      onEditorInput(editor);
    });
    editor.addEventListener("click", function () {
      onEditorInput(editor);
    });
    editor.addEventListener("keydown", function (e) {
      if (!menuEl || menuEl.hidden) return;
      var items = menuEl.querySelectorAll(".mention-menu-item");
      if (!items.length) return;
      var active = menuEl.querySelector(".mention-menu-item.is-active");
      var idx = Array.prototype.indexOf.call(items, active);
      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (active) active.classList.remove("is-active");
        idx = Math.min(items.length - 1, idx + 1);
        items[idx].classList.add("is-active");
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (active) active.classList.remove("is-active");
        idx = Math.max(0, idx - 1);
        items[idx].classList.add("is-active");
      } else if (e.key === "Enter" || e.key === "Tab") {
        if (active) {
          e.preventDefault();
          active.dispatchEvent(new Event("mousedown"));
        }
      } else if (e.key === "Escape") {
        hideMenu();
      }
    });
  }

  function initMentions(root) {
    suggestUrl = global.__MENTIONS_SUGGEST__ || (baseUrl() + "/api/mentions/suggest");
    var scope = root || document;
    scope.querySelectorAll("[data-rich-editor], [data-mention]").forEach(bindEditor);
  }

  document.addEventListener("mousedown", function (e) {
    if (!menuEl || menuEl.hidden) return;
    if (menuEl.contains(e.target)) return;
    hideMenu();
  });

  document.addEventListener("scroll", function () {
    if (menuEl && !menuEl.hidden) positionMenu();
  }, true);

  global.initMentions = initMentions;
  global.MENTION_COLOR = COLOR;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initMentions(document);
    });
  } else {
    initMentions(document);
  }
})(window);
