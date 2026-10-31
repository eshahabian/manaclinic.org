import urllib.request, urllib.error, re, json, time
urls=['https://manaclinic.org/','https://manaclinic.org/robots.txt','https://manaclinic.org/sitemap.xml','http://manaclinic.org/','https://www.manaclinic.org/']
for url in urls:
 t=time.time()
 try:
  req=urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'})
  with urllib.request.urlopen(req,timeout=25) as r:
   body=r.read().decode('utf-8','replace')
   out={'url':url,'final':r.url,'status':r.status,'seconds':round(time.time()-t,2),'xrobots':r.headers.get('X-Robots-Tag'),'title':re.findall(r'<title[^>]*>(.*?)</title>',body,re.S),'robots':re.findall(r'<meta[^>]+name=[\"\x27]robots[^>]*>',body),'canonical':re.findall(r'<link[^>]+rel=[\"\x27]canonical[^>]*>',body),'h1':re.findall(r'<h1[^>]*>(.*?)</h1>',body,re.S)}
   if 'robots.txt' in url:out['body']=body
   if 'sitemap.xml' in url:out['locations']=re.findall(r'<loc>(.*?)</loc>',body)[:5];out['count']=body.count('<loc>')
   print(json.dumps(out,ensure_ascii=False),flush=True)
 except Exception as e:print(json.dumps({'url':url,'error':str(e)}),flush=True)
