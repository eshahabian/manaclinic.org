import urllib.request,urllib.error,xml.etree.ElementTree as ET,json,time,concurrent.futures
from pathlib import Path
import re,html
from html.parser import HTMLParser
class Document(HTMLParser):
 def __init__(self,source):
  super().__init__();self.tags=[];self.feed(source)
 def handle_starttag(self,tag,attrs):self.tags.append((tag,dict(attrs)))
 def all(self,tag):return [attrs for t,attrs in self.tags if t==tag]
from urllib.parse import urljoin,urlparse
out=Path('D:/Psychology/output/seo-review-2026-10-21')
def fetch(url):
 start=time.time()
 try:
  resp=urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'ManaClinicSEOReview/1.0'}),timeout=40)
 except urllib.error.HTTPError as e:resp=e
 data=resp.read();return resp.status,resp.geturl(),dict(resp.headers),data,round(time.time()-start,2)
base='https://manaclinic.org'
for path,name in [('/robots.txt','robots.txt'),('/sitemap.xml','sitemap.xml')]:
 status,url,headers,data,secs=fetch(base+path);(out/name).write_bytes(data);print(name,status,secs,flush=True)
urls=[x.text for x in ET.fromstring((out/'sitemap.xml').read_bytes()).findall('{*}url/{*}loc')]
print('Sitemap URLs',len(urls),flush=True)
def inspect(url):
 try:
  status,final,headers,data,seconds=fetch(url);source=data.decode('utf-8-sig');doc=Document(source)
  clean=lambda text:html.unescape(re.sub('<[^>]+>',' ',text)).strip()
  meta=lambda key:next((e.get('content','') for e in doc.all('meta') if e.get('name')==key),'')
  canonical=next((e.get('href','') for e in doc.all('link') if e.get('rel')=='canonical'),'')
  schemas=[]
  for block in re.findall(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',source,re.S):
   try:schemas.append(json.loads(block))
   except:schemas.append({'error':'invalid json'})
  main=re.search(r'<main[^>]*>(.*?)</main>',source,re.S);main=main.group(1) if main else source
  text=clean(re.sub(r'<(script|style|header|footer|nav)\b[^>]*>.*?</\1>','',main,flags=re.S))
  title=re.search('<title>(.*?)</title>',source,re.S)
  record={'url':url,'status':status,'final':final,'seconds':seconds,'html_bytes':len(data),'title':clean(title.group(1)) if title else '', 'description':meta('description'),'robots':meta('robots'),'canonical':canonical,'h1':[clean(e) for e in re.findall(r'<h1\b[^>]*>(.*?)</h1>',source,re.S)],'text_chars':len(text),'text_sample':text[:1200],'schema':schemas,'images':[{'src':e.get('src'),'loading':e.get('loading'),'width':e.get('width'),'height':e.get('height'),'alt':e.get('alt')} for e in doc.all('img')], 'links':list(set(urljoin(final,e['href']) for e in doc.all('a') if 'href' in e)),'cache':headers.get('Cache-Control'),'content_encoding':headers.get('Content-Encoding')}
  if '/articles/' in url or '/doctors/' in url or url in [base+'/',base+'/about',base+'/contact']: (out/(str(urls.index(url)) if url in urls else 'extra')+'.html').write_bytes(data)
  return record
 except Exception as e:return {'url':url,'error':str(e)}
selected=urls[:75]
selected+= [base+'/tests/bai',base+'/not-a-real-page-seo-review',base+'/login']
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:results=list(pool.map(inspect,selected))
(out/'audit.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
for r in results: print(json.dumps({k:r.get(k) for k in ['url','status','error','title','robots','canonical','h1','text_chars','seconds','html_bytes']},ensure_ascii=False),flush=True)
