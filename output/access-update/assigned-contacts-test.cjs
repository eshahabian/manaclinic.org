const fs=require('fs');const {PhpNode}=require('./tools/node_modules/php-wasm/PhpNode');const {DatabaseSync}=require('node:sqlite');
(async()=>{
 const php=new PhpNode({version:'8.3'});let output='';php.addEventListener('output',e=>output+=e.detail.join(''));
 await php.mkdir('/app');for(const f of ['video_call.php','doctor_profile_fields.php'])await php.writeFile('/app/'+f,fs.readFileSync('cpanel/includes/'+f));
 const result=await php.run(`<?php
 require '/app/video_call.php';
 class QueryStatement extends PDOStatement{public function __construct(public string $sql){} public function execute(?array $params=null):bool{$GLOBALS['queries'][]=['sql'=>$this->sql,'params'=>$params];return true;}public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}}
 class QueryDatabase extends PDO{public function __construct(){}public function prepare(string $sql,array $options=[]):PDOStatement|false{return new QueryStatement($sql);}}
 $pdo=new QueryDatabase();$queries=[];$doctor=['id'=>'docA','role'=>'DOCTOR','name'=>'Regular'];
 video_call_contacts($pdo,$doctor);
 video_call_contacts($pdo,$doctor,'sam');
 video_call_contacts($pdo,$doctor,'','PATIENT',1,['other']);
 foreach([['id'=>'shiva','role'=>'DOCTOR','username'=>'shgeranmaye'],['id'=>'atieh','role'=>'DOCTOR','name'=>'عطیه گارسچی'],['id'=>'emad','role'=>'ADMIN','username'=>'eshahabian']] as $lead){video_call_contacts($pdo,$lead,'','PATIENT');}
 $count=count($queries);if(video_call_contacts($pdo,$doctor,'','DOCTOR')!==[]||count($queries)!==$count)throw new Exception('Staff search leaked');
 if(video_call_ensure_direct_room($pdo,$doctor,'other')!==null)throw new Exception('Direct call bypass');
 try{video_call_create_group($pdo,$doctor,'Test',['other']);throw new Exception('Group bypass');}catch(RuntimeException $e){}
 echo json_encode($queries);
 `);
 if(result!==0)throw Error(output);
 const queries=JSON.parse(output);const db=new DatabaseSync(':memory:');
 db.function('IF',(x,a,b)=>x?a:b);db.function('CONCAT',{varargs:true},(...a)=>a.join(''));db.function('SUBSTRING_INDEX',(s,d,n)=>n>0?s.split(d).slice(0,n).join(d):s.split(d).slice(n).join(d));
 db.exec(`CREATE TABLE users(id TEXT,name TEXT,username TEXT,role TEXT,email TEXT,phone TEXT,preferred_doctor_id TEXT);CREATE TABLE doctor_profiles(id TEXT,user_id TEXT,avatar_url TEXT);CREATE TABLE video_call_contact_stats(host_user_id TEXT,peer_user_id TEXT,call_count INTEGER,last_at TEXT);CREATE TABLE video_call_room_members(room_id TEXT,user_id TEXT);CREATE TABLE video_call_rooms(id TEXT,host_user_id TEXT,kind TEXT,room_key TEXT,created_at TEXT);CREATE TABLE appointments(patient_id TEXT,doctor_id TEXT,starts_at TEXT,status TEXT);
 INSERT INTO doctor_profiles VALUES('profileA','docA',''),('profileB','docB','');
 INSERT INTO users VALUES('own','sam own','sam1','PATIENT','','','profileA'),('other','sam other','sam2','PATIENT','','','profileB'),('none','unassigned','unassigned','PATIENT','','',NULL),('docB','Doctor B','doctorb','DOCTOR','','',NULL);
 INSERT INTO appointments VALUES('other','profileA','2026-01-01','COMPLETED');
 INSERT INTO video_call_contact_stats VALUES('docA','other',999,'2026-10-17');`);
 let checks=0;const check=(ok,msg)=>{if(!ok)throw Error(msg);checks++};
 const ids=q=>db.prepare(q.sql).all(...q.params).map(r=>r.id).sort();
 check(JSON.stringify(ids(queries[0]))==='["own"]','Default contacts must only use current assignment');
 check(JSON.stringify(ids(queries[1]))==='["own"]','Search cannot expose other assigned patients');
 check(ids(queries[2]).length===0,'Forged patient IDs denied');
 for(const q of queries.slice(3,6))check(ids(q).length===3,'Each named exception sees all patients');
 check(queries.length===8,'Direct and group bypasses checked');
 console.log('PASS '+checks+' SQL assignment checks plus PHP staff-search, direct-call and group bypass checks');
})().catch(e=>{console.error(e);process.exitCode=1});
