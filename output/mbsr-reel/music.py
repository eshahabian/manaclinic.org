"""Original procedural score for the MBSR reel (no third-party samples, no licensing needed)."""
from pathlib import Path

import numpy as np
from scipy_free import fftconvolve, lowpass, write_wav

SR = 48000
DUR = 60.0
N = int(SR * DUR)
rng = np.random.default_rng(7)
ROOT = Path(__file__).parent


def midi_hz(m):
    return 440.0 * 2 ** ((m - 69) / 12)


def piano_note(m, dur, vel=0.6):
    f = midi_hz(m)
    n = int(SR * dur)
    t = np.arange(n) / SR
    sig = np.zeros(n)
    B = 0.0004
    for k in range(1, 9):
        fk = f * k * np.sqrt(1 + B * k * k)
        if fk > SR / 2.2:
            break
        decay = 1.6 + 0.9 * k + f / 400
        amp = (1.0 / k ** 1.25) * (1 if k != 1 else 1.1)
        sig += amp * np.sin(2 * np.pi * fk * t + rng.uniform(0, 6.28)) * np.exp(-t * decay * 0.55)
    attack = np.minimum(1.0, t / 0.006)
    release = np.clip((dur - t) / 0.35, 0, 1)
    hammer = rng.normal(0, 1, n) * np.exp(-t * 90) * 0.04
    return (sig * attack * release + hammer) * vel


def pad_chord(notes, start, dur, gain, out, bright=0.0):
    n = int(SR * dur)
    t = np.arange(n) / SR
    s = np.zeros(n)
    for m in notes:
        f = midi_hz(m)
        for det in (-0.12, 0.0, 0.11):
            ff = f * 2 ** (det / 12)
            s += np.sin(2 * np.pi * ff * t + rng.uniform(0, 6.28))
            s += (0.18 + 0.25 * bright) * np.sin(2 * np.pi * 2 * ff * t + rng.uniform(0, 6.28))
    env = np.sin(np.pi * np.clip(t / dur, 0, 1)) ** 1.5
    i0 = int(start * SR)
    seg = s * env * gain / (len(notes) * 3)
    out[i0:i0 + n] += seg[: max(0, min(n, len(out) - i0))]


def place(buf, sig, start):
    i0 = int(start * SR)
    j = min(len(buf), i0 + len(sig))
    buf[i0:j] += sig[: j - i0]


piano = np.zeros(N)
pad = np.zeros(N)

# Part A (0–24s): sparse A minor, heavy and restrained. ~66 BPM feel.
a_events = [
    (0.6, 57, 0.45), (0.6, 45, 0.35), (2.4, 64, 0.30), (3.8, 60, 0.28),
    (5.2, 57, 0.30), (5.2, 41, 0.33), (7.0, 65, 0.26), (8.3, 60, 0.24),
    (9.8, 55, 0.30), (9.8, 43, 0.32), (11.6, 62, 0.26), (12.9, 59, 0.24),
    (14.4, 57, 0.30), (14.4, 45, 0.33), (16.2, 64, 0.25), (17.5, 60, 0.22),
    (19.0, 53, 0.30), (19.0, 41, 0.30), (20.8, 60, 0.22), (22.2, 64, 0.20),
]
for t0, m, v in a_events:
    place(piano, piano_note(m, 3.6, v), t0)
for (st, ch) in [(0.0, [45, 52, 57]), (4.6, [41, 48, 57]), (9.2, [43, 50, 55]),
                 (13.8, [45, 52, 57]), (18.4, [41, 48, 53])]:
    pad_chord(ch, st, 6.4, 0.20, pad)

# Part B (24–36s): the turn. Space, a suspended chord opening toward major.
b_events = [(24.4, 48, 0.30), (24.4, 64, 0.22), (27.0, 67, 0.22), (29.4, 72, 0.20),
            (31.0, 53, 0.30), (31.0, 69, 0.22), (33.4, 72, 0.20), (34.8, 76, 0.18)]
for t0, m, v in b_events:
    place(piano, piano_note(m, 4.5, v), t0)
pad_chord([48, 55, 62, 67], 23.2, 8.0, 0.22, pad, bright=0.3)
pad_chord([41, 53, 57, 64], 30.0, 7.5, 0.24, pad, bright=0.4)

# Part C (36–60s): warm, hopeful C major arpeggios, F–C–Am–G–F–C.
prog = [(36.0, [41, 53, 57, 60, 65]), (40.0, [48, 55, 60, 64, 67]),
        (44.0, [45, 52, 57, 60, 64]), (48.0, [43, 50, 55, 59, 62]),
        (52.0, [41, 53, 57, 60, 65]), (56.0, [48, 55, 60, 64, 67])]
for st, ch in prog:
    bass, rest = ch[0], ch[1:]
    place(piano, piano_note(bass - 12, 4.2, 0.30), st)
    pattern = [rest[0], rest[1], rest[2], rest[3], rest[2], rest[1]]
    for i, m in enumerate(pattern):
        tt = st + 0.35 + i * 0.6
        if tt < 59.0:
            place(piano, piano_note(m, 2.4, 0.15 + 0.03 * (i == 0)), tt)
    pad_chord([bass] + rest[:3], st - 0.4, 4.9, 0.26, pad, bright=0.6)
place(piano, piano_note(72, 4.0, 0.18), 57.6)

# City ambience: filtered brown-ish noise with slow traffic swells, fades out 18–30s.
noise = rng.normal(0, 1, N)
brown = np.cumsum(noise)
brown = brown - lowpass(brown, 20.0, SR)
brown /= np.max(np.abs(brown)) + 1e-9
hiss = lowpass(rng.normal(0, 1, N), 1800.0, SR)
hiss /= np.max(np.abs(hiss)) + 1e-9
t = np.arange(N) / SR
swell = 0.7 + 0.3 * np.sin(2 * np.pi * 0.07 * t) * np.sin(2 * np.pi * 0.023 * t + 1)
city = (0.8 * brown + 0.25 * hiss) * swell
city_env = np.clip(1 - (t - 18) / 12, 0, 1) ** 1.6
city *= city_env * 0.22

# Reverb: exponentially decaying stereo noise IR.
ir_len = int(SR * 2.8)
it = np.arange(ir_len) / SR
irL = rng.normal(0, 1, ir_len) * np.exp(-it * 2.4)
irR = rng.normal(0, 1, ir_len) * np.exp(-it * 2.4)
irL = lowpass(irL, 5000.0, SR)
irR = lowpass(irR, 5000.0, SR)
irL /= np.sqrt(np.sum(irL ** 2))
irR /= np.sqrt(np.sum(irR ** 2))

dry = piano * 0.9 + pad * 0.8
wetL = fftconvolve(dry, irL)[:N]
wetR = fftconvolve(dry, irR)[:N]
L = dry * 0.75 + wetL * 0.55 + city
R = dry * 0.75 + wetR * 0.55 + city * 0.92

fade_in = np.clip(t / 1.2, 0, 1)
fade_out = np.clip((DUR - t) / 2.5, 0, 1)
L *= fade_in * fade_out
R *= fade_in * fade_out
st = np.stack([L, R], axis=1)
st /= np.max(np.abs(st)) + 1e-9
st *= 0.89
np.save(ROOT / "audio" / "music.npy", st.astype(np.float32))
write_wav(ROOT / "audio" / "music.wav", st, SR)
print("music ok", st.shape)
