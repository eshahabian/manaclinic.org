(function () {
  var configEl = document.getElementById("sapp-chat-config");
  var thread = document.getElementById("sapp-thread");
  if (!configEl || !thread) return;
  var config = {};
  try { config = JSON.parse(configEl.textContent || "{}"); } catch (err) { return; }
  var observeOnly = !!config.observeOnly;
  if (observeOnly) {
    var composeLock = document.querySelector(".sapp-compose");
    if (composeLock) composeLock.hidden = true;
    var emojiLock = document.querySelector(".sapp-hold-emojis");
    if (emojiLock) emojiLock.hidden = true;
    ["reply", "pin", "forward", "delete", "select"].forEach(function (act) {
      var node = document.querySelector('.sapp-hold-menu [data-act="' + act + '"]');
      if (node) node.hidden = true;
    });
    var selectDeleteLock = document.getElementById("sapp-select-delete");
    if (selectDeleteLock) selectDeleteLock.hidden = true;
  }

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
    var editBtn = menu.querySelector('[data-act="edit"]');
    var pin = menu.querySelector('[data-act="pin"]');
    var ownMessage = article.getAttribute("data-mine") === "1";
    var ownText = ownMessage && String(article.getAttribute("data-body") || "").trim() !== "";
    if (save) save.hidden = files.length === 0;
    if (remove) remove.hidden = !ownMessage;
    if (editBtn) editBtn.hidden = !ownText;
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
    if (target && target.closest && target.closest("audio")) return;
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
    if (target && target.closest && target.closest("audio")) return;
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
      if (observeOnly || !current) return;
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
    if (observeOnly && (act === "reply" || act === "pin" || act === "forward" || act === "delete" || act === "select" || act === "edit")) return;
    if (act === "edit") {
      if (article.getAttribute("data-mine") !== "1") return;
      var source = String(messageText(article) || "").trim();
      if (!source) return;
      var editBox = document.getElementById("sapp-edit");
      var editId = document.getElementById("sapp-edit-id");
      var editSnippet = document.getElementById("sapp-edit-snippet");
      var field = document.getElementById("chat-body");
      if (editId) editId.value = id;
      if (editSnippet) editSnippet.textContent = source.length > 80 ? source.slice(0, 80) + "…" : source;
      if (editBox) editBox.hidden = false;
      if (replyBox) replyBox.hidden = true;
      var replyInput = document.getElementById("sapp-reply-id");
      if (replyInput) replyInput.value = "";
      if (field) {
        field.value = source;
        field.focus();
      }
      closeMenu();
      return;
    }
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
      if (article.getAttribute("data-mine") !== "1") return;
      if (!window.confirm("این پیام حذف شود؟")) return;
      closeMenu();
      postAction({ form: "delete", message_id: id }).then(function () {
        dropMessage(id);
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

  var editX = document.getElementById("sapp-edit-x");
  if (editX) editX.addEventListener("click", function () {
    var editId = document.getElementById("sapp-edit-id");
    var editBox = document.getElementById("sapp-edit");
    var field = document.getElementById("chat-body");
    if (editId) editId.value = "";
    if (editBox) editBox.hidden = true;
    if (field) field.value = "";
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
    if (observeOnly) return;
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
      ids.forEach(dropMessage);
      thread.classList.remove("is-selecting");
      if (selectBar) selectBar.hidden = true;
    }).catch(function (err) {
      window.alert(err.message || "انجام نشد.");
    });
  });
  } catch (err) {}

  function isAudioMime(mime) {
    mime = String(mime || "").toLowerCase().split(";")[0].trim();
    return mime.indexOf("audio/") === 0 || mime === "video/webm" || mime === "application/ogg" || mime === "video/ogg";
  }

  function messageHasAudio(msg) {
    var files = msg && msg.files ? msg.files : [];
    var i;
    for (i = 0; i < files.length; i++) {
      if (isAudioMime(files[i] && files[i].mime)) return true;
    }
    return false;
  }

  function appendFileNode(parent, file) {
    var href = String((file && file.url) || "");
    var mime = file && file.mime ? file.mime : "";
    if (!href) {
      var pendingName = document.createElement("p");
      pendingName.textContent = isAudioMime(mime) ? "وویس" : ((file && file.name) || "فایل");
      parent.appendChild(pendingName);
      return;
    }
    if (String(mime).indexOf("image/") === 0) {
      var link = document.createElement("a");
      link.href = href;
      var image = document.createElement("img");
      image.src = href;
      image.alt = (file && file.name) || "فایل";
      link.appendChild(image);
      parent.appendChild(link);
      return;
    }
    if (isAudioMime(mime)) {
      var audio = document.createElement("audio");
      audio.className = "sapp-audio";
      audio.controls = true;
      audio.preload = "none";
      audio.setAttribute("aria-label", "پیام صوتی");
      audio.src = href;
      parent.appendChild(audio);
      return;
    }
    var line = document.createElement("p");
    var fileLink = document.createElement("a");
    fileLink.href = href;
    fileLink.textContent = (file && file.name) || "فایل";
    line.appendChild(fileLink);
    parent.appendChild(line);
  }

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
      body.className = "sapp-msg-body";
      body.textContent = msg.body;
      article.appendChild(body);
    }
    (msg.files || []).forEach(function (file) {
      appendFileNode(article, file);
    });
    var reacts = document.createElement("div");
    reacts.className = "sapp-reacts";
    reacts.setAttribute("data-mine", "");
    article.appendChild(reacts);
    var meta = document.createElement("div");
    meta.className = "sapp-msg-meta";
    if (msg.edited) {
      var edited = document.createElement("span");
      edited.className = "sapp-edited";
      edited.textContent = "ویرایش شد";
      meta.appendChild(edited);
    }
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

  function dropMessage(id) {
    if (!id || !thread) return;
    var article = document.getElementById("m-" + id);
    if (article) article.remove();
    thread.querySelectorAll('a.sapp-quote[href="#m-' + id + '"] span').forEach(function (span) {
      span.textContent = "پیام حذف‌شده";
    });
    var pin = document.querySelector('a.sapp-pin[href="#m-' + id + '"]');
    if (pin) pin.remove();
  }

  function applyEdits(list) {
    if (!thread || !list) return;
    list.forEach(function (msg) {
      if (!msg || !msg.id) return;
      var article = document.getElementById("m-" + msg.id);
      if (!article) return;
      var body = msg.body || "";
      article.setAttribute("data-body", body);
      var paragraph = article.querySelector(".sapp-msg-body");
      if (!paragraph && body) {
        paragraph = document.createElement("p");
        paragraph.className = "sapp-msg-body";
        var reacts = article.querySelector(".sapp-reacts");
        if (reacts) article.insertBefore(paragraph, reacts);
        else article.appendChild(paragraph);
      }
      if (paragraph) paragraph.textContent = body;
      if (msg.edited) {
        var meta = article.querySelector(".sapp-msg-meta");
        if (meta && !meta.querySelector(".sapp-edited")) {
          var label = document.createElement("span");
          label.className = "sapp-edited";
          label.textContent = "ویرایش شد";
          meta.insertBefore(label, meta.firstChild);
        }
      }
      var snippet = String(body).replace(/\s+/g, " ").trim();
      if (snippet.length > 80) snippet = snippet.slice(0, 80) + "…";
      thread.querySelectorAll('a.sapp-quote[href="#m-' + msg.id + '"] span').forEach(function (span) {
        span.textContent = snippet || "پیام";
      });
    });
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
        var noteText = msg.body ? String(msg.body) : (messageHasAudio(msg) ? "پیام صوتی" : "فایل تازه");
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
        (data.removed || []).forEach(dropMessage);
        appendMessages(data.messages || []);
        applyEdits(data.edits || []);
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
  if (compose && !observeOnly) {
    var voiceBlob = null;
    var voiceMime = "";
    var voiceUrl = "";
    var recorder = null;
    var recordStream = null;
    var recordChunks = [];
    var recording = false;
    var arming = false;
    var discardRecord = false;
    var sendAfterStop = false;
    var recordTimer = 0;
    var recordStarted = 0;
    var voiceSending = false;
    var maxRecordMs = 5 * 60 * 1000;
    var holding = false;
    var locked = false;
    var dropStream = false;
    var tooShort = false;
    var slideCancel = false;
    var sendWhenReady = false;
    var pointerId = null;
    var originX = 0;
    var originY = 0;
    var gestureStart = 0;
    var paused = false;
    var stopIntent = "";
    var elapsedBase = 0;
    var segmentStart = 0;
    var pickedMime = "";
    var previewAudio = null;

    function voiceExt(mime) {
      mime = String(mime || "").toLowerCase();
      if (mime.indexOf("mp4") >= 0 || mime.indexOf("m4a") >= 0 || mime.indexOf("aac") >= 0) return "m4a";
      if (mime.indexOf("ogg") >= 0 || mime.indexOf("opus") >= 0) return "ogg";
      if (mime.indexOf("mpeg") >= 0 || mime.indexOf("mp3") >= 0) return "mp3";
      return "webm";
    }

    function pickRecordMime() {
      var types = ["audio/webm;codecs=opus", "audio/webm", "audio/mp4", "audio/ogg;codecs=opus", "audio/ogg"];
      if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return "";
      var i;
      for (i = 0; i < types.length; i++) {
        if (MediaRecorder.isTypeSupported(types[i])) return types[i];
      }
      return "";
    }

    function pad2(n) {
      return (n < 10 ? "0" : "") + n;
    }

    function formatClock(ms) {
      var csTotal = Math.max(0, Math.floor(ms / 10));
      var cs = csTotal % 100;
      var totalSec = Math.floor(csTotal / 100);
      var s = totalSec % 60;
      var m = Math.floor(totalSec / 60);
      return faDigits(m + ":" + pad2(s) + "," + pad2(cs));
    }

    function formatDuration(ms) {
      var total = Math.max(0, Math.round(ms / 1000));
      var s = total % 60;
      var m = Math.floor(total / 60);
      return faDigits(m + ":" + pad2(s));
    }

    function currentElapsed() {
      return elapsedBase + (segmentStart ? Date.now() - segmentStart : 0);
    }

    function beginSegment() {
      segmentStart = Date.now();
    }

    function freezeSegment() {
      if (!segmentStart) return;
      elapsedBase += Date.now() - segmentStart;
      segmentStart = 0;
    }

    function showHint(text) {
      var el = document.getElementById("sapp-voice-hint");
      if (!el) return;
      el.textContent = text;
      el.hidden = false;
      clearTimeout(showHint.timer);
      showHint.timer = setTimeout(function () { el.hidden = true; }, 2200);
    }

    function setHoldScroll(on) {
      document.body.classList.toggle("sapp-voice-hold", !!on);
    }

    function showSlideHint(on) {
      var el = document.getElementById("sapp-voice-hint");
      if (!el) return;
      if (!on) {
        if (el.getAttribute("data-slide") === "1") el.hidden = true;
        el.removeAttribute("data-slide");
        return;
      }
      clearTimeout(showHint.timer);
      el.setAttribute("data-slide", "1");
      el.textContent = "به بالا بکشید تا قفل شود";
      el.hidden = false;
    }

    function paintVoice(mode) {
      var row = document.getElementById("sapp-compose-row");
      var rec = document.getElementById("sapp-voice-rec");
      var liveBox = document.getElementById("sapp-voice-live");
      var pausedBox = document.getElementById("sapp-voice-paused");
      var btn = document.getElementById("sapp-voice");
      var active = mode === "recording" || mode === "locked" || mode === "paused" || mode === "ready";
      var showPaused = mode === "paused" || mode === "ready";
      if (row) row.classList.toggle("is-recording", active);
      if (rec) rec.hidden = !active;
      if (liveBox) liveBox.hidden = !active || showPaused;
      if (pausedBox) pausedBox.hidden = !showPaused;
      if (!active) {
        var timeEl = document.getElementById("sapp-voice-time");
        if (timeEl) timeEl.textContent = faDigits("0:00,00");
      }
      if (showPaused) {
        var dur = document.getElementById("sapp-voice-dur");
        if (dur) dur.textContent = formatDuration(currentElapsed());
      }
      showSlideHint(mode === "recording" && holding && !locked);
      if (btn) btn.setAttribute("aria-pressed", active ? "true" : "false");
    }

    function releaseVoiceUrl() {
      if (!voiceUrl) return;
      try { URL.revokeObjectURL(voiceUrl); } catch (err) {}
      voiceUrl = "";
    }

    function stopPreview() {
      if (previewAudio) {
        try { previewAudio.pause(); } catch (err) {}
      }
      var playBtn = document.getElementById("sapp-voice-play");
      var wave = document.getElementById("sapp-voice-wave");
      if (playBtn) {
        playBtn.classList.remove("is-on");
        playBtn.setAttribute("aria-pressed", "false");
        playBtn.setAttribute("aria-label", "پخش");
      }
      if (wave) wave.classList.remove("is-on");
    }

    function clearVoiceHold() {
      stopPreview();
      releaseVoiceUrl();
      voiceBlob = null;
      voiceMime = "";
      recordChunks = [];
      elapsedBase = 0;
      segmentStart = 0;
      paused = false;
      locked = false;
      holding = false;
      showSlideHint(false);
      paintVoice("idle");
    }

    function refreshPreviewUrl() {
      releaseVoiceUrl();
      if (!voiceBlob) return;
      try { voiceUrl = URL.createObjectURL(voiceBlob); } catch (err) { voiceUrl = ""; }
      if (previewAudio) {
        try { previewAudio.pause(); } catch (err) {}
        previewAudio = null;
      }
      stopPreview();
    }

    function snapshotChunks() {
      var type = pickedMime || (recorder && recorder.mimeType) || voiceMime || "audio/webm";
      if (recordChunks.length) voiceBlob = new Blob(recordChunks, { type: type });
      voiceMime = String(type).split(";")[0] || "audio/webm";
      refreshPreviewUrl();
      var dur = document.getElementById("sapp-voice-dur");
      if (dur) dur.textContent = formatDuration(currentElapsed());
    }

    function muteStream(muted) {
      if (!recordStream) return;
      recordStream.getAudioTracks().forEach(function (track) { track.enabled = !muted; });
    }

    function openRecorder(stream) {
      var mime = pickRecordMime();
      if (mime) pickedMime = mime;
      var rec = null;
      try {
        rec = new MediaRecorder(stream, mime ? { mimeType: mime, audioBitsPerSecond: 48000 } : { audioBitsPerSecond: 48000 });
      } catch (err) {
        try {
          rec = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
        } catch (err2) {
          return null;
        }
      }
      rec.ondataavailable = function (event) {
        if (event.data && event.data.size > 0) recordChunks.push(event.data);
        if (paused) snapshotChunks();
      };
      rec.onstop = function () {
        var type = rec.mimeType || pickedMime || "audio/webm";
        var blob = new Blob(recordChunks, { type: type });
        var intent = stopIntent || (discardRecord ? "discard" : (sendAfterStop ? "send" : ""));
        stopIntent = "";
        if (recorder === rec) recorder = null;
        recording = false;
        if (intent === "pause") {
          paused = true;
          locked = true;
          voiceBlob = blob;
          voiceMime = String(type).split(";")[0] || "audio/webm";
          refreshPreviewUrl();
          freezeSegment();
          muteStream(true);
          paintVoice("paused");
          return;
        }
        stopTracks();
        if (intent === "discard") {
          discardRecord = false;
          sendAfterStop = false;
          clearVoiceHold();
          return;
        }
        if (!blob.size || blob.size < 500 || currentElapsed() < 400) {
          clearVoiceHold();
          showHint("برای ضبط، دکمه را نگه دارید");
          return;
        }
        voiceBlob = blob;
        voiceMime = String(type).split(";")[0] || "audio/webm";
        refreshPreviewUrl();
        sendAfterStop = false;
        submitCompose();
      };
      return rec;
    }

    function stopTracks() {
      if (!recordStream) return;
      recordStream.getTracks().forEach(function (track) {
        try { track.stop(); } catch (err) {}
      });
      recordStream = null;
    }

    function tickRecord() {
      var timeEl = document.getElementById("sapp-voice-time");
      var elapsed = currentElapsed();
      if (timeEl) timeEl.textContent = formatClock(elapsed);
      if (elapsed >= maxRecordMs) requestSend();
    }

    function armTicks() {
      if (recordTimer) return;
      recordTimer = setInterval(tickRecord, 50);
    }

    function stopTicks() {
      if (!recordTimer) return;
      clearInterval(recordTimer);
      recordTimer = 0;
    }

    function requestDiscard() {
      stopPreview();
      sendAfterStop = false;
      sendWhenReady = false;
      holding = false;
      locked = false;
      setHoldScroll(false);
      showSlideHint(false);
      stopIntent = "discard";
      discardRecord = true;
      dropStream = true;
      stopTicks();
      if (recorder && recorder.state !== "inactive") {
        try { recorder.stop(); return; } catch (err) {}
      }
      stopTracks();
      clearVoiceHold();
    }

    function requestSend() {
      if (voiceSending) return;
      holding = false;
      setHoldScroll(false);
      showSlideHint(false);
      stopTicks();
      freezeSegment();
      sendAfterStop = true;
      stopIntent = "send";
      if (recorder && recorder.state !== "inactive") {
        try { recorder.stop(); return; } catch (err) {}
      }
      if (arming) {
        sendWhenReady = true;
        return;
      }
      if (voiceBlob) submitCompose();
      else clearVoiceHold();
    }

    function pauseTake() {
      if (voiceSending) return;
      freezeSegment();
      stopTicks();
      holding = false;
      setHoldScroll(false);
      showSlideHint(false);
      if (recorder && recorder.state === "recording" && typeof recorder.pause === "function") {
        try {
          recorder.requestData();
          recorder.pause();
          muteStream(true);
          paused = true;
          recording = false;
          locked = true;
          window.setTimeout(snapshotChunks, 60);
          paintVoice("paused");
          return;
        } catch (err) {}
      }
      stopIntent = "pause";
      if (recorder && recorder.state === "recording") {
        try { recorder.stop(); return; } catch (err) {}
      }
      snapshotChunks();
      paused = true;
      locked = true;
      paintVoice("paused");
    }

    function resumeTake() {
      stopPreview();
      muteStream(false);
      if (!recordStream) {
        locked = true;
        paused = false;
        ensureRecording();
        return;
      }
      if (recorder && recorder.state === "paused" && typeof recorder.resume === "function") {
        try {
          recorder.resume();
          paused = false;
          recording = true;
          locked = true;
          beginSegment();
          armTicks();
          paintVoice("locked");
          return;
        } catch (err) {}
      }
      var next = openRecorder(recordStream);
      if (!next) {
        window.alert("ضبط شروع نشد.");
        return;
      }
      recorder = next;
      try { recorder.start(250); } catch (err) {
        window.alert("ضبط شروع نشد.");
        return;
      }
      paused = false;
      recording = true;
      locked = true;
      beginSegment();
      armTicks();
      paintVoice("locked");
    }

    function ensureRecording() {
      if (recording || voiceSending || arming) return true;
      if (paused && recordStream) {
        resumeTake();
        return true;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        window.alert("این مرورگر ضبط صدا را پشتیبانی نمی‌کند.");
        return false;
      }
      discardRecord = false;
      sendAfterStop = false;
      arming = true;
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        arming = false;
        if (discardRecord || dropStream || (!holding && !locked && !sendWhenReady)) {
          stream.getTracks().forEach(function (track) {
            try { track.stop(); } catch (err) {}
          });
          var showShort = tooShort;
          discardRecord = false;
          dropStream = false;
          tooShort = false;
          sendWhenReady = false;
          clearVoiceHold();
          if (showShort) showHint("برای ضبط، دکمه را نگه دارید");
          return;
        }
        recordStream = stream;
        recorder = openRecorder(stream);
        if (!recorder) {
          stopTracks();
          clearVoiceHold();
          window.alert("این مرورگر ضبط صدا را پشتیبانی نمی‌کند.");
          return;
        }
        try { recorder.start(250); } catch (err) {
          stopTracks();
          clearVoiceHold();
          window.alert("ضبط شروع نشد.");
          return;
        }
        recording = true;
        beginSegment();
        armTicks();
        tickRecord();
        if (sendWhenReady && !holding) {
          sendWhenReady = false;
          requestSend();
          return;
        }
        paintVoice(locked ? "locked" : "recording");
      }).catch(function () {
        arming = false;
        holding = false;
        locked = false;
        setHoldScroll(false);
        var denied = !tooShort && !dropStream;
        clearVoiceHold();
        if (denied) window.alert("دسترسی میکروفون داده نشد.");
        tooShort = false;
        dropStream = false;
      });
      return true;
    }

    function submitCompose() {
      if (voiceSending || recording) return;
      var field = document.getElementById("chat-body");
      var file = document.getElementById("chat-file");
      var text = field ? field.value : "";
      var blob = voiceBlob;
      var hasFile = !blob && file && file.files && file.files.length > 0;
      var editIdEl = document.getElementById("sapp-edit-id");
      var editingId = editIdEl ? String(editIdEl.value || "") : "";
      if (editingId && !blob && !hasFile) {
        var nextBody = String(text).trim();
        if (!nextBody) return;
        voiceSending = true;
        postAction({ form: "edit", message_id: editingId, body: nextBody }).then(function (data) {
          voiceSending = false;
          applyEdits([data.message || { id: editingId, body: nextBody, edited: true }]);
          if (field) field.value = "";
          if (editIdEl) editIdEl.value = "";
          var editBox = document.getElementById("sapp-edit");
          if (editBox) editBox.hidden = true;
        }).catch(function (err) {
          voiceSending = false;
          window.alert(err.message || "ویرایش نشد.");
        });
        return;
      }
      if (!String(text).trim() && !hasFile && !blob) return;
      voiceSending = true;
      window.sappSentAt = Date.now();
      var body = new FormData(compose);
      body.set("ajax", "1");
      var preview = "";
      var base = "";
      if (blob) {
        base = String(voiceMime || blob.type || "audio/webm").split(";")[0] || "audio/webm";
        body.delete("file");
        body.append("file", blob, "voice." + voiceExt(base));
        body.set("voice", "1");
        preview = voiceUrl;
        voiceBlob = null;
        voiceUrl = "";
        paintVoice("idle");
      }
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
        files: blob ? [{ url: preview, name: "وویس", mime: base }] : (hasFile ? [{ url: "", name: file.files[0].name || "فایل", mime: "" }] : [])
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
        voiceSending = false;
        if (preview) {
          try { URL.revokeObjectURL(preview); } catch (err) {}
        }
        var temp = document.getElementById("m-" + tempId);
        if (temp) temp.remove();
        if (data.message) appendMessages([data.message]);
        stickThread();
      }).catch(function (err) {
        voiceSending = false;
        window.sappSentAt = 0;
        var temp = document.getElementById("m-" + tempId);
        if (temp) temp.classList.remove("is-pending");
        if (field && !field.value) field.value = text;
        if (blob) {
          voiceBlob = blob;
          voiceMime = base;
          voiceUrl = preview;
          locked = true;
          paused = true;
          paintVoice("paused");
          showHint("ارسال نشد");
        }
        window.alert(err.message || "پیام فرستاده نشد.");
      });
    }

    compose.addEventListener("submit", function (event) {
      event.preventDefault();
      if (recording || paused || (recorder && recorder.state && recorder.state !== "inactive")) {
        requestSend();
        return;
      }
      submitCompose();
    });

    var voiceBtn = document.getElementById("sapp-voice");
    function bindTap(id, fn) {
      var node = document.getElementById(id);
      if (!node) return;
      node.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        fn();
      });
    }
    bindTap("sapp-voice-up", requestSend);
    bindTap("sapp-voice-plane", requestSend);
    bindTap("sapp-voice-pause", pauseTake);
    bindTap("sapp-voice-resume", resumeTake);
    bindTap("sapp-voice-cancel", requestDiscard);
    bindTap("sapp-voice-trash", requestDiscard);
    bindTap("sapp-voice-play", function () {
      snapshotChunks();
      if (!voiceUrl) return;
      if (!previewAudio || previewAudio.getAttribute("data-src") !== voiceUrl) {
        if (previewAudio) {
          try { previewAudio.pause(); } catch (err) {}
        }
        previewAudio = new Audio(voiceUrl);
        previewAudio.setAttribute("data-src", voiceUrl);
        previewAudio.addEventListener("ended", stopPreview);
      }
      if (previewAudio.paused) {
        previewAudio.play().then(function () {
          var playBtn = document.getElementById("sapp-voice-play");
          var wave = document.getElementById("sapp-voice-wave");
          if (playBtn) {
            playBtn.classList.add("is-on");
            playBtn.setAttribute("aria-pressed", "true");
            playBtn.setAttribute("aria-label", "مکث پخش");
          }
          if (wave) wave.classList.add("is-on");
        }).catch(function () {});
        return;
      }
      stopPreview();
    });
    var waveBox = document.getElementById("sapp-voice-wave");
    if (waveBox && !waveBox.childNodes.length) {
      [30, 55, 80, 45, 95, 60, 40, 75, 50, 88, 35, 70, 48, 92, 58, 42, 78, 52, 66, 38].forEach(function (height, index) {
        var bar = document.createElement("i");
        bar.style.height = height + "%";
        bar.style.animationDelay = ((index % 6) * 0.08) + "s";
        waveBox.appendChild(bar);
      });
    }
    if (voiceBtn) {
      voiceBtn.addEventListener("contextmenu", function (event) { event.preventDefault(); });
      voiceBtn.addEventListener("pointerdown", function (event) {
        if (event.pointerType === "mouse" && event.button !== 0) return;
        if (voiceSending || holding || locked || recording || paused || arming) return;
        event.preventDefault();
        holding = true;
        locked = false;
        slideCancel = false;
        dropStream = false;
        tooShort = false;
        sendWhenReady = false;
        pointerId = event.pointerId;
        originX = event.clientX;
        originY = event.clientY;
        gestureStart = Date.now();
        try { voiceBtn.setPointerCapture(event.pointerId); } catch (err) {}
        setHoldScroll(true);
        recordChunks = [];
        elapsedBase = 0;
        segmentStart = 0;
        paused = false;
        voiceBlob = null;
        releaseVoiceUrl();
        paintVoice("recording");
        showSlideHint(true);
        if (!ensureRecording()) {
          holding = false;
          setHoldScroll(false);
          paintVoice("idle");
        }
      }, { passive: false });
      voiceBtn.addEventListener("pointermove", function (event) {
        if (!holding || locked || event.pointerId !== pointerId) return;
        event.preventDefault();
        var dx = originX - event.clientX;
        var dy = originY - event.clientY;
        if (dy > 64) {
          locked = true;
          slideCancel = false;
          setHoldScroll(false);
          paintVoice("locked");
          showSlideHint(false);
          return;
        }
        slideCancel = dx > 80;
      }, { passive: false });
      voiceBtn.addEventListener("pointerup", function (event) {
        if (pointerId !== null && event.pointerId !== pointerId) return;
        if (!holding) return;
        var elapsed = Date.now() - gestureStart;
        holding = false;
        setHoldScroll(false);
        try { voiceBtn.releasePointerCapture(pointerId); } catch (err) {}
        pointerId = null;
        if (locked) return;
        if (slideCancel || elapsed < 450) {
          tooShort = elapsed < 450 && !slideCancel;
          dropStream = true;
          requestDiscard();
          if (tooShort) showHint("برای ضبط، دکمه را نگه دارید");
          return;
        }
        if (!recording) {
          dropStream = true;
          paintVoice("idle");
          return;
        }
        requestSend();
      });
      voiceBtn.addEventListener("pointercancel", function (event) {
        if (!holding) return;
        if (locked) {
          holding = false;
          setHoldScroll(false);
          return;
        }
        slideCancel = true;
        tooShort = false;
        dropStream = true;
        holding = false;
        setHoldScroll(false);
        try { if (pointerId !== null) voiceBtn.releasePointerCapture(pointerId); } catch (err) {}
        pointerId = null;
        requestDiscard();
      });
    }
    window.addEventListener("pagehide", function () {
      requestDiscard();
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
  var kbFrame = 0;

  function stickThread() {
    if (thread) thread.scrollTop = thread.scrollHeight;
  }

  function fieldFocused() {
    return !!(typeField && document.activeElement === typeField);
  }

  function keyboardOverlap() {
    var vv = window.visualViewport;
    if (!vv) return 0;
    var layout = window.innerHeight || document.documentElement.clientHeight || 0;
    var offset = vv.offsetTop > 0 ? vv.offsetTop : 0;
    return Math.max(0, Math.round(layout - vv.height - offset));
  }

  function applyKeyboard() {
    var vv = window.visualViewport;
    var focused = fieldFocused();
    if (!focused) {
      document.body.classList.remove("is-typing");
      document.body.style.removeProperty("--sapp-kb");
      document.body.style.removeProperty("--sapp-vv-top");
      document.body.style.top = "";
      document.body.style.height = "";
      document.body.style.maxHeight = "";
      return;
    }
    document.body.classList.add("is-typing");
    var overlap = keyboardOverlap();
    var offset = vv && vv.offsetTop > 0 ? Math.round(vv.offsetTop) : 0;
    var visible = vv ? Math.round(vv.height) : 0;
    document.body.style.setProperty("--sapp-kb", overlap + "px");
    document.body.style.setProperty("--sapp-vv-top", offset + "px");
    if (visible > 0) {
      document.body.style.top = offset + "px";
      document.body.style.height = visible + "px";
      document.body.style.maxHeight = visible + "px";
    }
    if (thread && threadNearBottom()) stickThread();
  }

  function scheduleKeyboard() {
    if (kbFrame) return;
    kbFrame = window.requestAnimationFrame(function () {
      kbFrame = 0;
      applyKeyboard();
    });
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", scheduleKeyboard);
    window.visualViewport.addEventListener("scroll", scheduleKeyboard);
  }
  if (typeField) {
    typeField.addEventListener("focus", function () {
      document.body.classList.add("is-typing");
      scheduleKeyboard();
      window.setTimeout(scheduleKeyboard, 50);
      window.setTimeout(scheduleKeyboard, 280);
    });
    typeField.addEventListener("blur", function () {
      window.setTimeout(function () {
        if (fieldFocused()) return;
        applyKeyboard();
        pullLive();
      }, 120);
    });
  }
  window.addEventListener("resize", scheduleKeyboard);

  if (window.location.hash) {
    var target = document.getElementById(window.location.hash.slice(1));
    if (target && thread) thread.scrollTop = target.offsetTop;
  } else {
    stickThread();
  }
})();
