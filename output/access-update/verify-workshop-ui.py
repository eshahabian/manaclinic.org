from pathlib import Path
import ftplib,io,json,secrets,urllib.request
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='workshop-ui-'+secrets.token_hex(12);f.mkd(tag)
try:
 for remote,local in [('index.php','output/access-update/workshop-ui-fixture.php'),('style.css','cpanel/assets/css/style.css')]:f.storbinary('STOR '+tag+'/'+remote,io.BytesIO((r/local).read_bytes()))
 with urllib.request.urlopen('https://manaclinic.org/'+tag+'/',timeout=45) as response:html=response.read().decode('utf-8-sig')
 assert 'wcard-demo-member' in html and 'demo-admin' in html
 out=r/'output/workshop-ui';out.mkdir(exist_ok=True)
 html=html.replace('href="style.css"','href="'+(r/'cpanel/assets/css/style.css').as_uri()+'"')
 (out/'fixture.html').write_text(html,encoding='utf-8');print('PASS real workshop template renders synthetic member fixture')
finally:
 for name in ['index.php','style.css']:f.delete(tag+'/'+name)
 f.rmd(tag);f.quit()
