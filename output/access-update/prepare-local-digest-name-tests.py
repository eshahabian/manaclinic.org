import ast
from pathlib import Path
p=Path('D:/Psychology/output/access-update/deploy-digest-patient-names-20261102.py')
t=ast.parse(p.read_text('utf-8'))
s=next(n.value for n in ast.walk(t) if isinstance(n,ast.Constant) and isinstance(n.value,str) and n.value.startswith('<?php'))
s='\n'.join(line for line in s.splitlines() if not line.startswith('if(('))
s=s.replace("json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR)","['mode'=>'test']")
s=s.replace("foreach(['__STAGE__','__STAGE__.admin.php'] as $path)token_get_all(file_get_contents(__DIR__.'/'.$path),TOKEN_PARSE);","foreach(['D:/Psychology/cpanel/includes/sms_service.php','D:/Psychology/cpanel/pages/admin/sms_doctor_digest.php'] as $path)token_get_all(file_get_contents($path),TOKEN_PARSE);")
s=s.replace("__DIR__.'/includes/helpers.php'","'D:/Psychology/cpanel/includes/helpers.php'").replace("__DIR__.'/__STAGE__'","'D:/Psychology/cpanel/includes/sms_service.php'")
Path('D:/Psychology/output/access-update/test-digest-patient-names.php').write_text(s,encoding='utf-8')
