(function (global) {
  function optionText(opt) {
    return opt ? String(opt.textContent || "").trim() : "";
  }

  function selectedText(select) {
    var opt = select.options[select.selectedIndex];
    return opt && opt.value ? optionText(opt) : "";
  }

  function enhanceSearchSelect(select, options) {
    if (!select || select.dataset.searchSelect === "1") return null;
    select.dataset.searchSelect = "1";
    options = options || {};

    var wrap = document.createElement("div");
    wrap.className = "search-select";
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add("search-select-native");
    select.tabIndex = -1;
    select.setAttribute("aria-hidden", "true");

    var input = document.createElement("input");
    input.type = "text";
    input.className = "input search-select-input";
    input.setAttribute("autocomplete", "off");
    input.setAttribute("autocapitalize", "off");
    input.setAttribute("spellcheck", "false");
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-autocomplete", "list");
    var listId = (select.id || ("search-select-" + Math.random().toString(36).slice(2))) + "-list";
    input.setAttribute("aria-controls", listId);
    var firstOpt = select.options[0];
    input.placeholder = options.placeholder || (firstOpt && !firstOpt.value ? optionText(firstOpt) : "جستجو یا انتخاب");
    wrap.appendChild(input);

    var list = document.createElement("ul");
    list.className = "search-select-list";
    list.id = listId;
    list.hidden = true;
    wrap.appendChild(list);

    var activeIndex = -1;

    function allItems() {
      return Array.prototype.map.call(select.options, function (opt) {
        return { value: opt.value, text: optionText(opt) };
      });
    }

    function syncInput() {
      input.value = selectedText(select);
    }

    function close() {
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      wrap.classList.remove("is-open");
      activeIndex = -1;
    }

    function setValue(value, fireChange) {
      var next = value == null ? "" : String(value);
      if (select.value !== next) {
        select.value = next;
        if (fireChange) {
          select.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      syncInput();
      close();
    }

    function visibleOptions() {
      return Array.prototype.slice.call(list.querySelectorAll(".search-select-option"));
    }

    function highlight(index) {
      var opts = visibleOptions();
      if (!opts.length) {
        activeIndex = -1;
        return;
      }
      if (index < 0) index = opts.length - 1;
      if (index >= opts.length) index = 0;
      activeIndex = index;
      opts.forEach(function (el, i) {
        el.classList.toggle("is-active", i === activeIndex);
      });
      opts[activeIndex].scrollIntoView({ block: "nearest" });
    }

    function render(query) {
      var q = String(query || "").trim().toLowerCase();
      var current = select.value;
      list.innerHTML = "";
      activeIndex = -1;
      var shown = 0;
      allItems().forEach(function (item) {
        if (q && item.value && item.text.toLowerCase().indexOf(q) === -1) return;
        var li = document.createElement("li");
        li.className = "search-select-option" + (item.value === current ? " is-selected" : "");
        li.setAttribute("role", "option");
        li.dataset.value = item.value;
        li.textContent = item.text || "—";
        li.addEventListener("mousedown", function (e) {
          e.preventDefault();
          setValue(item.value, true);
        });
        list.appendChild(li);
        shown += 1;
      });
      if (!shown) {
        var empty = document.createElement("li");
        empty.className = "search-select-empty";
        empty.textContent = "موردی پیدا نشد";
        list.appendChild(empty);
      }
    }

    function open(query) {
      render(query);
      list.hidden = false;
      input.setAttribute("aria-expanded", "true");
      wrap.classList.add("is-open");
    }

    input.addEventListener("focus", function () {
      open("");
      input.select();
    });
    input.addEventListener("click", function () {
      if (list.hidden) open("");
    });
    input.addEventListener("input", function () {
      open(input.value);
    });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        e.preventDefault();
        syncInput();
        close();
        input.blur();
        return;
      }
      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (list.hidden) open(input.value);
        highlight(activeIndex + 1);
        return;
      }
      if (e.key === "ArrowUp") {
        e.preventDefault();
        if (list.hidden) open(input.value);
        highlight(activeIndex - 1);
        return;
      }
      if (e.key === "Enter") {
        var opts = visibleOptions();
        if (!list.hidden && opts.length) {
          e.preventDefault();
          var chosen = opts[activeIndex] || opts[0];
          setValue(chosen.dataset.value, true);
        }
      }
    });
    input.addEventListener("blur", function () {
      window.setTimeout(function () {
        var q = input.value.trim().toLowerCase();
        var exact = allItems().filter(function (item) {
          return item.value && item.text.toLowerCase() === q;
        });
        if (exact.length === 1) {
          setValue(exact[0].value, true);
        } else {
          syncInput();
        }
        close();
      }, 120);
    });

    document.addEventListener("mousedown", function (e) {
      if (!wrap.contains(e.target)) close();
    });

    select.addEventListener("change", syncInput);
    select.addEventListener("invalid", function () {
      input.focus();
    });

    var proto = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value");
    if (proto && proto.get && proto.set) {
      Object.defineProperty(select, "value", {
        configurable: true,
        enumerable: true,
        get: function () {
          return proto.get.call(select);
        },
        set: function (v) {
          proto.set.call(select, v);
          syncInput();
        }
      });
    }

    syncInput();
    return { sync: syncInput, setValue: setValue };
  }

  global.enhanceSearchSelect = enhanceSearchSelect;
})(window);
