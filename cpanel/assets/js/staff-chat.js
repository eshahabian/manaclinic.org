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
  var pendingReact = {};
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

  function messageId(article) {
    if (!article) return "";
    var id = article.getAttribute("data-id") || "";
    if (id) return id;
    return article.id.indexOf("m-") === 0 ? article.id.slice(2) : "";
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
    if (!article || !seenFaces || !peopleBox || !seenLabel) return;
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
    if (!pop || !article || !article.getBoundingClientRect) return;
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
    if (!article || thread.classList.contains("is-selecting")) return;
    if (!hold || !pop || !menu || !forwardBox) return;
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
        pinLabel.textContent = thread.getAttribute("data-pinned") === messageId(article) ? "برداشتن سنجاق" : "سنجاق";
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

  function paintReacts(article, counts, mineEmoji) {
    var box = article.querySelector(".sapp-reacts");
    if (!box) return;
    mineEmoji = mineEmoji || "";
    box.setAttribute("data-mine", mineEmoji);
    box.innerHTML = "";
    Object.keys(counts || {}).forEach(function (key) {
      if (!counts[key]) return;
      var chip = document.createElement("span");
      chip.className = "sapp-react-chip" + (key === mineEmoji ? " is-mine" : "");
      chip.textContent = key + " " + faDigits(counts[key]);
      box.appendChild(chip);
    });
  }

  function applyReactions(map) {
    if (!map || Array.isArray(map)) return;
    var seen = {};
    Object.keys(map).forEach(function (id) {
      seen[id] = true;
      if (pendingReact[id]) return;
      var article = document.getElementById("m-" + id);
      if (!article) return;
      paintReacts(article, (map[id] && map[id].counts) || {}, (map[id] && map[id].mine) || "");
    });
    thread.querySelectorAll(".sapp-msg").forEach(function (article) {
      var id = messageId(article);
      if (!id || pendingReact[id] || seen[id]) return;
      var box = article.querySelector(".sapp-reacts");
      if (box && box.childNodes.length) paintReacts(article, {}, "");
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
    var target = event.target;
    var article = target && target.closest ? target.closest(".sapp-msg") : null;
    if (!article || thread.classList.contains("is-selecting")) return;
    timer = setTimeout(function () {
      timer = null;
      suppressClick = true;
      openMenu(article);
    }, 420);
  }

  try {
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
    var target = event.target;
    var article = target && target.closest ? target.closest(".sapp-msg") : null;
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
    var target = event.target;
    var article = target && target.closest ? target.closest(".sapp-msg") : null;
    if (!article) return;
    event.preventDefault();
    article.classList.toggle("is-picked");
  }, true);

  var holdBack = document.getElementById("sapp-hold-back");
  if (holdBack) holdBack.addEventListener("click", closeMenu);
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeMenu();
  });

  if (pop) pop.querySelectorAll(".sapp-hold-emojis button").forEach(function (button) {
    button.addEventListener("click", function () {
      if (!current) return;
      var article = current;
      var id = messageId(article);
      var emoji = button.getAttribute("data-emoji") || "";
      if (!id || pendingReact[id]) return;
      pendingReact[id] = true;
      var box = article.querySelector(".sapp-reacts");
      var prevMine = box ? (box.getAttribute("data-mine") || "") : "";
      var nextMine = prevMine === emoji ? "" : emoji;
      var optimistic = {};
      if (nextMine) optimistic[nextMine] = 1;
      paintReacts(article, optimistic, nextMine);
      closeMenu();
      postAction({ form: "react", message_id: id, emoji: emoji }).then(function (data) {
        paintReacts(article, data.counts || {}, data.mine || "");
      }).catch(function (err) {
        paintReacts(article, {}, prevMine);
        window.alert(err.message || "انجام نشد.");
      }).then(function () {
        delete pendingReact[id];
      });
    });
  });

  if (menu) menu.addEventListener("click", function (event) {
    var actNode = event.target;
    var button = actNode && actNode.closest ? actNode.closest("[data-act]") : null;
    if (!button || !current) return;
    var article = current;
    var id = messageId(article);
    var act = button.getAttribute("data-act");
    if (act === "seen") return;
    if (act === "reply") {
      var who = article.getAttribute("data-name") || "";
      var snippet = messageText(article) || (messageFiles(article)[0] && messageFiles(article)[0].name) || "پیام";
      var replyInput = document.getElementById("sapp-reply-id");
      var replyWho = document.getElementById("sapp-reply-who");
      var replySnippet = document.getElementById("sapp-reply-snippet");
      if (replyInput) replyInput.value = id;
      if (replyWho) replyWho.textContent = who;
      if (replySnippet) replySnippet.textContent = snippet;
      if (replyBox) replyBox.hidden = false;
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
      if (!menu || !forwardBox || !roomBox) return;
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
      if (selectBar) selectBar.hidden = false;
      closeMenu();
    }
  });

  var replyX = document.getElementById("sapp-reply-x");
  if (replyX) replyX.addEventListener("click", function () {
    var replyInput = document.getElementById("sapp-reply-id");
    if (replyInput) replyInput.value = "";
    if (replyBox) replyBox.hidden = true;
  });

  var selectCancel = document.getElementById("sapp-select-cancel");
  if (selectCancel) selectCancel.addEventListener("click", function () {
    thread.classList.remove("is-selecting");
    thread.querySelectorAll(".sapp-msg.is-picked").forEach(function (item) {
      item.classList.remove("is-picked");
    });
    if (selectBar) selectBar.hidden = true;
  });

  var selectCopy = document.getElementById("sapp-select-copy");
  if (selectCopy) selectCopy.addEventListener("click", function () {
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

  var selectDelete = document.getElementById("sapp-select-delete");
  if (selectDelete) selectDelete.addEventListener("click", function () {
    var ids = [];
    thread.querySelectorAll(".sapp-msg.is-picked").forEach(function (item) {
      if (item.getAttribute("data-mine") === "1") ids.push(messageId(item));
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
  } catch (err) {}

  function buildMessage(msg) {
    var article = document.createElement("article");
    article.className = "sapp-msg " + (msg.mine ? "is-mine" : "is-theirs") + (msg.pending ? " is-pending" : "");
    article.id = "m-" + msg.id;
    article.setAttribute("data-id", msg.id);
    article.setAttribute("data-created", msg.created || "");
    article.setAttribute("data-mine", msg.mine ? "1" : "0");
    article.setAttribute("data-name", msg.name || "");
    article.setAttribute("data-body", msg.body || "");
    article.setAttribute("data-seen", "[]");
    article.setAttribute("data-files", JSON.stringify(msg.files || []));
    if (!msg.mine) {
      var who = document.createElement("span");
      who.className = "sapp-msg-name";
      who.textContent = msg.name || "";
      article.appendChild(who);
    }
    if (msg.forward) {
      var forwarded = document.createElement("span");
      forwarded.className = "sapp-forward";
      forwarded.textContent = "هدایت‌شده از " + msg.forward;
      article.appendChild(forwarded);
    }
    if (msg.replyTo) {
      var quote = document.createElement("a");
      quote.className = "sapp-quote";
      quote.href = "#m-" + msg.replyTo;
      var quoteName = document.createElement("strong");
      quoteName.textContent = msg.replyName || "پیام";
      var quoteText = document.createElement("span");
      quoteText.textContent = msg.replyText || "پیام";
      quote.appendChild(quoteName);
      quote.appendChild(quoteText);
      article.appendChild(quote);
    }
    if (msg.body) {
      var body = document.createElement("p");
      body.textContent = msg.body;
      article.appendChild(body);
    }
    (msg.files || []).forEach(function (file) {
      var href = String(file.url || "");
      if (!href) {
        var pendingName = document.createElement("p");
        pendingName.textContent = file.name || "فایل";
        article.appendChild(pendingName);
        return;
      }
      if (String(file.mime || "").indexOf("image/") === 0) {
        var link = document.createElement("a");
        link.href = href;
        var image = document.createElement("img");
        image.src = href;
        image.alt = file.name || "فایل";
        link.appendChild(image);
        article.appendChild(link);
      } else {
        var line = document.createElement("p");
        var fileLink = document.createElement("a");
        fileLink.href = href;
        fileLink.textContent = file.name || "فایل";
        line.appendChild(fileLink);
        article.appendChild(line);
      }
    });
    var reacts = document.createElement("div");
    reacts.className = "sapp-reacts";
    reacts.setAttribute("data-mine", "");
    article.appendChild(reacts);
    var meta = document.createElement("div");
    meta.className = "sapp-msg-meta";
    var time = document.createElement("time");
    time.textContent = msg.time || "";
    meta.appendChild(time);
    if (msg.mine) {
      var ticks = document.createElement("span");
      ticks.className = "sapp-ticks is-sent";
      ticks.setAttribute("data-ticks", msg.id);
      ticks.setAttribute("aria-label", "ارسال شد");
      ticks.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.2 8.2 6.4 11.4 12.8 4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      meta.appendChild(ticks);
    }
    article.appendChild(meta);
    return article;
  }

  function threadNearBottom() {
    if (!thread) return true;
    return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 140;
  }

  function currentUserId() {
    var fromBody = document.body ? (document.body.getAttribute("data-user") || "") : "";
    return String(fromBody || config.userId || "").trim().toLowerCase();
  }

  function messageIsMine(msg) {
    if (!msg) return false;
    var author = String(msg.userId || "").trim().toLowerCase();
    var me = currentUserId();
    if (author && me && author === me) return true;
    return !!msg.mine;
  }

  function appendMessages(list) {
    if (!list || !list.length) return;
    var empty = thread.querySelector(".sapp-chat-empty");
    var follow = threadNearBottom();
    var added = false;
    list.forEach(function (msg) {
      if (!msg || !msg.id || document.getElementById("m-" + msg.id)) return;
      if (messageIsMine(msg)) msg.mine = true;
      if (empty) {
        empty.remove();
        empty = null;
      }
      thread.appendChild(buildMessage(msg));
      added = true;
      if (!msg.mine && !msg.pending && window.sappShowChatNote) {
        var noteText = msg.body ? String(msg.body) : "فایل تازه";
        window.sappShowChatNote(msg.name || "مانا کارکنان", noteText);
      }
    });
    if (added && follow) stickThread();
  }

  function cursorId() {
    var nodes = thread.querySelectorAll(".sapp-msg");
    var i;
    for (i = nodes.length - 1; i >= 0; i--) {
      var id = messageId(nodes[i]);
      if (/^[a-f0-9]{24}$/.test(id)) return id;
    }
    return "";
  }

  var liveBusy = false;
  var liveAgain = false;

  function pullLive() {
    if (!config.receipts) return;
    if (liveBusy) {
      liveAgain = true;
      return;
    }
    liveBusy = true;
    var after = cursorId();
    var live = config.receipts + "?after=" + encodeURIComponent(after) + "&t=" + Date.now();
    fetch(live, { cache: "no-store", credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data) return;
        appendMessages(data.messages || []);
        applyReactions(data.reactions);
        if (data.states) paintTicks(data.states);
        Object.keys(data.seen || {}).forEach(function (id) {
          var article = document.getElementById("m-" + id);
          if (!article) return;
          article.setAttribute("data-seen", JSON.stringify(data.seen[id]));
          if (current && current.id === article.id) fillSeen(article);
        });
      })
      .catch(function () {})
      .then(function () {
        liveBusy = false;
        if (!liveAgain) return;
        liveAgain = false;
        pullLive();
      });
  }

  function armLive() {
    setTimeout(function () {
      try { pullLive(); } catch (err) {}
      armLive();
    }, 1500);
  }

  var compose = document.querySelector(".sapp-compose");
  if (compose) {
    compose.addEventListener("submit", function (event) {
      event.preventDefault();
      var field = document.getElementById("chat-body");
      var file = document.getElementById("chat-file");
      var text = field ? field.value : "";
      var hasFile = file && file.files && file.files.length > 0;
      if (!String(text).trim() && !hasFile) return;
      window.sappSentAt = Date.now();
      var body = new FormData(compose);
      body.set("ajax", "1");
      var tempId = "tmp" + Date.now();
      appendMessages([{
        id: tempId,
        mine: true,
        pending: true,
        name: "",
        body: String(text),
        time: "…",
        created: "",
        forward: "",
        replyTo: "",
        files: hasFile ? [{ url: "", name: file.files[0].name || "فایل", mime: "" }] : []
      }]);
      if (field) field.value = "";
      if (file) file.value = "";
      var replyId = document.getElementById("sapp-reply-id");
      if (replyId) replyId.value = "";
      if (replyBox) replyBox.hidden = true;
      stickThread();
      fetch(compose.action, {
        method: "POST",
        body: body,
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok || !data.ok) {
            throw new Error((data && data.error) || "پیام فرستاده نشد.");
          }
          return data;
        });
      }).then(function (data) {
        var temp = document.getElementById("m-" + tempId);
        if (temp) temp.remove();
        if (data.message) appendMessages([data.message]);
        stickThread();
      }).catch(function (err) {
        window.sappSentAt = 0;
        var temp = document.getElementById("m-" + tempId);
        if (temp) temp.classList.remove("is-pending");
        if (field && !field.value) field.value = text;
        window.alert(err.message || "پیام فرستاده نشد.");
      });
    });
  }

  window.sappOnPush = function (payload) {
    if (payload && payload.roomId && payload.roomId === config.roomId) pullLive();
  };

  if (config.receipts) {
    pullLive();
    armLive();
    document.addEventListener("visibilitychange", pullLive);
    window.addEventListener("focus", pullLive);
    window.addEventListener("pageshow", pullLive);
    if (window.visualViewport) {
      var viewportPull = 0;
      window.visualViewport.addEventListener("resize", function () {
        var now = Date.now();
        if (now - viewportPull < 1000) return;
        viewportPull = now;
        pullLive();
      });
    }
  }

  document.body.classList.add("is-thread");
  var typeField = document.getElementById("chat-body");
  var svhProbe = null;
  var baseSvh = 0;
  var kbState = "idle";
  var kbTimer = 0;
  var kbBumped = false;
  var kbRaised = false;
  var maxCovered = 0;
  var kbFloor = 0;
  var stickOnOpen = true;

  function stickThread() {
    if (thread) thread.scrollTop = thread.scrollHeight;
  }

  function rootPx() {
    var size = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
    return size > 0 ? size : 16;
  }

  function phoneLike() {
    return window.matchMedia("(hover: none) and (pointer: coarse)").matches;
  }

  function fieldFocused() {
    return !!(typeField && document.activeElement === typeField);
  }

  function svhPx() {
    if (!svhProbe) {
      svhProbe = document.createElement("div");
      svhProbe.setAttribute("aria-hidden", "true");
      svhProbe.style.cssText = "position:fixed;left:0;top:0;width:0;height:100svh;visibility:hidden;pointer-events:none";
      document.documentElement.appendChild(svhProbe);
    }
    var height = svhProbe.getBoundingClientRect().height;
    return height > 0 ? height : window.innerHeight;
  }

  function noteBase() {
    if (fieldFocused()) return;
    var height = svhPx();
    if (height > 0) baseSvh = height;
  }

  function svhDropped() {
    return baseSvh > 0 && svhPx() < baseSvh - rootPx() * 5;
  }

  function keyboardCovered() {
    var vv = window.visualViewport;
    if (!vv) return 0;
    var offset = vv.offsetTop > 0 ? vv.offsetTop : 0;
    return Math.max(0, Math.round(svhPx() - (offset + vv.height)));
  }

  function composerOverlap() {
    var compose = document.querySelector(".sapp-compose");
    var field = typeField || document.getElementById("chat-body");
    var edge = 0;
    if (compose) edge = compose.getBoundingClientRect().bottom;
    if (field) edge = Math.max(edge, field.getBoundingClientRect().bottom);
    if (!edge) return 0;
    var visibleBottom = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    return Math.round(edge - visibleBottom);
  }

  function appliedKbPx() {
    return Math.max(0, Math.round(svhPx() - document.body.getBoundingClientRect().height));
  }

  function rememberStick() {
    stickOnOpen = threadNearBottom();
  }

  function stickIfOpen() {
    if (stickOnOpen) stickThread();
  }

  function clampKbCss() {
    return "clamp(12rem, 50svh, 28rem)";
  }

  function clampKbPx() {
    var root = rootPx();
    var preferred = svhPx() * 0.5;
    return Math.round(Math.max(root * 12, Math.min(root * 28, preferred)));
  }

  function raiseFloor(px) {
    if (px > kbFloor) kbFloor = px;
  }

  function setKb(value) {
    document.body.style.setProperty("--sapp-kb", value);
    stickIfOpen();
  }

  function clearKb() {
    document.body.style.removeProperty("--sapp-kb");
  }

  function bumpOnce() {
    if (kbBumped || !fieldFocused()) return;
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        if (kbBumped || !fieldFocused()) return;
        kbBumped = true;
        var extra = composerOverlap();
        var root = rootPx();
        if (extra <= root * 0.5) return;
        var next = Math.max(appliedKbPx(), kbFloor) + extra;
        raiseFloor(next);
        setKb("calc(" + Math.round(next) + "px)");
      });
    });
  }

  function lockMeasured(px) {
    kbState = "locked";
    var root = rootPx();
    var next = px + root * 3;
    if (kbFloor > 0 && next < kbFloor - root * 4) {
      bumpOnce();
      return;
    }
    raiseFloor(next);
    setKb("calc(" + Math.round(px) + "px + 3rem)");
    bumpOnce();
  }

  function finishKeyboard() {
    kbTimer = 0;
    if (!fieldFocused() || kbState === "locked") return;
    var covered = Math.max(keyboardCovered(), maxCovered);
    var overlap = composerOverlap();
    var root = rootPx();
    var real = root * 8;
    if (covered >= real) {
      lockMeasured(covered);
      return;
    }
    if (svhDropped()) {
      kbState = "locked";
      kbFloor = root * 3;
      setKb("3rem");
      bumpOnce();
      return;
    }
    if (overlap >= real) {
      lockMeasured(overlap);
      return;
    }
    if (phoneLike()) {
      kbState = "locked";
      raiseFloor(clampKbPx());
      setKb(clampKbCss());
      bumpOnce();
      return;
    }
    kbState = "locked";
    if (overlap > root * 0.5) {
      raiseFloor(overlap + root * 3);
      setKb("calc(" + Math.round(overlap) + "px + 3rem)");
      bumpOnce();
      return;
    }
    setKb("0px");
  }

  function maybeRaise() {
    if (kbRaised || !fieldFocused()) return;
    var covered = keyboardCovered();
    var root = rootPx();
    if (covered < root * 8) return;
    var next = covered + root * 3;
    var floor = Math.max(appliedKbPx(), kbFloor);
    if (next <= floor + root) return;
    kbRaised = true;
    raiseFloor(next);
    maxCovered = covered;
    setKb("calc(" + Math.round(covered) + "px + 3rem)");
  }

  function syncKeyboard() {
    if (!fieldFocused()) return;
    document.body.classList.add("is-typing");
    if (kbState === "locked") {
      maybeRaise();
      return;
    }
    var covered = keyboardCovered();
    if (covered > maxCovered) maxCovered = covered;
    if (kbState !== "pending") {
      kbState = "pending";
      kbTimer = window.setTimeout(finishKeyboard, 400);
    }
  }

  function resetKeyboard() {
    if (fieldFocused()) return;
    window.clearTimeout(kbTimer);
    kbTimer = 0;
    kbState = "idle";
    kbBumped = false;
    kbRaised = false;
    kbFloor = 0;
    maxCovered = 0;
    document.body.classList.remove("is-typing");
    clearKb();
    document.body.style.top = "";
    document.body.style.height = "";
    noteBase();
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", syncKeyboard);
    window.visualViewport.addEventListener("scroll", syncKeyboard);
  }
  if (typeField) {
    typeField.addEventListener("focus", function () {
      rememberStick();
      document.body.classList.add("is-typing");
      if (phoneLike() && kbState !== "locked") {
        raiseFloor(clampKbPx());
        setKb(clampKbCss());
      }
      window.requestAnimationFrame(function () { stickIfOpen(); });
      syncKeyboard();
    });
    typeField.addEventListener("blur", function () {
      window.setTimeout(function () {
        resetKeyboard();
        pullLive();
      }, 180);
    });
  }
  window.addEventListener("resize", function () {
    if (fieldFocused()) syncKeyboard();
    else noteBase();
  });
  noteBase();
  syncKeyboard();

  if (window.location.hash) {
    var target = document.getElementById(window.location.hash.slice(1));
    if (target && thread) thread.scrollTop = target.offsetTop;
  } else {
    stickThread();
  }
})();
