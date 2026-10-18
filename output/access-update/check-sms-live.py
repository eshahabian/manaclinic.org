import urllib.request,urllib.parse,http.cookiejar,re
jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
base='https://manaclinic.org'
with client.open(base+'/login',timeout=45) as r:
 page=r.read().decode();print('Login HTTP',r.status,'OTP option:', 'login/otp' in page)
csrf=re.search(r'name="csrf_token"\s+value="([^"]+)"',page) or re.search(r'name="_csrf"\s+value="([^"]+)"',page)
if not csrf:
 print('CSRF form field names:',re.findall(r'name="([^"]+)"',page)[:12])
else:
 payload=urllib.parse.urlencode({csrf.group(0).split('"')[1]:csrf.group(1),'username':'otp_test_nonexistent','phone':'','next':''}).encode()
 with client.open(urllib.request.Request(base+'/login/otp',data=payload),timeout=45) as r:
  body=r.read().decode();print('Incomplete connection blocks OTP:', 'ورود پیامکی پس از تکمیل تنظیمات' in body)
for path in ['/admin/sms-settings','/config.sms.php','/sms-worker.php']:
 try:
  with client.open(base+path,timeout=45) as r:print(path,r.status,'Login redirect:', '/login' in r.url)
 except urllib.error.HTTPError as e:print(path,e.code)
