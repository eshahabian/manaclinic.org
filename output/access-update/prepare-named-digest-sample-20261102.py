from pathlib import Path
import subprocess,json,base64
root=Path('D:/Psychology')
render=root/'output/access-update/render-named-digest-sample.php'
render.write_text('''<?php
require 'D:/Psychology/cpanel/includes/helpers.php';
require 'D:/Psychology/cpanel/includes/sms_service.php';
$pdo=null;
echo json_encode(['body'=>sms_doctor_daily_digest_body('درمانگر نمونه','۱۱ آبان ۱۴۰۵',['09:30','10:30','13:00'],['09:30 — مراجع نمونه اول','10:30 — مراجع نمونه دوم','13:00 — مراجع نمونه سوم'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
''',encoding='utf-8')
r=subprocess.run(['D:/FaraFarda/.tools/php/php.exe','-d','extension_dir=D:/FaraFarda/.tools/php/ext','-d','extension=mbstring',str(render)],capture_output=True,check=True)
body=json.loads(r.stdout)['body']
s=(root/'output/access-update/test-sms-length-20261102.py').read_text('utf-8')
start=s.index('$base=');end=s.index("$out=['now'",start)
encoded=base64.b64encode(body.encode()).decode()
s=s[:start]+"$messages=['named_sample'=>base64_decode('"+encoded+"')];\n"+s[end:]
s=s.replace("'sms-length-'","'sms-named-sample-'").replace('test-sms-length-20261102.json','test-sms-named-sample-20261102.json')
# One-shot marker prevents duplicate send if the same HTTP request is repeated.
s=s.replace("$fields =", "$fields =")
s=s.replace(" $fields=['From'", " $marker=__DIR__.'/storage/'+0;\n $fields=['From'") if False else s
s=s.replace(" $fields=['From'", " $claim=fopen(__FILE__.'.sent','x');if($claim===false)throw new RuntimeException('Sample already claimed; do not resend');fclose($claim);\n $fields=['From'")
s=s.replace("ftp.delete(name);assert", "ftp.delete(name);ftp.delete(name+'.sent');assert")
# Marker is present only if the request passed authorization and reached sending.
s=s.replace("ftp.delete(name+'.sent');", "\n  try: ftp.delete(name+'.sent')\n  except Exception: pass\n  ")
(root/'output/access-update/test-sms-named-sample-20261102.py').write_text(s,encoding='utf-8')
print(json.dumps({'rendered_chars':len(body),'sample':body},ensure_ascii=True))
