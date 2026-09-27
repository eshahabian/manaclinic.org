(function () {
  var PDFJS = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js";
  var PDFJS_WORKER = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  var PDFLIB = "https://cdnjs.cloudflare.com/ajax/libs/pdf-lib/1.17.1/pdf-lib.min.js";
  var loading = null;

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

  function paintMark(ctx, w, h, text) {
    if (!text) return;
    ctx.save();
    ctx.fillStyle = "rgba(40,40,40,0.22)";
    ctx.font = "600 " + Math.max(18, Math.round(w / 26)) + "px Vazirmatn, Tahoma, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.translate(w / 2, h / 2);
    ctx.rotate(-28 * Math.PI / 180);
    var stepX = Math.max(280, ctx.measureText(text).width + 80);
    var stepY = Math.max(110, Math.round(h / 7));
    var y;
    var x;
    for (y = -h; y <= h; y += stepY) {
      for (x = -w; x <= w; x += stepX) {
        ctx.fillText(text, x, y);
      }
    }
    ctx.restore();
  }

  function setStatus(box, text) {
    var el = box.querySelector(".wm-pdf-status");
    if (el) el.textContent = text || "";
  }

  function openPdf(box) {
    return ensureLibs(false).then(function () {
      if (!window.pdfjsLib) throw new Error("pdfjs");
      return fetch(box.getAttribute("data-pdf-url") || "", { credentials: "same-origin", cache: "no-store" });
    }).then(function (res) {
      if (!res.ok) throw new Error("fetch");
      return res.arrayBuffer();
    }).then(function (buffer) {
      return window.pdfjsLib.getDocument({ data: buffer }).promise;
    });
  }

  function renderCanvases(pdf, text, scale) {
    var pages = [];
    var chain = Promise.resolve();
    var i;
    for (i = 1; i <= pdf.numPages; i++) {
      (function (num) {
        chain = chain.then(function () {
          return pdf.getPage(num).then(function (page) {
            var viewport = page.getViewport({ scale: scale });
            var canvas = document.createElement("canvas");
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            var ctx = canvas.getContext("2d");
            return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
              paintMark(ctx, canvas.width, canvas.height, text);
              pages.push({ canvas: canvas, width: viewport.width / scale, height: viewport.height / scale });
            });
          });
        });
      })(i);
    }
    return chain.then(function () { return pages; });
  }

  function dataUrlToBytes(dataUrl) {
    var raw = atob(dataUrl.split(",")[1] || "");
    var bytes = new Uint8Array(raw.length);
    var i;
    for (i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
  }

  document.addEventListener("click", function (e) {
    var showBtn = e.target && e.target.closest ? e.target.closest(".js-pdf-show") : null;
    var downBtn = e.target && e.target.closest ? e.target.closest(".js-pdf-download") : null;
    var btn = showBtn || downBtn;
    if (!btn) return;
    var box = btn.closest(".wm-pdf-box");
    if (!box || box.getAttribute("data-busy") === "1") return;
    var text = box.getAttribute("data-pdf-mark") || window.workshopOfflineWatermark || "";
    var pagesEl = box.querySelector(".wm-pdf-pages");
    box.setAttribute("data-busy", "1");
    btn.disabled = true;
    setStatus(box, showBtn ? "در حال آماده‌سازی نمایش..." : "در حال ساخت فایل با واترمارک...");
    var fontReady = document.fonts && document.fonts.load
      ? document.fonts.load("600 24px Vazirmatn").catch(function () {})
      : Promise.resolve();
    fontReady.then(function () {
      return openPdf(box);
    }).then(function (pdf) {
      return renderCanvases(pdf, text, showBtn ? 1.35 : 1.5);
    }).then(function (pages) {
      if (showBtn) {
        if (pagesEl) {
          pagesEl.innerHTML = "";
          pages.forEach(function (page) {
            page.canvas.className = "wm-pdf-canvas";
            pagesEl.appendChild(page.canvas);
          });
          pagesEl.hidden = false;
        }
        setStatus(box, "");
        box.removeAttribute("data-busy");
        return;
      }
      return ensureLibs(true).then(function () {
        if (!window.PDFLib) throw new Error("pdflib");
        return window.PDFLib.PDFDocument.create();
      }).then(function (out) {
        var chain = Promise.resolve();
        pages.forEach(function (page) {
          chain = chain.then(function () {
            var bytes = dataUrlToBytes(page.canvas.toDataURL("image/jpeg", 0.82));
            return out.embedJpg(bytes).then(function (img) {
              var sheet = out.addPage([page.width, page.height]);
              sheet.drawImage(img, { x: 0, y: 0, width: page.width, height: page.height });
            });
          });
        });
        return chain.then(function () { return out.save(); });
      }).then(function (saved) {
        var blob = new Blob([saved], { type: "application/pdf" });
        var url = URL.createObjectURL(blob);
        var link = document.createElement("a");
        link.href = url;
        var name = box.getAttribute("data-pdf-name") || "session.pdf";
        link.download = name.toLowerCase().indexOf(".pdf") === name.length - 4 ? name : name + ".pdf";
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        setStatus(box, "فایل دانلودی واترمارک دارد.");
        btn.disabled = false;
        box.removeAttribute("data-busy");
      });
    }).catch(function () {
      setStatus(box, "نمایش فایل ممکن نشد. صفحه را تازه کنید.");
      btn.disabled = false;
      box.removeAttribute("data-busy");
    });
  });
})();
