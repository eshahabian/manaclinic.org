from pathlib import Path
from ftplib import FTP
from datetime import datetime,timezone,timedelta
import ast,io,json,secrets,time,urllib.request,base64,zipfile,hashlib
root=Path('D:/Psychology')
base=Path('D:/ManaClinic-Backups/manaclinic-full-20261102-113205')
tree=ast.parse((base/'tools/backup-live-site-20261102.py').read_text('utf-8-sig'))
source=next(ast.literal_eval(n.value) for n in tree.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='php' for t in n.targets))
php=source[:source.index('$zip=new ZipArchive();')]
php=php.replace("$home=dirname(__DIR__);$work=$home.'/__WORK__';\nif(!is_dir($work)&&!mkdir($work,0700))throw new RuntimeException('Cannot create private backup workspace');",'')
php=php.replace("fopen($work.'/database.sql','wb')","fopen('php://temp','w+b')")
php=php.replace("(\"'\".$db->real_escape_string((string)$v).\"'\")", "('CONVERT(0x'.bin2hex((string)$v).' USING binary)')")
assert "CONVERT(0x" in php
php=php.replace('fclose($fh);$db->rollback();','rewind($fh);$sql=stream_get_contents($fh);fclose($fh);$db->rollback();')
php+='''
$files=[];foreach(['includes/sms_service.php','pages/admin/sms_doctor_digest.php','includes/outreach.php'] as $p)$files['public_html/'.$p]=base64_encode(file_get_contents(__DIR__.'/'.$p));
echo json_encode(['ok'=>true,'created_tehran'=>date('c'),'database'=>base64_encode($sql),'sql_sha256'=>hash('sha256',$sql),'tables'=>$counts,'files'=>$files],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
'''
token=secrets.token_hex(32);name='backup-sms-'+secrets.token_hex(12)+'.php'
php=php.replace('__TOKEN__',token).replace('__EXPIRY__',str(int(time.time())+600))
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
def get(f,p):
 b=io.BytesIO();f.retrbinary('RETR '+p,b.write);return b.getvalue()
with FTP() as f:
 f.connect(cfg['host'],int(cfg.get('port',21)),timeout=60);f.login(cfg['username'],cfg['password']);f.cwd('/public_html')
 ht=get(f,'.htaccess');mod=ht.replace(b'RewriteEngine On',b'RewriteEngine On\nRewriteRule ^'+name.replace('.',r'\.').encode()+b'$ '+name.encode()+b' [END]',1)
 assert mod!=ht
 try:
  f.storbinary('STOR '+name,io.BytesIO(php.encode()));f.storbinary('STOR .htaccess',io.BytesIO(mod))
  req=urllib.request.Request('https://manaclinic.org/'+name,data=b'',headers={'X-Setup-Token':token},method='POST')
  with urllib.request.urlopen(req,timeout=120) as r:out=json.load(r)
  assert out['ok']
 finally:
  assert get(f,'.htaccess')==mod,'Concurrent routing change'
  f.storbinary('STOR .htaccess',io.BytesIO(ht));f.delete(name);assert get(f,'.htaccess')==ht
 files={p:base64.b64decode(b) for p,b in out['files'].items()}
 for p,b in files.items():assert get(f,p.removeprefix('public_html/'))==b,'Live file changed during snapshot'
sql=base64.b64decode(out.pop('database'));assert hashlib.sha256(sql).hexdigest()==out['sql_sha256']
assert sql.endswith(b'-- BACKUP COMPLETE\n')
files['database/manaclinic.sql']=sql
stamp=datetime.now(timezone(timedelta(hours=3,minutes=30))).strftime('%Y%m%d-%H%M%S')
dest=base/('sms-supplement-'+stamp);dest.mkdir()
with zipfile.ZipFile(base/'manaclinic-full.zip') as old:
 changed=[p for p in out['files'] if old.read(p).replace(b'\r\n',b'\n')!=files[p].replace(b'\r\n',b'\n')]
meta={'created_tehran':out['created_tehran'],'base_backup':'../manaclinic-full.zip','changed_source_files':changed,'tables':len(out['tables']),'rows':sum(out['tables'].values()),'restore':'Restore the base archive first, overwrite its three SMS source files with this supplement, and import ONLY the full database SQL from this supplement instead of the older SQL. This is a supplement, not a standalone website backup.','scope':'Current three SMS PHP files and a full consistent read-only database snapshot, including SMS settings and outbox history.','full_restore_test':False}
files['SUPPLEMENT_INFO.json']=json.dumps(meta,ensure_ascii=False,indent=2).encode()
archive=dest/'sms-supplement.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
 for p,b in files.items():z.writestr(p,b)
with zipfile.ZipFile(archive) as z:
 assert z.testzip() is None
 for p,b in files.items():assert hashlib.sha256(z.read(p)).digest()==hashlib.sha256(b).digest()
verified={**meta,'status':'VERIFIED','sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'bytes':archive.stat().st_size,'temporary_server_files_removed':True}
(dest/'VERIFIED.json').write_text(json.dumps(verified,ensure_ascii=False,indent=2),'utf-8')
(dest/'SHA256SUMS.txt').write_text(verified['sha256']+'  sms-supplement.zip\n','ascii')
(base/'LATEST-SUPPLEMENT.txt').write_text(str(archive)+'\n'+meta['restore']+'\n','utf-8')
print(json.dumps({'archive':str(archive),**verified},ensure_ascii=True))
