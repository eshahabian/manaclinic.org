from pathlib import Path
s=Path('output/therapist-guide/deploy-v2.py').read_text(encoding='utf-8-sig')
s=s.replace("files=['assets/img/therapist-guide-cartoon-v2.webp','assets/css/therapist-guide.css','includes/home_therapist_guide.php','pages/home.php']", "files=['assets/css/therapist-guide.css','includes/home_therapist_guide.php']")
s=s.replace('v2-baseline','v3-baseline').replace('?v=20261021b','?v=20261021c')
s=s.replace(' for n in files[:2]:',' for n in files[:1]:')
s=s.replace(" assert 'therapist-guide-cartoon-v2.webp' in home", " assert 'therapist-guide-cartoon-v2.webp' in home\n assert 'class=\"mana-guide-question\"' in home\n assert 'برای من مناسب‌تره…</span>' in home")
s=s.replace(".write_text(home,encoding='utf8')", ".write_text(home.replace('\\r',''),encoding='utf8',newline='\\n')")
Path('output/therapist-guide/deploy-v3.py').write_text(s,encoding='utf8')
