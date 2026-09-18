(function (global) {
  var EMOJIS = [
    "😀", "😃", "😄", "😁", "😊", "🙂", "😉", "😍", "🥰", "😘",
    "😜", "🤗", "🤔", "😮", "😲", "😢", "😭", "😤", "😡", "😴",
    "👍", "👎", "👏", "🙏", "💪", "✌️", "🤝", "👋", "❤️", "🧡",
    "💛", "💚", "💙", "💜", "🖤", "🤍", "💔", "✨", "⭐", "🔥",
    "✅", "❌", "⚠️", "📌", "📝", "📅", "⏰", "📞", "💬", "📣",
    "🎉", "🎊", "🎁", "🌸", "🌿", "☕", "🍰", "🏥", "🩺", "💊"
  ];

  var openPanel = null;

  function closePanel() {
    if (openPanel) {
      openPanel.remove();
      openPanel = null;
    }
  }

  function insertAtCursor(field, emoji) {
    if (!field) return;
    if (field.isContentEditable) {
      field.focus();
      if (document.queryCommandSupported && document.queryCommandSupported("insertText")) {
        document.execCommand("insertText", false, emoji);
      } else {
        var sel = global.getSelection();
        if (sel && sel.rangeCount) {
          var range = sel.getRangeAt(0);
          range.deleteContents();
          range.insertNode(document.createTextNode(emoji));
          range.collapse(false);
          sel.removeAllRanges();
          sel.addRange(range);
        } else {
          field.appendChild(document.createTextNode(emoji));
        }
      }
      field.dispatchEvent(new Event("input", { bubbles: true }));
      return;
    }
    var start = field.selectionStart != null ? field.selectionStart : field.value.length;
    var end = field.selectionEnd != null ? field.selectionEnd : start;
    var val = String(field.value || "");
    field.value = val.slice(0, start) + emoji + val.slice(end);
    var pos = start + emoji.length;
    try {
      field.setSelectionRange(pos, pos);
    } catch (err) {}
    field.focus();
    field.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function buildPanel(anchor, onPick) {
    closePanel();
    var panel = document.createElement("div");
    panel.className = "emoji-picker-panel";
    panel.setAttribute("role", "listbox");
    panel.setAttribute("aria-label", "انتخاب ایموجی");
    EMOJIS.forEach(function (em) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "emoji-picker-item";
      b.textContent = em;
      b.title = em;
      b.addEventListener("mousedown", function (e) {
        e.preventDefault();
        onPick(em);
        closePanel();
      });
      panel.appendChild(b);
    });
    document.body.appendChild(panel);
    openPanel = panel;

    var rect = anchor.getBoundingClientRect();
    var top = rect.bottom + 6 + global.scrollY;
    var left = rect.left + global.scrollX;
    panel.style.top = top + "px";
    panel.style.left = Math.max(8, Math.min(left, global.innerWidth - panel.offsetWidth - 8)) + "px";

    // اگر از پایین صفحه بیرون زد، بالای دکمه باز شود
    var pr = panel.getBoundingClientRect();
    if (pr.bottom > global.innerHeight - 8) {
      panel.style.top = rect.top + global.scrollY - panel.offsetHeight - 6 + "px";
    }
  }

  function attachButton(btn, getTarget) {
    if (!btn || btn.dataset.emojiBound === "1") return;
    btn.dataset.emojiBound = "1";
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (openPanel) {
        closePanel();
        return;
      }
      var target = typeof getTarget === "function" ? getTarget() : getTarget;
      if (!target) return;
      buildPanel(btn, function (em) {
        insertAtCursor(target, em);
      });
    });
  }

  /** برای contenteditable / ادیتور غنی */
  global.insertEmojiAtTarget = insertAtCursor;

  /** باز کردن پنل روی یک لنگر و درج در target (عنصر یا تابع) */
  global.openEmojiPicker = function (anchor, target) {
    if (!anchor) return;
    if (openPanel) {
      closePanel();
      return;
    }
    var field = typeof target === "function" ? target() : target;
    if (!field) return;
    buildPanel(anchor, function (em) {
      insertAtCursor(field, em);
    });
  };

  global.bindEmojiButton = function (btn, target) {
    attachButton(btn, target);
  };

  /**
   * دکمه ایموجی کنار textarea/input با data-emoji-field
   * یا داخل .emoji-field-wrap
   */
  global.initEmojiFields = function (root) {
    var scope = root || document;
    scope.querySelectorAll("[data-emoji-field]").forEach(function (field) {
      if (field.dataset.emojiReady === "1") return;
      field.dataset.emojiReady = "1";
      var wrap = field.closest(".emoji-field-wrap");
      if (!wrap) {
        wrap = document.createElement("div");
        wrap.className = "emoji-field-wrap";
        field.parentNode.insertBefore(wrap, field);
        wrap.appendChild(field);
      }
      var btn = wrap.querySelector("[data-emoji-btn]");
      if (!btn) {
        btn = document.createElement("button");
        btn.type = "button";
        btn.className = "emoji-field-btn";
        btn.setAttribute("data-emoji-btn", "1");
        btn.setAttribute("title", "ایموجی");
        btn.setAttribute("aria-label", "افزودن ایموجی");
        btn.textContent = "😊";
        wrap.appendChild(btn);
      }
      attachButton(btn, field);
    });

    scope.querySelectorAll("[data-emoji-btn][data-emoji-for]").forEach(function (btn) {
      var id = btn.getAttribute("data-emoji-for");
      var field = id ? document.getElementById(id) : null;
      if (field) attachButton(btn, field);
    });
  };

  document.addEventListener("mousedown", function (e) {
    if (!openPanel) return;
    if (e.target.closest(".emoji-picker-panel") || e.target.closest("[data-emoji-btn]") || e.target.closest("[data-cmd=emoji]")) {
      return;
    }
    closePanel();
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closePanel();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      global.initEmojiFields(document);
    });
  } else {
    global.initEmojiFields(document);
  }
})(window);
