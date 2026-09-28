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

  var PDFJS = vendor("pdf.min.js");
  var PDFJS_WORKER = vendor("pdf.worker.min.js");
  var PDFLIB = vendor("pdf-lib.min.js");
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
      loading = fetch(PDFJS_WORKER, { credentials: "same-origin" }).then(function (res) {
        if (!res.ok) throw new Error("worker");
        return res.blob();
      }).then(function (blob) {
        window.__manaPdfWorker = URL.createObjectURL(new Blob([blob], { type: "text/javascript" }));
      }).catch(function () {
        window.__manaPdfWorker = PDFJS_WORKER;
      }).then(function () {
        return loadScript(PDFJS);
      }).then(function () {
        if (window.pdfjsLib) window.pdfjsLib.GlobalWorkerOptions.workerSrc = window.__manaPdfWorker || PDFJS_WORKER;
      });
    }
    var jobs = [loading];
    if (needBuild) jobs.push(loadScript(PDFLIB));
    return Promise.all(jobs);
  }

  function svgEscape(text) {
    return String(text).replace(/[&<>"]/g, function (ch) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[ch];
    });
  }

  function loadMarkImage(text) {
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="36">'
      + '<text x="260" y="24" text-anchor="middle" font-family="Tahoma,Segoe UI,sans-serif" font-size="15" fill="#2a2a2a" fill-opacity="0.34">'
      + svgEscape(text) + "</text></svg>";
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { resolve(null); };
      img.src = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
    });
  }

  function paintText(ctx, w, h, text) {
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = "source-over";
    ctx.direction = "ltr";
    ctx.font = Math.max(11, Math.round(w / 44)) + "px Tahoma, Segoe UI, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillStyle = "rgba(45,45,45,0.32)";
    ctx.translate(w / 2, h / 2);
    ctx.rotate(-24 * Math.PI / 180);
    var stepX = Math.max(180, ctx.measureText(text).width + 48);
    var stepY = Math.max(64, Math.round(h / 9));
    var y;
    var x;
    for (y = -h; y <= h; y += stepY) {
      for (x = -w; x <= w; x += stepX) ctx.fillText(text, x, y);
    }
    ctx.restore();
  }

  function paintMark(ctx, w, h, img, text) {
    if (!img) {
      if (!text) return false;
      paintText(ctx, w, h, text);
      return true;
    }
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = "source-over";
    var iw = Math.max(90, Math.round(w / 4.4));
    var ih = Math.max(16, Math.round(iw * (img.height / img.width)));
    ctx.translate(w / 2, h / 2);
    ctx.rotate(-24 * Math.PI / 180);
    var stepX = iw + Math.round(w / 16);
    var stepY = ih + Math.round(h / 8);
    var y;
    var x;
    for (y = -h; y <= h; y += stepY) {
      for (x = -w; x <= w; x += stepX) {
        ctx.drawImage(img, x - iw / 2, y - ih / 2, iw, ih);
      }
    }
    ctx.restore();
    return true;
  }

  function markCanvas(img, text) {
    var canvas = document.createElement("canvas");
    var size = 640;
    canvas.width = size;
    canvas.height = size;
    var ctx = canvas.getContext("2d");
    if (!paintMark(ctx, size, size, img, text)) return null;
    return canvas;
  }

  function pngBytes(canvas) {
    try {
      return dataUrlToBytes(canvas.toDataURL("image/png"));
    } catch (e) {
      return null;
    }
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

  function openPdf(buffer) {
    var bytes = new Uint8Array(buffer);
    return window.pdfjsLib.getDocument({ data: bytes, disableFontFace: true, useSystemFonts: true }).promise;
  }

  function renderCanvases(pdf, markImg, text, scale) {
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
              paintMark(ctx, canvas.width, canvas.height, markImg, text);
              pages.push({ canvas: canvas, width: viewport.width / scale, height: viewport.height / scale });
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
    var n = count || 16;
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

  function overlayPdf(buffer, markImg, text) {
    var mark = markCanvas(markImg, text);
    var bytes = mark ? pngBytes(mark) : null;
    if (!bytes) {
      mark = markCanvas(null, text);
      bytes = mark ? pngBytes(mark) : null;
    }
    if (!bytes || !window.PDFLib) throw new Error("mark");
    return window.PDFLib.PDFDocument.load(buffer, { ignoreEncryption: true }).then(function (doc) {
      return doc.embedPng(bytes).then(function (img) {
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

  function rasterPdf(buffer, markImg, text) {
    return openPdf(buffer.slice(0)).then(function (pdf) {
      return renderCanvases(pdf, markImg, text, 1);
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

  function buildDownload(buffer, markImg, text) {
    return ensureLibs(true).then(function () {
      if (!window.PDFLib) throw new Error("pdflib");
      return overlayPdf(buffer, markImg, text);
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
    setStatus(box, showBtn ? "در حال آماده‌سازی نمایش..." : "در حال ساخت فایل با واترمارک...");
    loadMarkImage(text).then(function (markImg) {
      return fetchBytes(box).then(function (buffer) {
        return { buffer: buffer, markImg: markImg };
      });
    }).then(function (ready) {
      if (downBtn) return buildDownload(ready.buffer, ready.markImg, text);
      return ensureLibs(false).then(function () {
        if (!window.pdfjsLib) throw new Error("pdfjs");
        return openPdf(ready.buffer);
      }).then(function (pdf) {
        return renderCanvases(pdf, ready.markImg, text, 1);
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
        box.removeAttribute("data-busy");
      });
    }).then(function (saved) {
      if (!downBtn || !saved) return;
      saveBlob(box, saved);
      setStatus(box, "فایل دانلودی واترمارک دارد.");
      box.removeAttribute("data-busy");
    }).catch(function (err) {
      if (err && err.message === "fetch") {
        setStatus(box, "فایل از سرور خوانده نشد.");
      } else if (err && err.message === "mark") {
        setStatus(box, "واترمارک ساخته نشد. صفحه را یک‌بار تازه کنید.");
      } else {
        setStatus(box, "ساخت فایل با واترمارک ممکن نشد. صفحه را تازه کنید.");
      }
      box.removeAttribute("data-busy");
    });
  });
})();
