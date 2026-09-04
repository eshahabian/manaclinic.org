(function () {
  var cfg = window.__ASSISTANT__ || {};
  var chatUrl = cfg.chatUrl || "";
  var sendUrl = cfg.sendUrl || "";
  var reportBase = cfg.reportBase || "";
  var loginUrl = cfg.loginUrl || "";
  var registerUrl = cfg.registerUrl || "";
  var resumeSession = cfg.resumeSession || "";
  var loggedIn = !!cfg.loggedIn;
  var preferAi = !!cfg.aiEnabled;

  var messagesEl = document.getElementById("assistant-messages");
  var controlsEl = document.getElementById("assistant-controls");
  var resultsEl = document.getElementById("assistant-results");
  if (!messagesEl || !controlsEl || !resultsEl) return;

  var sessionId = resumeSession || "";
  var busy = false;
  var selectedDoctorId = "";
  var mode = preferAi ? "ai" : "guided";
  var canComplete = false;

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s == null ? "" : String(s);
    return d.innerHTML;
  }

  function addMsg(role, text) {
    var div = document.createElement("div");
    div.className = "assistant-msg assistant-msg--" + role;
    div.innerHTML =
      '<div class="assistant-bubble">' + esc(text).replace(/\n/g, "<br>") + "</div>";
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function setBusy(v) {
    busy = v;
    controlsEl.querySelectorAll("button, textarea, input").forEach(function (el) {
      el.disabled = !!v;
    });
  }

  function postForm(url, data) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) {
      if (data[k] != null) body.append(k, data[k]);
    });
    return fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        Accept: "application/json",
      },
      body: body.toString(),
      credentials: "same-origin",
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error(j.error || "خطا در ارتباط");
        return j;
      });
    });
  }

  function renderAiComposer() {
    controlsEl.innerHTML = "";
    var wrap = document.createElement("div");
    wrap.className = "assistant-composer";

    var ta = document.createElement("textarea");
    ta.className = "input assistant-text";
    ta.rows = 3;
    ta.placeholder = "اینجا بنویسید… مثلاً این روزها اضطراب دارم";
    wrap.appendChild(ta);

    var row = document.createElement("div");
    row.className = "assistant-actions";

    var send = document.createElement("button");
    send.type = "button";
    send.className = "btn btn-primary";
    send.textContent = "ارسال";
    send.addEventListener("click", function () {
      var text = (ta.value || "").trim();
      if (!text) return;
      ta.value = "";
      sendAiMessage(text);
    });
    row.appendChild(send);

    var finish = document.createElement("button");
    finish.type = "button";
    finish.className = "btn btn-outline";
    finish.id = "assistant-finish-btn";
    finish.textContent = "پیشنهاد درمانگر";
    finish.disabled = !canComplete;
    finish.addEventListener("click", function () {
      if (busy || !sessionId) return;
      setBusy(true);
      postForm(chatUrl, { action: "complete", sessionId: sessionId })
        .then(handleChatResponse)
        .catch(function (err) {
          addMsg("bot", err.message || "خطا");
          setBusy(false);
        });
    });
    row.appendChild(finish);

    wrap.appendChild(row);
    controlsEl.appendChild(wrap);

    ta.addEventListener("keydown", function (e) {
      if (e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();
        send.click();
      }
    });
  }

  function sendAiMessage(text) {
    if (busy || !sessionId) return;
    addMsg("user", text);
    setBusy(true);
    postForm(chatUrl, { action: "message", sessionId: sessionId, text: text })
      .then(handleChatResponse)
      .catch(function (err) {
        addMsg("bot", err.message || "خطا");
        setBusy(false);
        renderAiComposer();
      });
  }

  function renderQuestion(q, step, total) {
    controlsEl.innerHTML = "";
    if (!q) return;
    var meta = document.createElement("p");
    meta.className = "assistant-step muted";
    meta.textContent = "سوال " + (step + 1) + " از " + total;
    controlsEl.appendChild(meta);

    if (q.type === "text") {
      var ta = document.createElement("textarea");
      ta.className = "input assistant-text";
      ta.rows = 3;
      ta.placeholder = q.placeholder || "پاسخ خود را بنویسید…";
      controlsEl.appendChild(ta);
      var row = document.createElement("div");
      row.className = "assistant-actions";
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn btn-primary";
      btn.textContent = q.optional ? "ادامه" : "ارسال";
      btn.addEventListener("click", function () {
        sendAnswer({ optionId: "", text: ta.value || "" });
      });
      row.appendChild(btn);
      if (q.optional) {
        var skip = document.createElement("button");
        skip.type = "button";
        skip.className = "btn btn-outline";
        skip.textContent = "رد کردن";
        skip.addEventListener("click", function () {
          sendAnswer({ optionId: "", text: "" });
        });
        row.appendChild(skip);
      }
      controlsEl.appendChild(row);
      return;
    }

    var grid = document.createElement("div");
    grid.className = "assistant-options";
    (q.options || []).forEach(function (opt) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "assistant-option";
      b.textContent = opt.label;
      b.addEventListener("click", function () {
        addMsg("user", opt.label);
        sendAnswer({ optionId: opt.id, text: "" });
      });
      grid.appendChild(b);
    });
    controlsEl.appendChild(grid);
  }

  function sendAnswer(payload) {
    if (busy || !sessionId) return;
    if (payload.text && payload.text.trim()) {
      addMsg("user", payload.text.trim());
    }
    setBusy(true);
    postForm(chatUrl, {
      action: "answer",
      sessionId: sessionId,
      optionId: payload.optionId || "",
      text: payload.text || "",
    })
      .then(handleChatResponse)
      .catch(function (err) {
        addMsg("bot", err.message || "خطا");
        setBusy(false);
      });
  }

  function renderResults(data) {
    controlsEl.innerHTML = "";
    resultsEl.hidden = false;
    selectedDoctorId = data.selectedDoctorId || "";
    var doctors = data.doctors || [];
    var workshops = data.workshops || [];
    var html = '<div class="assistant-match">';
    html += "<h2>پیشنهاد درمانگر (مرتبط)</h2>";
    if (!doctors.length) {
      html +=
        '<p class="muted">پیشنهاد دقیقی پیدا نشد — منشی پس از دریافت خلاصه، درمانگر مناسب را انتخاب می‌کند.</p>';
    } else {
      html += '<div class="assistant-doctor-list">';
      html +=
        '<label class="assistant-doctor-card"><input type="radio" name="doctorPick" value=""' +
        (!selectedDoctorId ? " checked" : "") +
        '><span><strong>بدون ترجیح</strong><br><span class="muted">منشی تصمیم بگیرد</span></span></label>';
      doctors.forEach(function (d) {
        var checked = selectedDoctorId === d.id ? " checked" : "";
        html +=
          '<label class="assistant-doctor-card">' +
          '<input type="radio" name="doctorPick" value="' +
          esc(d.id) +
          '"' +
          checked +
          ">" +
          "<span><strong>" +
          esc(d.name) +
          "</strong><br>" +
          '<span class="muted">' +
          esc(d.specialty || "") +
          "</span>" +
          (d.url
            ? ' · <a href="' + esc(d.url) + '" target="_blank" rel="noopener">پروفایل</a>'
            : "") +
          "</span></label>";
      });
      html += "</div>";
    }

    html += '<h2 style="margin-top:1.25rem">پیشنهاد کارگاه</h2>';
    if (!workshops.length) {
      html += '<p class="muted">کارگاه مرتبطی پیدا نشد.</p>';
    } else {
      html += '<ul class="assistant-workshop-list">';
      workshops.forEach(function (w) {
        html +=
          "<li><strong>" +
          esc(w.title) +
          "</strong> — " +
          esc(w.type_label || w.type) +
          " · " +
          esc(w.doctor_name || "") +
          (w.url ? ' · <a href="' + esc(w.url) + '">مشاهده دوره‌ها</a>' : "") +
          "</li>";
      });
      html += "</ul>";
    }

    html += '<div class="assistant-actions" style="margin-top:1.25rem">';
    if (data.status === "SENT") {
      html +=
        '<p class="flash flash-success" style="margin:0">خلاصه برای کلینیک ارسال شده؛ منشی ارجاع را انجام می‌دهد.</p>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">مشاهده / چاپ گزارش</a>';
    } else if (loggedIn || data.loggedIn) {
      html +=
        '<button type="button" class="btn btn-primary" id="assistant-send-btn">ارسال خلاصه به کلینیک</button>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">پیش‌نمایش چاپ</a>';
      html +=
        '<p class="muted" style="width:100%;margin:.35rem 0 0;font-size:.82rem">منشی خلاصه را می‌بیند و در صورت نیاز به درمانگر ارجاع می‌دهد. انتخاب درمانگر اختیاری است (ترجیح شما).</p>';
    } else {
      html +=
        '<p class="muted" style="width:100%;margin:0 0 .5rem">برای ارسال خلاصه به کلینیک، وارد شوید یا ثبت‌نام کنید.</p>';
      html +=
        '<a class="btn btn-primary" href="' +
        esc(data.loginUrl || loginUrl) +
        '">ورود</a>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(data.registerUrl || registerUrl) +
        '">ثبت‌نام</a>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">پیش‌نمایش چاپ</a>';
    }
    html += "</div></div>";
    resultsEl.innerHTML = html;

    resultsEl.querySelectorAll("input[name=doctorPick]").forEach(function (inp) {
      inp.addEventListener("change", function () {
        selectedDoctorId = inp.value || "";
      });
    });
    selectedDoctorId = "";
    var checked = resultsEl.querySelector("input[name=doctorPick]:checked");
    if (checked) selectedDoctorId = checked.value || "";
    var sendBtn = document.getElementById("assistant-send-btn");
    if (sendBtn) {
      sendBtn.addEventListener("click", function () {
        setBusy(true);
        postForm(sendUrl, {
          sessionId: sessionId,
          doctorId: selectedDoctorId || "",
        })
          .then(function (res) {
            addMsg("bot", res.message || "ارسال شد.");
            window.location.href =
              res.reportUrl || reportBase + "?session=" + encodeURIComponent(sessionId);
          })
          .catch(function (err) {
            if (err.message && err.message.indexOf("وارد") !== -1) {
              window.location.href = loginUrl;
              return;
            }
            addMsg("bot", err.message || "خطا در ارسال");
            setBusy(false);
          });
      });
    }
  }

  function handleChatResponse(data) {
    setBusy(false);
    if (data.sessionId) sessionId = data.sessionId;
    if (data.mode) mode = data.mode;
    if (typeof data.canComplete === "boolean") canComplete = data.canComplete;
    if (data.botMessage) addMsg("bot", data.botMessage);
    if (data.done) {
      renderResults(data);
      return;
    }
    if (mode === "ai") {
      renderAiComposer();
      return;
    }
    if (data.question) {
      addMsg("bot", data.question.text);
      renderQuestion(data.question, data.step || 0, data.total || 1);
    }
  }

  function startFresh() {
    setBusy(true);
    postForm(chatUrl, { action: "start" })
      .then(handleChatResponse)
      .catch(function (err) {
        addMsg("bot", err.message || "شروع گفتگو ممکن نشد.");
        setBusy(false);
      });
  }

  function resume() {
    setBusy(true);
    postForm(chatUrl, { action: "status", sessionId: sessionId })
      .then(function (data) {
        setBusy(false);
        if (data.mode) mode = data.mode;
        if (data.done) {
          addMsg(
            "bot",
            "گفتگوی قبلی شما آماده است. پیشنهادها را ببینید و در صورت تمایل شرح‌حال را ارسال کنید."
          );
          renderResults(data);
          return;
        }
        if (mode === "ai" && data.messages && data.messages.length) {
          data.messages.forEach(function (m) {
            addMsg(m.role === "assistant" ? "bot" : "user", m.content || "");
          });
          canComplete = (data.messages || []).filter(function (m) {
            return m.role === "user";
          }).length >= 2;
          renderAiComposer();
          return;
        }
        sessionId = "";
        startFresh();
      })
      .catch(function () {
        startFresh();
      });
  }

  if (resumeSession) resume();
  else startFresh();
})();
