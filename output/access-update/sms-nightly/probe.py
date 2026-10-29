from pathlib import Path
import ftplib,json,io,secrets,hashlib,urllib.request
root=Path.cwd();d=root/'output/access-update/sms-nightly';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32);tag='private-nightly-'+secrets.token_hex(16)
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');f.mkd(tag)
try:
 for file in ['includes/sms_service.php','pages/admin/sms_settings.php','pages/admin/sms_doctor_digest.php','pages/cron_sms.php']:
  buf=io.BytesIO();f.retrbinary('RETR '+file,buf.write);(d/('before-'+file.replace('/','_'))).write_bytes(buf.getvalue())
 php=(d/'probe.php').read_text(encoding='utf-8').replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest());f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php.encode()))
 req=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(req,timeout=60) as resp:result=json.load(resp)
 (d/'probe.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8');print(json.dumps(result,ensure_ascii=True),flush=True)
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
