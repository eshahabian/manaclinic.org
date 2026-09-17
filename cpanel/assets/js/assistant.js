(function () {
  var cfg = window.__ASSISTANT__ || {};
  var chatUrl = cfg.chatUrl || "";
  var sendUrl = cfg.sendUrl || "";
  var reportBase = cfg.reportBase || "";
  var loginUrl = cfg.loginUrl || "";
  var registerUrl = cfg.registerUrl || "";
  var resumeSession = cfg.resumeSession || "";
  var loggedIn = !!cfg.loggedIn;

  var messagesEl = document.getElementById("assistant-messages");
  var controlsEl = document.getElementById("assistant-controls");
  var resultsEl = document.getElementById("assistant-results");
  if (!messagesEl || !controlsEl || !resultsEl) return;

  var sessionId = resumeSession || "";
  var busy = false;
  var selectedDoctorId = "";
  var phase = "topic";
  var canComplete = false;
  var aiChat = false;
  var currentQuestions = [];
  var exploredIds = {};

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
      return r.text().then(function (text) {
        var j = null;
        var trimmed = (text || "").trim();
        if (trimmed) {
          try {
            j = JSON.parse(trimmed);
          } catch (e) {
            if (trimmed.charAt(0) === "<") {
              throw new Error(
                "سرور به‌جای پاسخ چت، صفحه HTML برگرداند. معمولاً تایم‌اوت یا خطای PHP است — دوباره پیام کوتاه‌تر بفرستید."
              );
            }
            throw new Error("پاسخ نامعتبر از سرور");
          }
        } else {
          j = {};
        }
        if (!r.ok) {
          throw new Error((j && j.error) || "خطا در ارتباط (کد " + r.status + ")");
        }
        if (j && j.error && !j.botMessage && !j.sessionId) {
          throw new Error(j.error);
        }
        return j;
      });
    });
  }

  function renderTopics(topics) {
    controlsEl.innerHTML = "";
    var hint = document.createElement("p");
    hint.className = "assistant-step muted";
    hint.textContent = "یک حوزه را انتخاب کنید";
    controlsEl.appendChild(hint);
    var grid = document.createElement("div");
    grid.className = "assistant-options assistant-options--topics";
    (topics || []).forEach(function (t) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "assistant-option";
      b.textContent = t.label;
      b.addEventListener("click", function () {
        if (busy || !sessionId) return;
        addMsg("user", t.label);
        setBusy(true);
        postForm(chatUrl, { action: "select_topic", sessionId: sessionId, topicId: t.id })
          .then(handleChatResponse)
          .catch(function (err) {
            addMsg("bot", err.message || "خطا");
            setBusy(false);
          });
      });
      grid.appendChild(b);
    });
    controlsEl.appendChild(grid);
  }

  function renderExplore(questions, topic) {
    controlsEl.innerHTML = "";
    if (questions && questions.length) {
      currentQuestions = questions;
    }
    var title = document.createElement("p");
    title.className = "assistant-step muted";
    title.textContent =
      "سوال‌های تخصصی" +
      (topic && topic.label ? " — " + topic.label : "") +
      " (اختیاری)";
    controlsEl.appendChild(title);

    var grid = document.createElement("div");
    grid.className = "assistant-options assistant-options--faq";
    (currentQuestions || []).forEach(function (q) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "assistant-option" + (exploredIds[q.id] ? " is-done" : "");
      b.textContent = q.text;
      b.addEventListener("click", function () {
        if (busy || !sessionId) return;
        setBusy(true);
        postForm(chatUrl, {
          action: "faq",
          sessionId: sessionId,
          questionId: q.id,
        })
          .then(function (data) {
            exploredIds[q.id] = true;
            handleChatResponse(data);
          })
          .catch(function (err) {
            addMsg("bot", err.message || "خطا");
            setBusy(false);
            renderExplore(null, topic);
          });
      });
      grid.appendChild(b);
    });
    controlsEl.appendChild(grid);

    var noteWrap = document.createElement("div");
    noteWrap.className = "assistant-composer";
    var ta = document.createElement("textarea");
    ta.className = "input assistant-text";
    ta.rows = 2;
    ta.placeholder = "اگر مورد دیگری مدنظرتان است اینجا بنویسید…";
    noteWrap.appendChild(ta);

    var row = document.createElement("div");
    row.className = "assistant-actions";

    var addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "btn btn-outline";
    addBtn.textContent = "ثبت مورد من";
    addBtn.addEventListener("click", function () {
      var text = (ta.value || "").trim();
      if (!text || busy) return;
      addMsg("user", text);
      ta.value = "";
      setBusy(true);
      postForm(chatUrl, { action: "add_note", sessionId: sessionId, text: text })
        .then(handleChatResponse)
        .catch(function (err) {
          addMsg("bot", err.message || "خطا");
          setBusy(false);
          renderExplore(null, topic);
        });
    });
    row.appendChild(addBtn);

    var nextBtn = document.createElement("button");
    nextBtn.type = "button";
    nextBtn.className = "btn btn-primary";
    nextBtn.textContent = "ادامه";
    nextBtn.addEventListener("click", function () {
      if (busy || !sessionId) return;
      setBusy(true);
      postForm(chatUrl, { action: "next_step", sessionId: sessionId })
        .then(handleChatResponse)
        .catch(function (err) {
          addMsg("bot", err.message || "خطا");
          setBusy(false);
        });
    });
    row.appendChild(nextBtn);

    noteWrap.appendChild(row);
    controlsEl.appendChild(noteWrap);
  }

  function renderChoices(choices) {
    controlsEl.innerHTML = "";
    var grid = document.createElement("div");
    grid.className = "assistant-options";
    (choices || []).forEach(function (c) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "assistant-option assistant-option--choice";
      b.textContent = c.label;
      b.addEventListener("click", function () {
        if (busy || !sessionId) return;
        addMsg("user", c.label);
        setBusy(true);
        postForm(chatUrl, {
          action: "choose_path",
          sessionId: sessionId,
          path: c.id,
        })
          .then(handleChatResponse)
          .catch(function (err) {
            addMsg("bot", err.message || "خطا");
            setBusy(false);
          });
      });
      grid.appendChild(b);
    });
    controlsEl.appendChild(grid);
  }

  function renderAiComposer() {
    controlsEl.innerHTML = "";
    var wrap = document.createElement("div");
    wrap.className = "assistant-composer";

    var ta = document.createElement("textarea");
    ta.className = "input assistant-text";
    ta.rows = 3;
    ta.placeholder = "اینجا بنویسید… درباره همان موضوعی که انتخاب کردید";
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
    if (data.status === "SENT" || data.delivered) {
      html +=
        '<p class="flash flash-success" style="margin:0">نسخه گفتگو برای درمانگران کلینیک ارسال شد.</p>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">مشاهده / چاپ گزارش</a>';
    } else if (loggedIn || data.loggedIn) {
      html +=
        '<button type="button" class="btn btn-primary" id="assistant-send-btn">ارسال مجدد به کلینیک</button>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">پیش‌نمایش چاپ</a>';
    } else {
      html +=
        '<a class="btn btn-outline" href="' +
        esc(reportBase + "?session=" + encodeURIComponent(sessionId)) +
        '">مشاهده / چاپ گزارش</a>';
      html +=
        '<a class="btn btn-outline" href="' +
        esc(data.loginUrl || loginUrl) +
        '">ورود (اختیاری)</a>';
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

  function applyPhaseUi(data) {
    phase = data.phase || phase;
    if (typeof data.canComplete === "boolean") canComplete = data.canComplete;
    if (typeof data.aiChat === "boolean") aiChat = data.aiChat;
    if (data.explored && data.explored.length) {
      data.explored.forEach(function (id) {
        exploredIds[id] = true;
      });
    }

    if (phase === "topic") {
      renderTopics(data.topics || []);
      return;
    }
    if (phase === "explore") {
      renderExplore(data.questions || currentQuestions, data.topic || null);
      return;
    }
    if (phase === "choice") {
      renderChoices(data.choices || []);
      return;
    }
    if (phase === "chat") {
      renderAiComposer();
      return;
    }
  }

  function handleChatResponse(data) {
    setBusy(false);
    if (data.sessionId) sessionId = data.sessionId;
    // در faq خود کاربر را سمت کلاینت اضافه نمی‌کنیم چون سرور userMessage می‌فرستد
    if (data.userMessage) {
      // قبلاً در UI اضافه نشده — faq از سرور
      var last = messagesEl.lastElementChild;
      var already =
        last &&
        last.classList.contains("assistant-msg--user") &&
        last.textContent === data.userMessage;
      if (!already) addMsg("user", data.userMessage);
    }
    if (data.botMessage) addMsg("bot", data.botMessage);
    if (data.done) {
      renderResults(data);
      return;
    }
    applyPhaseUi(data);
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
        if (data.done) {
          addMsg(
            "bot",
            "گفتگوی قبلی شما آماده است. پیشنهادها را ببینید و در صورت تمایل شرح‌حال را ارسال کنید."
          );
          renderResults(data);
          return;
        }
        if (data.messages && data.messages.length) {
          data.messages.forEach(function (m) {
            addMsg(m.role === "assistant" ? "bot" : "user", m.content || "");
          });
        }
        applyPhaseUi(data);
      })
      .catch(function () {
        startFresh();
      });
  }

  if (resumeSession) resume();
  else startFresh();
})();
