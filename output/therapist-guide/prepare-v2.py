from pathlib import Path
s=Path('output/therapist-guide/deploy.py').read_text(encoding='utf-8-sig')
s=s.replace("files=['assets/css/therapist-guide.css','assets/js/therapist-guide.js','includes/home_therapist_guide.php','pages/home.php']", "files=['assets/img/therapist-guide-cartoon-v2.webp','assets/css/therapist-guide.css','includes/home_therapist_guide.php','pages/home.php']")
start=s.index("  if n=='pages/home.php':")
end=s.index(" print('PASS live baseline",start)
s=s[:start]+"  baseline=root/'output/therapist-guide/v2-baseline'/n.replace('/','_')\n  if not baseline.exists() or norm(baseline.read_bytes())!=norm(b):raise RuntimeError('Remote changed; merge required: '+n)\n"+s[end:]
s=s.replace("'?v=20261021a'","'?v=20261021b'")
s=s.replace(" for n in files[:2]:assert get('/'+n+'?v=20261021b')==(root/'cpanel'/n).read_text(encoding='utf8')", " for n in files[:2]:\n  with urllib.request.urlopen('https://manaclinic.org/'+n+'?v=20261021b',timeout=45) as r:assert r.read()==(root/'cpanel'/n).read_bytes()\n assert 'therapist-guide-cartoon-v2.webp' in home\n assert 'section home-content' in home\n (root/'output/therapist-guide/live-home.html').write_text(home,encoding='utf8')")
Path('output/therapist-guide/deploy-v2.py').write_text(s,encoding='utf8')
