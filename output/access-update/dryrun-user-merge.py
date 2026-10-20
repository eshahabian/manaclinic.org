from pathlib import Path
import ftplib,json,io,secrets,urllib.request,urllib.parse,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=(r/'output/access-update/merge-same-name-users.php').read_text(encoding='utf-8')
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token},data=urllib.parse.urlencode({'mode':'dryrun'}).encode())
 with urllib.request.urlopen(request,timeout=45) as response:data=response.read().decode('utf-8-sig')
 (r/'output/access-update/duplicate-users-dryrun.json').write_text(data,encoding='utf-8');print(data.encode("ascii","backslashreplace").decode())
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
