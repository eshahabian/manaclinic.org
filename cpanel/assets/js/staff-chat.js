(function () {
  var configEl = document.getElementById("sapp-chat-config");
  var thread = document.getElementById("sapp-thread");
  if (!configEl || !thread) return;
  var config = {};
  try { config = JSON.parse(configEl.textContent || "{}"); } catch (err) { return; }

  var hold = document.getElementById("sapp-hold");
  var pop = document.getElementById("sapp-hold-pop");
  var peopleBox = document.getElementById("sapp-hold-people");
  var seenLabel = document.getElementById("sapp-hold-seen-label");
  var seenFaces = document.getElementById("sapp-hold-seen-faces");
  var menu = document.getElementById("sapp-hold-menu");
  var forwardBox = document.getElementById("sapp-hold-forward");
  var roomBox = document.getElementById("sapp-hold-rooms");
  var selectBar = document.getElementById("sapp-selectbar");
  var replyBox = document.getElementById("sapp-reply");
  var current = null;
  var timer = null;
  var startX = 0;
  var startY = 0;
  var fromTouch = false;
  var suppressClick = false;

  function faDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return "۰۱۲۳۴۵۶۷۸۹"[digit];
    });
  }

  function token() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") || "" : "";
  }

  function postAction(fields) {
    var body = new FormData();
    body.append("_csrf", token());
    body.append("ajax", "1");
    body.append("room_id", config.roomId || "");
    Object.keys(fields).forEach(function (key) {
      body.append(key, fields[key]);
    });
    return fetch(config.post, {
      method: "POST",
      body: body,
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data.ok) {
          throw new Error((data && data.error) || "انجام نشد.");
        }
        return data;
      });
    });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () {
        return legacyCopy(text);
      });
    }
    return legacyCopy(text);
  }

  function legacyCopy(text) {
    var area = document.createElement("textarea");
    area.value = text;
    document.body.appendChild(area);
    area.select();
    try { document.execCommand("copy"); } catch (err) {}
    area.remove();
    return Promise.resolve();
  }

  function messageText(article) {
    return article.getAttribute("data-body") || "";
  }

  function messageFiles(article) {
    try { return JSON.parse(article.getAttribute("data-files") || "[]"); } catch (err) { return []; }
  }

  function seenPeople(article) {
    try { return JSON.parse(article.getAttribute("data-seen") || "[]"); } catch (err) { return []; }
  }

  function fillSeen(article) {
    var people = seenPeople(article);
    var group = thread.getAttribute("data-group") === "1";
    seenFaces.innerHTML = "";
    peopleBox.innerHTML = "";
    if (group) {
      seenLabel.textContent = people.length ? faDigits(people.length) + " نفر دیدند" : "هنوز کسی ندیده";
      peopleBox.hidden = people.length === 0;
      people.forEach(function (person) {
        var row = document.createElement("div");
        row.className = "sapp-seen-row";
        var avatar = document.createElement("span");
        avatar.className = "sapp-seen-av";
        avatar.textContent = String(person.name || "؟").charAt(0);
        var text = document.createElement("span");
        text.appendChild(document.createTextNode(person.name || ""));
        var role = document.createElement("small");
        role.textContent = person.role ? " · " + person.role : "";
        text.appendChild(role);
        var mark = document.createElement("span");
        mark.className = "sapp-seen-check";
        mark.textContent = "✓✓";
        row.appendChild(avatar);
        row.appendChild(text);
        row.appendChild(mark);
        peopleBox.appendChild(row);
      });
    } else {
      seenLabel.textContent = people.length ? "دیده شد" : "هنوز دیده نشده";
      peopleBox.hidden = true;
    }
    people.slice(0, 3).forEach(function (person) {
      var face = document.createElement("i");
      face.textContent = String(person.name || "؟").charAt(0);
      seenFaces.appendChild(face);
    });
  }

  function closeMenu() {
    if (!hold) return;
    hold.hidden = true;
    current = null;
    document.body.style.overflow = "";
  }

  function placeMenu(article) {
    pop.style.top = "0px";
    pop.style.left = "8px";
    pop.style.right = "auto";
    var msg = article.getBoundingClientRect();
    var box = pop.getBoundingClientRect();
    var top = msg.bottom + 8;
    if (top + box.height > window.innerHeight - 8) {
      top = Math.max(8, msg.top - box.height - 8);
    }
    pop.style.top = top + "px";
    if (article.classList.contains("is-mine")) {
      var right = Math.max(8, window.innerWidth - msg.right);
      pop.style.right = right + "px";
      pop.style.left = "auto";
    } else {
      pop.style.left = Math.max(8, msg.left) + "px";
      pop.style.right = "auto";
    }
  }

  function openMenu(article) {
    if (thread.classList.contains("is-selecting")) return;
    current = article;
    menu.hidden = false;
    forwardBox.hidden = true;
    fillSeen(article);
    var files = messageFiles(article);
    var save = menu.querySelector('[data-act="save"]');
    var remove = menu.querySelector('[data-act="delete"]');
    var pin = menu.querySelector('[data-act="pin"]');
    if (save) save.hidden = files.length === 0;
    if (remove) remove.hidden = article.getAttribute("data-mine") !== "1";
    if (pin) {
      var pinLabel = pin.querySelector("span");
      if (pinLabel) {
        pinLabel.textContent = thread.getAttribute("data-pinned") === article.id.slice(2) ? "برداشتن سنجاق" : "سنجاق";
      }
    }
    var emoji = article.querySelector(".sapp-reacts");
    var mine = emoji ? emoji.getAttribute("data-mine") || "" : "";
    pop.querySelectorAll(".sapp-hold-emojis button").forEach(function (button) {
      button.classList.toggle("is-on", button.getAttribute("data-emoji") === mine);
    });
    hold.hidden = false;
    document.body.style.overflow = "hidden";
    placeMenu(article);
    if (navigator.vibrate) {
      try { navigator.vibrate(12); } catch (err) {}
    }
  }

  function paintTicks(states) {
    Object.keys(states || {}).forEach(function (id) {
      var el = document.querySelector('[data-ticks="' + id + '"]');
      if (!el) return;
      el.className = "sapp-ticks is-" + states[id];
      el.setAttribute("aria-label", states[id] === "read" ? "دیده شد" : (states[id] === "delivered" ? "رسید" : "ارسال شد"));
      if (states[id] !== "sent" && el.querySelectorAll("path").length < 2) {
        el.innerHTML = '<svg viewBox="0 0 20 16" aria-hidden="true"><path d="M1.4 8.2 4.6 11.4 11 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.2 8.2 9.4 11.4 15.8 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      }
    });
  }

  function paintReacts(article, counts, emoji) {
    var box = article.querySelector(".sapp-reacts");
    if (!box) return;
    var mine = box.getAttribute("data-mine") || "";
    if (emoji) mine = mine === emoji ? "" : emoji;
    box.setAttribute("data-mine", mine);
    box.innerHTML = "";
    Object.keys(counts || {}).forEach(function (key) {
      if (!counts[key]) return;
      var chip = document.createElement("span");
      chip.className = "sapp-react-chip" + (key === mine ? " is-mine" : "");
      chip.textContent = key + " " + faDigits(counts[key]);
      box.appendChild(chip);
    });
  }

  function clearPress() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  function armPress(event, x, y) {
    clearPress();
    startX = x;
    startY = y;
    var article = event.target.closest(".sapp-msg");
    if (!article || thread.classList.contains("is-selecting")) return;
    timer = setTimeout(function () {
      timer = null;
      suppressClick = true;
      openMenu(article);
    }, 420);
  }

  thread.addEventListener("touchstart", function (event) {
    fromTouch = true;
    if (!event.touches || !event.touches[0]) return;
    armPress(event, event.touches[0].clientX, event.touches[0].clientY);
  }, { passive: true });

  thread.addEventListener("touchmove", function (event) {
    if (!timer || !event.touches || !event.touches[0]) return;
    var dx = Math.abs(event.touches[0].clientX - startX);
    var dy = Math.abs(event.touches[0].clientY - startY);
    if (dx > 10 || dy > 10) clearPress();
  }, { passive: true });

  thread.addEventListener("touchend", clearPress);
  thread.addEventListener("touchcancel", clearPress);

  thread.addEventListener("mousedown", function (event) {
    if (fromTouch || event.button !== 0) return;
    armPress(event, event.clientX, event.clientY);
  });
  window.addEventListener("mouseup", clearPress);

  thread.addEventListener("contextmenu", function (event) {
    var article = event.target.closest(".sapp-msg");
    if (!article) return;
    event.preventDefault();
    openMenu(article);
  });

  thread.addEventListener("click", function (event) {
    if (suppressClick) {
      suppressClick = false;
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (!thread.classList.contains("is-selecting")) return;
    var article = event.target.closest(".sapp-msg");
    if (!article) return;
    event.preventDefault();
    article.classList.toggle("is-picked");
  }, true);

  document.getElementById("sapp-hold-back").addEventListener("click", closeMenu);
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeMenu();
  });

  pop.querySelectorAll(".sapp-hold-emojis button").forEach(function (button) {
    button.addEventListener("click", function () {
      if (!current) return;
      var article = current;
      var emoji = button.getAttribute("data-emoji") || "";
      postAction({ form: "react", message_id: article.id.slice(2), emoji: emoji }).then(function (data) {
        paintReacts(article, data.counts || {}, emoji);
        closeMenu();
      }).catch(function (err) {
        window.alert(err.message || "انجام نشد.");
      });
    });
  });

  menu.addEventListener("click", function (event) {
    var button = event.target.closest("[data-act]");
    if (!button || !current) return;
    var article = current;
    var id = article.id.slice(2);
    var act = button.getAttribute("data-act");
    if (act === "seen") return;
    if (act === "reply") {
      var who = article.getAttribute("data-name") || "";
      var snippet = messageText(article) || (messageFiles(article)[0] && messageFiles(article)[0].name) || "پیام";
      document.getElementById("sapp-reply-id").value = id;
      document.getElementById("sapp-reply-who").textContent = who;
      document.getElementById("sapp-reply-snippet").textContent = snippet;
      replyBox.hidden = false;
      closeMenu();
      var field = document.getElementById("chat-body");
      if (field) field.focus();
      return;
    }
    if (act === "copy") {
      var text = messageText(article);
      if (!text) {
        window.alert("متنی برای کپی نیست.");
        return;
      }
      copyText(text).then(closeMenu);
      return;
    }
    if (act === "save") {
      messageFiles(article).forEach(function (file) {
        var link = document.createElement("a");
        link.href = file.url + (String(file.url).indexOf("?") >= 0 ? "&" : "?") + "download=1";
        link.download = file.name || "";
        document.body.appendChild(link);
        link.click();
        link.remove();
      });
      closeMenu();
      return;
    }
    if (act === "pin") {
      postAction({ form: "pin", message_id: id }).then(function () {
        window.location.reload();
      }).catch(function (err) {
        window.alert(err.message || "انجام نشد.");
      });
      return;
    }
    if (act === "link") {
      copyText(window.location.origin + window.location.pathname + "#m-" + id).then(closeMenu);
      return;
    }
    if (act === "forward") {
      menu.hidden = true;
      forwardBox.hidden = false;
      roomBox.innerHTML = "";
      var rooms = config.rooms || [];
      if (!rooms.length) {
        var empty = document.createElement("p");
        empty.className = "sapp-hold-forward-title";
        empty.textContent = "اتاق دیگری برای هدایت نیست.";
        roomBox.appendChild(empty);
      }
      rooms.forEach(function (room) {
        var item = document.createElement("button");
        item.type = "button";
        item.className = "sapp-hold-item";
        item.textContent = room.title || "اتاق";
        item.addEventListener("click", function () {
          postAction({ form: "forward", message_id: id, target_room: room.id }).then(function () {
            window.location.href = String(config.chatBase || "").replace(/\/$/, "") + "/" + room.id;
          }).catch(function (err) {
            window.alert(err.message || "انجام نشد.");
          });
        });
        roomBox.appendChild(item);
      });
      placeMenu(article);
      return;
    }
    if (act === "delete") {
      if (!window.confirm("این پیام حذف شود؟")) return;
      postAction({ form: "delete", message_id: id }).then(function () {
        window.location.reload();
      }).catch(function (err) {
        window.alert(err.message || "انجام نشد.");
      });
      return;
    }
    if (act === "select") {
      thread.classList.add("is-selecting");
      article.classList.add("is-picked");
      selectBar.hidden = false;
      closeMenu();
    }
  });

  document.getElementById("sapp-reply-x").addEventListener("click", function () {
    document.getElementById("sapp-reply-id").value = "";
    replyBox.hidden = true;
  });

  document.getElementById("sapp-select-cancel").addEventListener("click", function () {
    thread.classList.remove("is-selecting");
    thread.querySelectorAll(".sapp-msg.is-picked").forEach(function (item) {
      item.classList.remove("is-picked");
    });
    selectBar.hidden = true;
  });

  document.getElementById("sapp-select-copy").addEventListener("click", function () {
    var parts = [];
    thread.querySelectorAll(".sapp-msg.is-picked").forEach(function (item) {
      var text = messageText(item);
      if (text) parts.push(text);
    });
    if (!parts.length) {
      window.alert("متنی برای کپی نیست.");
      return;
    }
    copyText(parts.join("\n"));
  });

  document.getElementById("sapp-select-delete").addEventListener("click", function () {
    var ids = [];
    thread.querySelectorAll(".sapp-msg.is-picked").forEach(function (item) {
      if (item.getAttribute("data-mine") === "1") ids.push(item.id.slice(2));
    });
    if (!ids.length) {
      window.alert("فقط پیام خودتان حذف می‌شود.");
      return;
    }
    if (!window.confirm("پیام‌های انتخاب‌شده حذف شوند؟")) return;
    var chain = Promise.resolve();
    ids.forEach(function (id) {
      chain = chain.then(function () {
        return postAction({ form: "delete", message_id: id });
      });
    });
    chain.then(function () {
      window.location.reload();
    }).catch(function (err) {
      window.alert(err.message || "انجام نشد.");
    });
  });

  if (config.receipts) {
    setInterval(function () {
      if (document.hidden) return;
      fetch(config.receipts, { headers: { Accept: "application/json" }, credentials: "same-origin" })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (!data) return;
          if (data.states) paintTicks(data.states);
          Object.keys(data.seen || {}).forEach(function (id) {
            var article = document.getElementById("m-" + id);
            if (!article) return;
            article.setAttribute("data-seen", JSON.stringify(data.seen[id]));
            if (current && current.id === article.id) fillSeen(article);
          });
        })
        .catch(function () {});
    }, 4000);
  }

  if (window.location.hash) {
    var target = document.getElementById(window.location.hash.slice(1));
    if (target) target.scrollIntoView({ block: "center" });
  } else {
    window.scrollTo(0, document.body.scrollHeight);
  }
})();
