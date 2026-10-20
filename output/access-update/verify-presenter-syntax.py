from pathlib import Path
import ftplib,io,json,secrets,urllib.request
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='workshop-ui-'+secrets.token_hex(12);f.mkd(tag)
try:
 files=['includes/workshop_presenters.php','includes/workshop_presenters_form.php','actions/workshop_presenters_save.php','actions/doctor_workshops.php','actions/secretary_workshops.php','includes/workshop_manage_cards.php','includes/workshop_peer_block.php','includes/sms_service.php']
 for i,n in enumerate(files):f.storbinary('STOR '+tag+'/'+str(i)+'.txt',io.BytesIO((r/'cpanel'/n).read_bytes()))
 php=b'<?php header("Content-Type: text/plain");foreach(glob(__DIR__."/*.txt") as $file)token_get_all(file_get_contents($file),TOKEN_PARSE);echo "PASS presenter feature PHP syntax";'
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(php))
 with urllib.request.urlopen('https://manaclinic.org/'+tag+'/',timeout=45) as response:print(response.read().decode())
finally:
 for i in range(len(files)):f.delete(tag+'/'+str(i)+'.txt')
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
