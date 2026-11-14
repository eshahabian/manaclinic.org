import json
import subprocess
import sys
from functools import lru_cache
from pathlib import Path

import arabic_reshaper
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

try:
    from bidi import get_display
except ImportError:
    from bidi.algorithm import get_display

ROOT = Path(__file__).parent
SRC = ROOT / "frames_src"
FFMPEG = r"C:\Program Files\ZD Soft\Screen Recorder\Tools\ffmpeg.exe"
FONT = r"D:\Psychology\cpanel\assets\fonts\Vazirmatn-Variable.ttf"
LOGO = r"D:\Psychology\cpanel\assets\img\logo.png"
W, H, FPS, DUR = 1080, 1920, 30, 60.0
BASE_SCALE = 1.14

# (file, start, end, zoom_from, zoom_to, pan_x_from, pan_x_to, pan_y_from, pan_y_to)
SHOTS = [
    ("s1a_tehran_street.jpg", 0.0, 3.7, 1.00, 1.08, 0.0, 0.0, 0.03, -0.02),
    ("s1b_phone_news.jpg", 3.1, 6.7, 1.10, 1.02, 0.02, -0.01, 0.0, 0.0),
    ("s1c_family_bills.jpg", 6.1, 9.7, 1.02, 1.09, -0.02, 0.02, 0.02, 0.0),
    ("s1d_window_night.jpg", 9.1, 12.8, 1.04, 1.11, 0.0, 0.02, 0.0, -0.01),
    ("s2a_woman_home.jpg", 12.2, 16.4, 1.03, 1.10, 0.02, -0.01, 0.0, 0.0),
    ("s2b_man_staring.jpg", 15.8, 20.0, 1.12, 1.05, 0.0, 0.0, 0.0, 0.0),
    ("s2c_bed_awake.jpg", 19.4, 24.6, 1.10, 1.03, 0.0, 0.0, -0.01, 0.0),
    ("s3a_window_dawn.jpg", 24.6, 31.4, 1.02, 1.07, 0.0, 0.01, 0.0, 0.0),
    ("s3b_light_enters.jpg", 29.0, 36.6, 1.03, 1.09, 0.0, 0.0, 0.0, 0.01),
    ("s4a_group_session.jpg", 36.0, 40.7, 1.09, 1.02, 0.0, 0.0, 0.01, -0.01),
    ("s4b_breathing.jpg", 40.1, 44.5, 1.02, 1.08, 0.0, 0.0, 0.0, 0.0),
    ("s4c_mindful_walking.jpg", 43.9, 48.7, 1.04, 1.10, 0.02, -0.02, 0.0, 0.0),
    ("s5a_morning_calm.jpg", 48.1, 60.0, 1.00, 1.07, 0.0, 0.0, 0.0, -0.01),
]
XFADE = {"s3b_light_enters.jpg": 2.4}
DEFAULT_XF = 0.6


def smooth(x):
    x = np.clip(x, 0.0, 1.0)
    return x * x * (3 - 2 * x)


def ease(x):
    x = min(max(x, 0.0), 1.0)
    return 0.5 - 0.5 * np.cos(np.pi * x)


@lru_cache(maxsize=None)
def base_image(name):
    im = Image.open(SRC / name).convert("RGB")
    bw, bh = int(W * BASE_SCALE), int(H * BASE_SCALE)
    im = im.resize((bw, bh), Image.LANCZOS)
    im = im.filter(ImageFilter.UnsharpMask(radius=2.2, percent=70, threshold=2))
    return im


def render_shot(shot, t):
    name, s, e, z0, z1, px0, px1, py0, py1 = shot
    u = ease((t - s) / (e - s))
    z = z0 + (z1 - z0) * u
    px = px0 + (px1 - px0) * u
    py = py0 + (py1 - py0) * u
    im = base_image(name)
    bw, bh = im.size
    cw, ch = bw / (BASE_SCALE * z), bh / (BASE_SCALE * z)
    cx = bw / 2 + px * bw
    cy = bh / 2 + py * bh
    x0 = min(max(cx - cw / 2, 0), bw - cw)
    y0 = min(max(cy - ch / 2, 0), bh - ch)
    frame = im.resize((W, H), Image.BILINEAR, box=(x0, y0, x0 + cw, y0 + ch))
    return np.asarray(frame, dtype=np.float32) / 255.0


