from pathlib import Path
import urllib.request
with urllib.request.urlopen('https://manaclinic.org/?guide-capture=20261021',timeout=45) as r: Path('output/therapist-guide/live-home.html').write_bytes(r.read())
print('Saved live HTML for browser verification')
