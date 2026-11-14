import json
from pathlib import Path

import numpy as np
from scipy_free import fftconvolve, highpass, lowpass, read_wav, write_wav

ROOT = Path(__file__).parent
SR = 48000
N = SR * 60

VO_START = {"s1": 0.8, "s2": 12.5, "s3": 25.0, "s4": 36.6, "s5": 46.9}

music = np.load(ROOT / "audio" / "music.npy").astype(np.float64)
vo = np.zeros(N)
active = np.zeros(N)
timeline = {}
for sid, start in VO_START.items():
    x, sr = read_wav(ROOT / "audio" / f"vo_{sid}.wav")
    assert sr == SR
    x = highpass(x, 85.0, SR)
    rms = np.sqrt(np.mean(x[np.abs(x) > 0.01] ** 2))
    x *= (10 ** (-17 / 20)) / rms
    i0 = int(start * SR)
    j = min(N, i0 + len(x))
    vo[i0:j] += x[: j - i0]
    active[i0:j] = 1
    words = json.loads((ROOT / "audio" / f"vo_{sid}.words.json").read_text(encoding="utf-8"))
    timeline[sid] = {"start": start, "end": start + len(x) / SR, "words": words}
    assert start + words[-1]["end"] < 59.7, f"{sid} overruns 60s"

rng = np.random.default_rng(3)
room_len = int(SR * 0.6)
rt = np.arange(room_len) / SR
room = lowpass(rng.normal(0, 1, room_len) * np.exp(-rt * 11), 4000.0, SR)
room /= np.sqrt(np.sum(room ** 2))
vo = vo + 0.10 * fftconvolve(vo, room)[:N]

k = int(SR * 0.35)
kernel = np.hanning(2 * k)
kernel /= kernel.sum()
duck = np.convolve(active, kernel, mode="same")
music_gain = (0.62 - 0.36 * duck)[:, None]

mix = music * music_gain + vo[:, None]
peak_target = 10 ** (-1.0 / 20)
mix = np.tanh(mix * 1.15) / np.tanh(1.15)
mix *= peak_target / np.max(np.abs(mix))

write_wav(ROOT / "audio" / "final_mix.wav", mix, SR)
(ROOT / "timeline.json").write_text(json.dumps(timeline, ensure_ascii=False, indent=1), encoding="utf-8")
rms_db = 20 * np.log10(np.sqrt(np.mean(mix ** 2)))
print("mix ok, rms dBFS:", round(rms_db, 1))
