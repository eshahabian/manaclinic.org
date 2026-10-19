import concurrent.futures
import json
import re
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path

base = 'https://manaclinic.org'
def fetch(url):
    with urllib.request.urlopen(url, timeout=45) as r:
        return r.status, r.read().decode('utf-8-sig')

_, robots = fetch(base + '/robots.txt')
rules = re.findall(r'^Disallow:\s*(\S+)', robots, re.M)
def blocked(path):
    return any(re.match(re.escape(rule).replace(r'\*', '.*').replace(r'\$', '$'), path) for rule in rules)

for path in ['/doctors', '/doctors/dprofile001mana', '/services/couples']:
    assert not blocked(path), path
for path in ['/doctor', '/doctor/profile', '/dashboard', '/app']:
    assert blocked(path), path

_, xml = fetch(base + '/sitemap.xml')
urls = [n.text for n in ET.fromstring(xml).findall('{*}url/{*}loc')]
assert not any('/tests/' in url for url in urls)

def check(url):
    try:
        status, html = fetch(url)
        canonical = re.search(r'<link rel="canonical" href="([^"]+)"', html)
        noindex = bool(re.search(r'<meta name="robots" content="[^"]*noindex', html))
        for raw in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
            json.loads(raw)
        return {'url': url, 'status': status, 'canonical': canonical.group(1) if canonical else None, 'noindex': noindex, 'h1_count': len(re.findall(r'<h1(?:\s|>)', html))}
    except Exception as e:
        return {'url': url, 'error': str(e)}

with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    results = list(pool.map(check, urls))
Path('output/seo-verification.json').write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding='utf-8')
issues = [r for r in results if r.get('status') != 200 or r.get('noindex') or not r.get('canonical') or r.get('h1_count') != 1]
print('Robots: public doctors allowed; private routes remain blocked.')
print('Sitemap pages checked:', len(results))
print('Issues:', json.dumps(issues, ensure_ascii=False))
assert not issues
