from pathlib import Path
import ftplib,json,io,secrets,urllib.request,hashlib
r=Path.cwd();c=json.loads((r/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));token=secrets.token_hex(32)
source=r'''<?php
if(!hash_equals('TOKEN_HASH',hash('sha256',$_SERVER['HTTP_X_DIAGNOSTIC_TOKEN']??''))){http_response_code(403);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$root=dirname(__DIR__);$config=require $root.'/config.php';require $root.'/includes/db.php';require $root.'/includes/helpers.php';require $root.'/includes/user_phone.php';$p=db_connect($config);
$plan=json_decode('[{"source": "1f008475b2cb4843c1c79fcb", "target": "ae5b4c229636eb258d922c48", "name": "نازنین حسینی پور", "target_username": "nhoseynipoor1"}, {"source": "2cf71f656e2441b635ffac35", "target": "4150adb4876189617196c398", "name": "سمیه ثنایی", "target_username": "sthetwo"}, {"source": "42955c84b16ef33cd5d3fda9", "target": "d49fb302f9e83b931c3a9cc7", "name": "سعید حجتی", "target_username": "shjti"}, {"source": "51d105ffc3f68d34dba258f8", "target": "eb83a8b94ae31855d8d9f92d", "name": "پریسا قانع", "target_username": "pghana"}, {"source": "7081127fe666491be9fbf199", "target": "805d9bcaff67096f910cdeed", "name": "مهبد محمدي", "target_username": "mmohammadi"}, {"source": "695a8589e05dfe4acd373b1a", "target": "805d9bcaff67096f910cdeed", "name": "مهبد محمدي", "target_username": "mmohammadi"}, {"source": "8e8a49f83e5d5efbfa13ff9e", "target": "ca4c5630b6599748fdae8968", "name": "رضا پرستار", "target_username": "rprstar"}, {"source": "ab82f6a261390c5159ee2bfe", "target": "e3bbe6b3f7bd1b3fa6864cfa", "name": "سعید برزگری", "target_username": "sbrzgri"}]',true);
$cols=$p->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME REGEXP '(^|_)(user|patient|sender|recipient|actor|author|owner|created_by|updated_by|approved_by|recorded_by)(_id)?$' OR COLUMN_NAME LIKE '%user_id' OR COLUMN_NAME LIKE '%patient_id')")->fetchAll();$out=[];
foreach($plan as $pair){$r=['name'=>$pair['name'],'target_username'=>$pair['target_username'],'references'=>[]];foreach($cols as $c){$t=$c['TABLE_NAME'];$col=$c['COLUMN_NAME'];$q=$p->prepare("SELECT `$col`,COUNT(*) AS n FROM `$t` WHERE `$col` IN (?,?) GROUP BY `$col`");$q->execute([$pair['source'],$pair['target']]);$counts=$q->fetchAll();if($counts)$r['references'][$t.'.'.$col]=$counts;}$w=$p->prepare('SELECT user_id,balance,held_balance,(SELECT COUNT(*) FROM wallet_transactions wt WHERE wt.wallet_id=w.id) AS tx_count FROM wallets w WHERE user_id IN (?,?)');$w->execute([$pair['source'],$pair['target']]);$r['wallets']=$w->fetchAll();$out[]=$r;}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>'''
source=source.replace('TOKEN_HASH',hashlib.sha256(token.encode()).hexdigest())
f=ftplib.FTP(c['host'],timeout=45);f.login(c['username'],c['password']);f.cwd('/public_html');tag='private-sms-check-'+secrets.token_hex(12);f.mkd(tag)
try:
 f.storbinary('STOR '+tag+'/index.php',io.BytesIO(source.encode()))
 request=urllib.request.Request('https://manaclinic.org/'+tag+'/',headers={'X-Diagnostic-Token':token})
 with urllib.request.urlopen(request,timeout=45) as response:data=response.read().decode('utf-8-sig')
 (r/'output/access-update/duplicate-users-reference-counts.json').write_text(data,encoding='utf-8');print("Schema audit saved")
finally:
 f.delete(tag+'/index.php');f.rmd(tag);f.quit()
