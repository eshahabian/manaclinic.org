# تیزر ۶۰ ثانیه‌ای MBSR — کلینیک مانا

خروجی: `mana_mbsr_reel_60s.mp4` — 1080×1920، 30fps، H.264 High، AAC 48kHz استریو، 60.0s، حدود 10Mbps.

## روش تولید

- سرویس تولید ویدیوی هوش مصنوعی (Runway / Kling / Veo / Sora) و کلید API در پروژه موجود نبود.
- فریم‌ها: تصاویر سینمایی تولیدشده با هوش مصنوعی (۱۳ نما، 9:16) + حرکت آرام دوربین (push-in / pan)، crossfade، grain فیلم، vignette و رنگ‌بندی سرد ← گرم در Python.
- گویندگی: Microsoft Edge neural TTS، صدای `fa-IR-DilaraNeural` (rate −6%، سکانس پنجم +4%).
- موسیقی: کاملاً اورجینال و الگوریتمی (`music.py`) — پیانو و پد، بدون نمونه صوتی شخص ثالث.
- زیرنویس: Vazirmatn، شکل‌دهی با arabic-reshaper + python-bidi، زمان‌بندی از word boundary گویندگی.
- encode: FFmpeg 3.0 (libx264 / aac).

## زمان‌بندی نماها

| زمان | نما | فایل |
|---|---|---|
| 0.0–3.7 | خیابان شلوغ تهران، هوای خاکستری | `s1a_tehran_street.jpg` |
| 3.1–6.7 | مرد میانسال، نور سرد گوشی در شب | `s1b_phone_news.jpg` |
| 6.1–9.7 | خانواده و قبض‌ها، سکوت | `s1c_family_bills.jpg` |
| 9.1–12.8 | شخصیت اصلی کنار پنجره در شب | `s1d_window_night.jpg` |
| 12.2–16.4 | زن خسته، راه‌پله، برگشت به خانه | `s2a_woman_home.jpg` |
| 15.8–20.0 | مرد جوان خیره به نقطه‌ای | `s2b_man_staring.jpg` |
| 19.4–24.6 | بیداری در تخت، نمای ثابت از بالا | `s2c_bed_awake.jpg` |
| 24.6–31.4 | شخصیت اصلی، چشم بسته، سپیده‌دم سرد | `s3a_window_dawn.jpg` |
| 29.0–36.6 | ورود نور صبح (crossfade ۲.۴ ثانیه) | `s3b_light_enters.jpg` |
| 36.0–40.7 | جلسه گروهی ذهن‌آگاهی + کارت MBSR | `s4a_group_session.jpg` |
| 40.1–44.5 | تمرین توجه به تنفس | `s4b_breathing.jpg` |
| 43.9–48.7 | راه رفتن آگاهانه | `s4c_mindful_walking.jpg` |
| 48.1–60.0 | شخصیت اصلی در روشنایی صبح + کارت پایانی (از 52.4) | `s5a_morning_calm.jpg` |

گویندگی: s1 0.8–9.0 · s2 12.5–20.5 · s3 25.0–34.5 · s4 36.6–45.9 · s5 46.9–59.5

## پرامپت‌ها

پرامپت‌های تصویری دقیقاً همان‌هایی است که برای ساخت فریم‌ها استفاده شد. پرامپت‌های ویدیویی برای وقتی است که به سرویس image-to-video دسترسی پیدا کنید؛ هر فریم را به‌عنوان first frame بدهید.

**سبک مشترک (به انتهای همه پرامپت‌ها اضافه شود):** cinematic documentary realism, 35mm, shallow depth of field, soft film grain, natural Iranian faces, no text, no logos, no religious symbols, no exaggerated expressions.

### سکانس ۱ — فشار زندگی (0–12)
- **s1a تصویر:** Busy Tehran street on an overcast grey day, dense traffic, yellow taxis, pedestrians in coats, shop fronts with blurred unreadable Persian signage, hazy Alborz mountains behind smog, telephoto compression, cold desaturated grey-blue grade.
- **s1a ویدیو:** Slow telephoto push-in, traffic crawling, pedestrians walking, slight heat haze, 4s, no camera shake.
- **s1b تصویر:** Iranian man in his 40s, short beard, tired eyes, dim living room at night, face lit only by the cold glow of a smartphone turned away from camera.
- **s1b ویدیو:** Static close-up, thumb scrolls slowly, eyes move, subtle breath, light flicker from screen, 4s.
- **s1c تصویر:** Middle-class Iranian couple in their 40s at a small kitchen table at night reviewing paper bills, calculator, two glasses of tea, teenage daughter softly out of focus in doorway.
- **s1c ویدیو:** Very slow dolly-in, wife turns a page, husband rubs forehead, no dialogue, 4s.
- **s1d تصویر:** Same woman (main character), grey knit sweater, sitting by a large apartment window at night, knees pulled up, looking at Tehran city lights, tired.
- **s1d ویدیو:** Slow push-in from behind shoulder, city lights twinkling, she blinks slowly, 4s.