def compose_plate(t):
    acc = None
    for i, shot in enumerate(SHOTS):
        name, s, e = shot[0], shot[1], shot[2]
        if not (s <= t < e or (i == len(SHOTS) - 1 and t >= s)):
            continue
        img = render_shot(shot, t)
        xf = XFADE.get(name, DEFAULT_XF)
        alpha = 1.0 if i == 0 else float(smooth((t - s) / xf))
        acc = img if acc is None else acc * (1 - alpha) + img * alpha
    return acc


yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
r = np.sqrt(((xx - W / 2) / (W * 0.62)) ** 2 + ((yy - H / 2) / (H * 0.62)) ** 2)
VIGNETTE = (1 - 0.32 * smooth((r - 0.45) / 0.75))[..., None].astype(np.float32)
_g = np.random.default_rng(11)
GRAIN = []
for _ in range(8):
    small = _g.normal(0, 1, (H // 2, W // 2)).astype(np.float32)
    g = np.asarray(Image.fromarray(((small * 40) + 128).clip(0, 255).astype(np.uint8)).resize((W, H), Image.BILINEAR),
                   dtype=np.float32)
    GRAIN.append(((g - 128) / 40)[..., None])


def warmth(t):
    if t < 24.5:
        return 0.0
    return float(smooth((t - 24.5) / 11.0))


def grade(img, t, fi):
    u = warmth(t)
    luma = (img * np.array([0.2126, 0.7152, 0.0722], np.float32)).sum(axis=2, keepdims=True)
    sat = 0.70 + 0.28 * u
    img = luma + (img - luma) * sat
    cold = np.array([0.94, 0.985, 1.07], np.float32)
    warm = np.array([1.045, 1.0, 0.93], np.float32)
    img = img * (cold * (1 - u) + warm * u)
    contrast = 0.92 + 0.06 * u
    img = (img - 0.5) * contrast + 0.5 + (0.015 - 0.01 * u)
    img = img * VIGNETTE
    img = img + GRAIN[fi % len(GRAIN)] * (0.028 - 0.008 * u)
    return np.clip(img, 0, 1)


def font(size, weight=500):
    f = ImageFont.truetype(FONT, size)
    try:
        f.set_variation_by_axes([weight])
    except Exception:
        pass
    return f


def shape(text):
    if any("\u0600" <= c <= "\u06ff" for c in text):
        return get_display(arabic_reshaper.reshape(text))
    return text


def text_layer(text, size, weight=500, fill=(255, 255, 255, 255), box=None, shadow=True, pad=(34, 18)):
    f = font(size, weight)
    disp = shape(text)
    l, tp, rt, bt = f.getbbox(disp)
    tw, th = rt - l, bt - tp
    lw, lh = tw + pad[0] * 2, int(size * 1.55) + pad[1] * 2
    layer = Image.new("RGBA", (lw + 20, lh + 20), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    if box:
        d.rounded_rectangle((10, 10, 10 + lw, 10 + lh), radius=26, fill=box)
    tx = 10 + pad[0] - l
    ty = 10 + (lh - th) / 2 - tp
    if shadow:
        sh = Image.new("RGBA", layer.size, (0, 0, 0, 0))
        ImageDraw.Draw(sh).text((tx, ty + 3), disp, font=f, fill=(0, 0, 0, 170))
        sh = sh.filter(ImageFilter.GaussianBlur(4))
        layer = Image.alpha_composite(layer, sh)
        d = ImageDraw.Draw(layer)
    d.text((tx, ty), disp, font=f, fill=fill)
    return layer


def to_np_rgba(layer):
    a = np.asarray(layer, dtype=np.float32) / 255.0
    return a[..., :3], a[..., 3:4]


@lru_cache(maxsize=None)
def cached_layer(key):
    kind, text, size, weight, fill, box = key
    return to_np_rgba(text_layer(text, size, weight, fill, box))


def overlay(img, rgb, a, cx, cy, opacity):
    if opacity <= 0.001:
        return
    h, w = a.shape[:2]
    x0, y0 = int(round(cx - w / 2)), int(round(cy - h / 2))
    xs0, ys0 = max(0, -x0), max(0, -y0)
    x0c, y0c = max(0, x0), max(0, y0)
    x1c, y1c = min(W, x0 + w), min(H, y0 + h)
    if x1c <= x0c or y1c <= y0c:
        return
    sub_rgb = rgb[ys0:ys0 + (y1c - y0c), xs0:xs0 + (x1c - x0c)]
    sub_a = a[ys0:ys0 + (y1c - y0c), xs0:xs0 + (x1c - x0c)] * opacity
    region = img[y0c:y1c, x0c:x1c]
    img[y0c:y1c, x0c:x1c] = region * (1 - sub_a) + sub_rgb * sub_a


def fade_window(t, s, e, fin=0.18, fout=0.18):
    if t < s or t > e:
        return 0.0
    return float(min(smooth((t - s) / fin), smooth((e - t) / fout)))


timeline = json.loads((ROOT / "timeline.json").read_text(encoding="utf-8"))
script = {x["id"]: x for x in json.loads((ROOT / "script.json").read_text(encoding="utf-8"))}


def build_cues():
    cues = []
    for sid, item in script.items():
        tl = timeline[sid]
        words = tl["words"]
        wlen = np.array([len(w["text"].replace("\u200c", "")) for w in words], float)
        wcum = np.concatenate([[0], np.cumsum(wlen)]) / wlen.sum()
        chunks = item["subs"]
        clen = np.array([len(c.replace(" ", "").replace("\u200c", "")) for c in chunks], float)
        ccum = np.concatenate([[0], np.cumsum(clen)]) / clen.sum()
        starts = []
        for f in ccum[:-1]:
            idx = int(np.searchsorted(wcum[:-1], f - 1e-6, side="left"))
            idx = min(idx, len(words) - 1)
            starts.append(tl["start"] + words[idx]["start"])
        starts[0] = tl["start"] + words[0]["start"] - 0.05
        end_all = tl["start"] + words[-1]["end"] + 0.45
        for i, c in enumerate(chunks):
            s = starts[i]
            e = starts[i + 1] - 0.04 if i + 1 < len(chunks) else end_all
            cues.append((s, max(e, s + 0.7), c))
    return cues


CUES = build_cues()
SUB_Y = 1330
SUB_BOX = (12, 14, 18, 150)


def draw_subtitles(img, t):
    for s, e, text in CUES:
        op = fade_window(t, s, e, 0.12, 0.12)
        if op > 0:
            rgb, a = cached_layer(("sub", text, 50, 560, (255, 255, 255, 255), SUB_BOX))
            overlay(img, rgb, a, W / 2, SUB_Y, op)


def gradient_band(img, y0, y1, strength):
    if strength <= 0:
        return
    ys = np.arange(H, dtype=np.float32)
    m = np.clip(1 - np.abs(ys - (y0 + y1) / 2) / ((y1 - y0) / 2), 0, 1) ** 0.8
    img *= (1 - strength * m)[:, None, None]


def draw_mbsr_card(img, t):
    s, e = 37.0, 47.9
    op = fade_window(t, s, e, 0.9, 0.8)
    if op <= 0:
        return
    gradient_band(img, 150, 900, 0.5 * op)
    rise = (1 - ease((t - s) / 1.4)) * 26
    layers = [
        (("card", "MBSR", 168, 800, (255, 255, 255, 255), None), 440, 0.0),
        (("card", "Mindfulness-Based Stress Reduction", 40, 450, (236, 226, 208, 255), None), 575, 0.35),
        (("card", "کاهش استرس مبتنی بر ذهن‌آگاهی", 52, 600, (255, 255, 255, 255), None), 665, 0.7),
    ]
    for key, y, delay in layers:
        o = op * float(smooth((t - s - delay) / 0.8))
        rgb, a = cached_layer(key)
        overlay(img, rgb, a, W / 2, y + rise, o)
    line_o = op * float(smooth((t - s - 0.5) / 0.8))
    if line_o > 0:
        half = int(150 * ease((t - s - 0.5) / 1.2))
        if half > 0:
            region = img[615:618, W // 2 - half:W // 2 + half]
            img[615:618, W // 2 - half:W // 2 + half] = region * (1 - 0.8 * line_o) + np.array([0.9, 0.82, 0.62]) * 0.8 * line_o


@lru_cache(maxsize=None)
def logo_layer(size):
    lg = Image.open(LOGO).convert("RGBA").resize((size, size), Image.LANCZOS)
    pad = 40
    canvas = Image.new("RGBA", (size + pad * 2, size + pad * 2), (0, 0, 0, 0))
    glow = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    ImageDraw.Draw(glow).ellipse((pad, pad + 6, pad + size, pad + size + 6), fill=(0, 0, 0, 120))
    glow = glow.filter(ImageFilter.GaussianBlur(14))
    canvas = Image.alpha_composite(canvas, glow)
    canvas.alpha_composite(lg, (pad, pad))
    return to_np_rgba(canvas)


END_START = 52.4
END_TEXT = [
    (("end", "دوره جدید MBSR", 84, 800, (255, 255, 255, 255), None), 650, 0.8),
    (("end", "کلینیک روان‌شناسی مانا", 58, 620, (255, 255, 255, 255), None), 755, 1.3),
    (("end", "تمرین آگاهی، در روزهای دشوار", 46, 500, (240, 214, 160, 255), None), 850, 1.8),
    (("end", "اطلاعات و ثبت‌نام", 38, 500, (225, 225, 225, 255), None), 990, 2.6),
    (("end", "۰۲۱-۲۲۰۶۵۷۷۴   |   ۰۹۱۰-۱۳۸-۷۸۳۸", 42, 600, (255, 255, 255, 255), None), 1055, 2.9),
    (("end", "اینستاگرام  mana_clinic@", 40, 560, (255, 255, 255, 255), None), 1120, 3.2),
    (("end", "تهران، سعادت‌آباد", 34, 450, (215, 215, 215, 255), None), 1178, 3.4),
]


def draw_end_card(img, t):
    if t < END_START:
        return
    k = float(smooth((t - END_START) / 1.6))
    img *= (1 - 0.52 * k)
    gradient_band(img, 200, 1260, 0.25 * k)
    lt = t - END_START - 0.2
    lo = float(smooth(lt / 1.0))
    if lo > 0:
        scale = 0.92 + 0.08 * ease(lt / 1.6)
        size = int(250 * scale) // 2 * 2
        rgb, a = logo_layer(size)
        overlay(img, rgb, a, W / 2, 420, lo)
    for key, y, delay in END_TEXT:
        o = float(smooth((t - END_START - delay) / 0.7))
        rgb, a = cached_layer(key)
        overlay(img, rgb, a, W / 2, y + (1 - o) * 14, o)
    lo2 = float(smooth((t - END_START - 2.3) / 0.8))
    if lo2 > 0:
        half = 120
        region = img[920:922, W // 2 - half:W // 2 + half]
        img[920:922, W // 2 - half:W // 2 + half] = region * (1 - 0.7 * lo2) + np.array([0.94, 0.84, 0.63]) * 0.7 * lo2


def frame_at(fi):
    t = fi / FPS
    img = compose_plate(t)
    img = grade(img, t, fi)
    img = img * (0.0 + float(smooth(t / 0.9)))
    dip = 1 - 0.55 * max(0.0, 1 - abs(t - 24.6) / 0.6)
    img = img * dip
    draw_mbsr_card(img, t)
    draw_end_card(img, t)
    draw_subtitles(img, t)
    return (np.clip(img, 0, 1) * 255 + 0.5).astype(np.uint8)


def main():
    if len(sys.argv) > 1 and sys.argv[1] == "--preview":
        out = ROOT / "preview"
        out.mkdir(exist_ok=True)
        for ts in sys.argv[2].split(","):
            fi = int(float(ts) * FPS)
            Image.fromarray(frame_at(fi)).save(out / f"p_{float(ts):05.1f}.jpg", quality=90)
            print("preview", ts)
        return
    out = ROOT / "mana_mbsr_reel_60s.mp4"
    seq = ROOT / "seq"
    seq.mkdir(exist_ok=True)
    total = int(DUR * FPS)
    if "--encode-only" not in sys.argv:
        for fi in range(total):
            Image.fromarray(frame_at(fi)).save(seq / f"f_{fi:05d}.jpg", quality=97, subsampling=0)
            if fi % 150 == 0:
                print(f"frame {fi}/{total}", flush=True)
    cmd = [FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
           "-framerate", str(FPS), "-start_number", "0", "-i", str(seq / "f_%05d.jpg"),
           "-i", str(ROOT / "audio" / "final_mix.wav"),
           "-map", "0:v", "-map", "1:a",
           "-vf", "scale=out_color_matrix=bt709:out_range=tv",
           "-c:v", "libx264", "-preset", "slow", "-crf", "19", "-maxrate", "10M", "-bufsize", "20M", "-profile:v", "high", "-level", "4.1",
           "-pix_fmt", "yuv420p", "-g", "60", "-bf", "2",
           "-colorspace", "bt709", "-color_primaries", "bt709", "-color_trc", "bt709",
           "-c:a", "aac", "-b:a", "192k", "-ar", "48000",
           "-t", "60", "-movflags", "+faststart", str(out)]
    rc = subprocess.call(cmd)
    print("done", rc, out)


if __name__ == "__main__":
    main()
