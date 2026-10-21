from pathlib import Path
import ftplib,io,json,urllib.request
r=Path('D:/Psychology');w=r/'output/test-grid-three';c=json.loads((r/'.vscode/sftp.json').read_text('utf-8-sig'))
files=['assets/css/psych-tests.css','pages/tests.php'];norm=lambda b:b.replace(b'\r\n',b'\n')
with ftplib.FTP() as f:
 f.connect(c['host'],int(c.get('port',21)),timeout=40);f.login(c['username'],c['password']);f.cwd('/public_html')
 def get(n):
  b=io.BytesIO();f.retrbinary('RETR '+n,b.write);return b.getvalue()
 old={n:get(n) for n in files}
 for n in files:assert norm(old[n])==norm((w/Path(n).name).read_bytes()),'Concurrent remote change: '+n
 done=[]
 try:
  for n in files:
   data=(r/'cpanel'/n).read_bytes();f.storbinary('STOR '+n,io.BytesIO(data));done.append(n);assert get(n)==data
  css=urllib.request.urlopen('https://manaclinic.org/assets/css/psych-tests.css?v=3',timeout=45).read().decode();assert 'repeat(3,minmax(0,1fr))' in css and 'repeat(4,minmax(0,1fr))' not in css
  html=urllib.request.urlopen('https://manaclinic.org/tests',timeout=45).read().decode();assert 'psych-tests.css?v=3' in html and html.count('class="psych-test-tile-image"')==10
  print('PASS three-column desktop layout published; ten tiles preserved')
 except Exception:
  for n in reversed(done):f.storbinary('STOR '+n,io.BytesIO(old[n]))
  raise
