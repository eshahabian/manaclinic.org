from pathlib import Path
s=Path('output/therapist-guide/verify-v2.cjs').read_text(encoding='utf-8-sig')
s=s.replace("body:fs.readFileSync('output/therapist-guide/live-home.html')", "body:fs.readFileSync('output/therapist-guide/live-home.html','utf8').replace(/<span class=\"mana-guide-bubble\">نمی‌دونم[\\s\\S]*?<\\/span>/,fs.readFileSync('cpanel/includes/home_therapist_guide.php','utf8').match(/<div class=\"mana-guide-question\">[\\s\\S]*?<\\/div>/)[0])")
s=s.replace("page.locator('.mana-guide').screenshot({path:'output/therapist-guide/v2-desktop.png'})", "page.locator('.mana-guide-art').screenshot({path:'output/therapist-guide/v3-desktop.png'})")
s=s.replace("page.locator('.mana-guide').screenshot({path:'output/therapist-guide/v2-mobile.png'})", "page.locator('.mana-guide-art').screenshot({path:'output/therapist-guide/v3-mobile.png'})")
Path('output/therapist-guide/preview-v3.cjs').write_text(s,encoding='utf8')