### سکانس ۲ — فشارهای پنهان (12–24)
- **s2a تصویر:** Tired Iranian woman around 30, dark manteau, loose grey headscarf, shoulder bag, unlocking her apartment door in a dim old stairwell, fluorescent light.
- **s2a ویدیو:** Handheld-feel but stable, she turns the key and pauses, exhales, 4s.
- **s2b تصویر:** Extreme close-up of an Iranian man in his late 20s, stubble, staring at a fixed point, still, soft grey side light.
- **s2b ویدیو:** Locked-off shot, almost no motion, a single slow blink, 4s.
- **s2c تصویر:** Top-down view, Iranian woman in her 30s lying awake in bed at night, eyes open toward the ceiling, cold blue light through curtains.
- **s2c ویدیو:** Locked top-down shot slowly settling to stillness, curtain moves slightly, 5s.

### سکانس ۳ — نقطه تغییر (24–36)
- **s3a تصویر:** Main character on a cushion by a large window before sunrise, eyes gently closed, hands resting on lap, natural breathing, no meditation pose, cool blue-grey dawn light.
- **s3a ویدیو:** Static, chest rises and falls naturally, the sky outside brightens very slowly, 6s.
- **s3b تصویر:** Same woman, medium close-up, eyes closed, first warm golden sunrise light falling across her cheek, dust particles in the beam, colors shifting from cool grey to warm.
- **s3b ویدیو:** Sunlight slowly sweeps across her face, gentle slow push-in, 7s.

### سکانس ۴ — معرفی MBSR (36–49)
- **s4a تصویر:** Small mindfulness group of eight Iranian adults of different ages in a bright minimal clinic room, sitting on chairs in a circle, feet flat, eyes closed or gaze lowered, a female instructor among them, plants, wooden floor, no candles or incense.
- **s4a ویدیو:** Slow overhead crane-down drift, everyone still and breathing, 5s.
- **s4b تصویر:** Iranian man in his 50s, one hand on chest and one on abdomen, eyes closed, attention to breathing, warm side light, blurred participants behind.
- **s4b ویدیو:** Close-up, hands rise and fall with natural breath, 4s.
- **s4c تصویر:** Low-angle close view of feet of several adults taking slow deliberate steps on a wooden floor, warm morning light and long shadows.
- **s4c ویدیو:** Slow lateral tracking at floor level, one foot lifts and places slowly, 5s.

### سکانس ۵ — دعوت (49–60)
- **s5a تصویر:** Same woman, same window, full natural morning daylight, holding a glass of tea with both hands, calm and grounded real expression, slight tiredness still visible, ordinary hazy Tehran outside.
- **s5a ویدیو:** Slow push-in, steam from tea, she takes one calm breath, 6s; then hold for the end card.

## متن روی تصویر

- 37.0–47.9: `MBSR` / `Mindfulness-Based Stress Reduction` / «کاهش استرس مبتنی بر ذهن‌آگاهی»
- 52.4–60.0: لوگوی مانا، «دوره جدید MBSR»، «کلینیک روان‌شناسی مانا»، «تمرین آگاهی، در روزهای دشوار»، «اطلاعات و ثبت‌نام»، تلفن‌های ثبت‌شده در `cpanel/includes/seo.php`، اینستاگرام `mana_clinic`، «تهران، سعادت‌آباد».
- تاریخ شروع، ظرفیت، تعداد جلسات و نام مدرس عمداً نیامده، چون در پروژه داده تأییدشده‌ای برای این دوره پیدا نشد.

## ساخت دوباره

```powershell
python tts.py            # گویندگی (اینترنت لازم است)
python music.py          # موسیقی اورجینال
python mix.py            # میکس، ducking و نرمال‌سازی
python render.py         # رندر ۱۸۰۰ فریم و encode
python render.py --preview "2,26,42,58.5"   # فقط پیش‌نمایش
python cover.py          # کاور
```
