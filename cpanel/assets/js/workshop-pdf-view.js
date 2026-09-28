(function () {
  function vendor(file) {
    var named = {
      "pdf.min.js": window.workshopPdfJs,
      "pdf.worker.min.js": window.workshopPdfWorker,
      "pdf-lib.min.js": window.workshopPdfLib
    };
    if (named[file]) return named[file];
    var tag = document.querySelector('script[src*="workshop-pdf-view.js"]');
    var src = tag ? (tag.getAttribute("src") || "") : "";
    var root = src.replace(/assets\/js\/workshop-pdf-view\.js.*$/, "");
    return root + "assets/vendor/pdfjs/" + file;
  }

  function fontUrl() {
    var tag = document.querySelector('script[src*="workshop-pdf-view.js"]');
    var src = tag ? (tag.getAttribute("src") || "") : "";
    var root = src.replace(/assets\/js\/workshop-pdf-view\.js.*$/, "");
    return root + "assets/fonts/Vazirmatn-Medium.woff2";
  }

  var PDFJS = vendor("pdf.min.js");
  var PDFJS_WORKER = vendor("pdf.worker.min.js");
  var PDFLIB = vendor("pdf-lib.min.js");
  var loading = null;
  var fontLoading = null;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var found = document.querySelector('script[data-pdf-src="' + src + '"]');
      if (found) {
        if (found.getAttribute("data-ready") === "1") {
          resolve();
          return;
        }
        found.addEventListener("load", function () { resolve(); });
        found.addEventListener("error", reject);
        return;
      }
      var script = document.createElement("script");
      script.src = src;
      script.async = true;
      script.setAttribute("data-pdf-src", src);
      script.onload = function () {
        script.setAttribute("data-ready", "1");
        resolve();
      };
      script.onerror = function () { reject(new Error("script")); };
      document.head.appendChild(script);
    });
  }

  function ensureLibs(needBuild) {
    if (!loading) {
      loading = loadScript(PDFJS).then(function () {
        if (window.pdfjsLib) window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
      });
    }
    var jobs = [loading];
    if (needBuild) jobs.push(loadScript(PDFLIB));
    return Promise.all(jobs);
  }

  function ensureMarkFont() {
    if (fontLoading) return fontLoading;
    fontLoading = new Promise(function (resolve) {
      if (!window.FontFace) {
        resolve(false);
        return;
      }
      try {
        if (document.fonts && document.fonts.check("500 16px ManaWatermark")) {
          warmupFont();
          resolve(true);
          return;
        }
      } catch (e) {}
      var face = new FontFace("ManaWatermark", "url(" + fontUrl() + ")", { weight: "500" });
      face.load().then(function (loaded) {
        document.fonts.add(loaded);
        warmupFont();
        if (document.fonts && document.fonts.load) {
          return document.fonts.load("500 16px ManaWatermark");
        }
      }).then(function () {
        resolve(true);
      }).catch(function () {
        resolve(false);
      });
    });
    return fontLoading;
  }

  function warmupFont() {
    if (document.getElementById("mana-wm-probe")) return;
    var probe = document.createElement("span");
    probe.id = "mana-wm-probe";
    probe.textContent = "مهر";
    probe.style.cssText = "position:absolute;left:-9999px;top:0;font:500 16px ManaWatermark,Tahoma,sans-serif";
    document.body.appendChild(probe);
  }

  function paintMark(ctx, w, h, text) {
    if (!text) return false;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = "source-over";
    ctx.direction = "ltr";
    ctx.font = "500 " + Math.max(11, Math.round(w / 44)) + "px ManaWatermark, Tahoma, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    if (ctx.measureText(text).width < 8) {
      ctx.restore();
      return false;
    }
    ctx.translate(w / 2, h / 2);
    ctx.rotate(-26 * Math.PI / 180);
    var stepX = Math.max(220, ctx.measureText(text).width + 70);
    var stepY = Math.max(78, Math.round(h / 8));
    var y;
    var x;
    ctx.fillStyle = "rgba(45,45,45,0.32)";
    for (y = -h; y <= h; y += stepY) {
      for (x = -w; x <= w; x += stepX) {
        ctx.fillText(text, x, y);
      }
    }
    ctx.restore();
    return true;
  }

  function markCanvas(text) {
    var canvas = document.createElement("canvas");
    var size = 720;
    canvas.width = size;
    canvas.height = size;
    var ctx = canvas.getContext("2d");
    if (!paintMark(ctx, size, size, text)) return null;
    return canvas;
  }

  function setStatus(box, text) {
    var el = box.querySelector(".wm-pdf-status");
    if (el) el.textContent = text || "";
  }

  function fetchBytes(box) {
    return fetch(box.getAttribute("data-pdf-url") || "", { credentials: "same-origin", cache: "no-store" }).then(function (res) {
      if (!res.ok) throw new Error("fetch");
      return res.arrayBuffer();
    });
  }

  function dataUrlToBytes(dataUrl) {
    var raw = atob(dataUrl.split(",")[1] || "");
    var bytes = new Uint8Array(raw.length);
    var i;
    for (i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
  }

  function renderCanvases(pdf, text, scale) {
    var pages = [];
    var chain = Promise.resolve();
    var i;
    for (i = 1; i <= pdf.numPages; i++) {
      (function (num) {
        chain = chain.then(function () {
          return new Promise(function (resolve) { setTimeout(resolve, 0); });
        }).then(function () {
          return pdf.getPage(num).then(function (page) {
            var viewport = page.getViewport({ scale: scale });
            var canvas = document.createElement("canvas");
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            var ctx = canvas.getContext("2d");
            return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
              var clean = document.createElement("canvas");
              clean.width = canvas.width;
              clean.height = canvas.height;
              var out = clean.getContext("2d");
              out.drawImage(canvas, 0, 0);
              paintMark(out, clean.width, clean.height, text);
              pages.push({ canvas: clean, width: viewport.width / scale, height: viewport.height / scale });
            });
          });
        });
      })(i);
    }
    return chain.then(function () { return pages; });
  }

  function inkLayer(text, count) {
    var layer = document.createElement("div");
    layer.className = "wm-pdf-ink";
    layer.setAttribute("aria-hidden", "true");
    var n = count || 24;
    var i;
    for (i = 0; i < n; i++) {
      var span = document.createElement("span");
      span.textContent = text;
      layer.appendChild(span);
    }
    return layer;
  }

  function saveBlob(box, bytes) {
    var blob = new Blob([bytes], { type: "application/pdf" });
    var url = URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.href = url;
    var name = box.getAttribute("data-pdf-name") || "session.pdf";
    link.download = name.toLowerCase().indexOf(".pdf") === name.length - 4 ? name : name + ".pdf";
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  function overlayPdf(buffer, text) {
    var mark = markCanvas(text);
    if (!mark || !window.PDFLib) throw new Error("mark");
    var pngBytes = dataUrlToBytes(mark.toDataURL("image/png"));
    return window.PDFLib.PDFDocument.load(buffer, { ignoreEncryption: true }).then(function (doc) {
      return doc.embedPng(pngBytes).then(function (img) {
        doc.getPages().forEach(function (page) {
          var size = page.getSize();
          var side = Math.max(size.width, size.height) * 1.45;
          page.drawImage(img, {
            x: (size.width - side) / 2,
            y: (size.height - side) / 2,
            width: side,
            height: side
          });
        });
        return doc.save();
      });
    });
  }

  function rasterPdf(buffer, text) {
    var copy = buffer.slice(0);
    return window.pdfjsLib.getDocument({ data: copy }).promise.then(function (pdf) {
      return renderCanvases(pdf, text, 1.05);
    }).then(function (pages) {
      return window.PDFLib.PDFDocument.create().then(function (out) {
        var chain = Promise.resolve();
        pages.forEach(function (page) {
          chain = chain.then(function () {
            var bytes = dataUrlToBytes(page.canvas.toDataURL("image/jpeg", 0.86));
            return out.embedJpg(bytes).then(function (img) {
              var sheet = out.addPage([page.width, page.height]);
              sheet.drawImage(img, { x: 0, y: 0, width: page.width, height: page.height });
            });
          });
        });
        return chain.then(function () { return out.save(); });
      });
    });
  }

  function buildDownload(buffer, text) {
    return ensureLibs(true).then(function () {
      if (!window.PDFLib || !window.pdfjsLib) throw new Error("pdflib");
      return overlayPdf(buffer, text).catch(function () {
        return rasterPdf(buffer, text);
      });
    });
  }

  document.addEventListener("click", function (e) {
    var pptBtn = e.target && e.target.closest ? e.target.closest(".js-ppt-show") : null;
    if (pptBtn) {
      var pptBox = pptBtn.closest(".wm-ppt-box");
      var wrap = pptBox ? pptBox.querySelector(".wm-ppt-frame") : null;
      var src = pptBtn.getAttribute("data-ppt-url") || "";
      if (wrap && src) {
        wrap.innerHTML = "";
        var frame = document.createElement("iframe");
        frame.className = "wm-ppt-native";
        frame.title = "پاورپوینت";
        frame.src = src;
        wrap.appendChild(frame);
        wrap.hidden = false;
      }
      return;
    }
    var showBtn = e.target && e.target.closest ? e.target.closest(".js-pdf-show") : null;
    var downBtn = e.target && e.target.closest ? e.target.closest(".js-pdf-download") : null;
    var btn = showBtn || downBtn;
    if (!btn) return;
    e.preventDefault();
    var box = btn.closest(".wm-pdf-box");
    if (!box || box.getAttribute("data-busy") === "1") return;
    var text = box.getAttribute("data-pdf-mark") || window.workshopOfflineWatermark || "";
    if (!text) {
      setStatus(box, "نام برای واترمارک پیدا نشد.");
      return;
    }
    var pagesEl = box.querySelector(".wm-pdf-pages");
    box.setAttribute("data-busy", "1");
    btn.disabled = true;
    setStatus(box, showBtn ? "در حال آماده‌سازی نمایش..." : "در حال ساخت فایل با واترمارک...");
    ensureMarkFont().then(function () {
      return fetchBytes(box);
    }).then(function (buffer) {
      if (downBtn) return buildDownload(buffer, text);
      return ensureLibs(false).then(function () {
        if (!window.pdfjsLib) throw new Error("pdfjs");
        return window.pdfjsLib.getDocument({ data: buffer }).promise;
      }).then(function (pdf) {
        return renderCanvases(pdf, text, 1);
      }).then(function (pages) {
        if (pagesEl) {
          pagesEl.innerHTML = "";
          var stack = document.createElement("div");
          stack.className = "wm-pdf-stack";
          pages.forEach(function (page) {
            page.canvas.className = "wm-pdf-canvas";
            stack.appendChild(page.canvas);
          });
          stack.appendChild(inkLayer(text, 16));
          pagesEl.appendChild(stack);
          pagesEl.hidden = false;
        }
        setStatus(box, "");
        btn.disabled = false;
        box.removeAttribute("data-busy");
      });
    }).then(function (saved) {
      if (!downBtn || !saved) return;
      saveBlob(box, saved);
      setStatus(box, "فایل دانلودی واترمارک دارد.");
      btn.disabled = false;
      box.removeAttribute("data-busy");
    }).catch(function (err) {
      if (err && err.message === "fetch") {
        setStatus(box, "فایل از سرور خوانده نشد.");
      } else if (err && err.message === "mark") {
        setStatus(box, "واترمارک ساخته نشد. صفحه را یک‌بار تازه کنید.");
      } else {
        setStatus(box, "ساخت فایل با واترمارک ممکن نشد. صفحه را تازه کنید.");
      }
      btn.disabled = false;
      box.removeAttribute("data-busy");
    });
  });
})();
