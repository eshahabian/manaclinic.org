import numpy as np
from PIL import Image

import render as R

t = 34.0
img = R.render_shot(("s3b_light_enters.jpg", 0, 1, 1.06, 1.06, 0.0, 0.0, -0.02, -0.02), 0.5)
img = R.grade(img, 40.0, 0)
ys = np.arange(R.H, dtype=np.float32)
shade = 1 - 0.62 * R.smooth((ys - 950) / 650)
img *= shade[:, None, None]

rgb, a = R.logo_layer(120)
R.overlay(img, rgb, a, R.W / 2, 1560, 1.0)
for key, y in [
    (("cover", "MBSR", 190, 820, (255, 255, 255, 255), None), 1170),
    (("cover", "کاهش استرس مبتنی بر ذهن‌آگاهی", 60, 650, (255, 255, 255, 255), None), 1320),
    (("cover", "دوره جدید  |  کلینیک روان‌شناسی مانا", 44, 520, (240, 214, 160, 255), None), 1420),
]:
    rgb, a = R.cached_layer(key)
    R.overlay(img, rgb, a, R.W / 2, y, 1.0)

out = (np.clip(img, 0, 1) * 255 + 0.5).astype(np.uint8)
Image.fromarray(out).save(R.ROOT / "mana_mbsr_reel_cover.jpg", quality=95, subsampling=0)
Image.fromarray(out[240:1680]).save(R.ROOT / "mana_mbsr_reel_cover_grid_3x4_preview.jpg", quality=90)
print("cover ok")
