const fs=require('fs');const {PhpNode}=require('./tools/node_modules/php-wasm/PhpNode');const {DatabaseSync}=require('node:sqlite');
(async()=>{
 const php=new PhpNode({version:'8.3'});let output='';php.addEventListener('output',e=>output+=e.detail.join(''));
 await php.mkdir('/app');for(const f of ['video_call.php','doctor_profile_fields.php','helpers.php','seo.php'])await php.writeFile('/app/'+f,fs.readFileSync('cpanel/includes/'+f));
 const result=await php.run(`<?php
 date_default_timezone_set('Asia/Tehran');require '/app/helpers.php';require '/app/video_call.php';
 class CapturedStatement extends PDOStatement{public function __construct(public string $sql){}public function execute(?array $params=null):bool{$GLOBALS['queries'][]=['sql'=>$this->sql,'params'=>$params];return true;}public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return false;}}
 class CapturedDatabase extends PDO{public function __construct(){}public function prepare(string $sql,array $opts=[]):PDOStatement|false{return new CapturedStatement($sql);}}
 $pdo=new CapturedDatabase();$queries=[];$room=['host_user_id'=>'doctor','room_key'=>video_call_direct_key('doctor','patient'),'kind'=>'direct'];
 $start=strtotime('2026-10-18 15:00:00');foreach([$start-901,$start-900,$start,$start+3600,$start+3601] as $now)video_call_secretary_appointment($pdo,$room,'patient',true,$now);
 video_call_secretary_appointment($pdo,$room,'patient',false,$start-86400);
 if(video_call_room_membership_allowed($pdo,['id'=>'pat','role'=>'PATIENT'],$room))throw new Exception('Substring membership bypass');
 if(!video_call_room_membership_allowed($pdo,['id'=>'patient','role'=>'PATIENT'],$room))throw new Exception('Participant membership lost');
 $config=['app_url'=>'https://manaclinic.org'];$base='';$link=video_call_share_url(['share_token'=>'abc123']);
 if($link!=='https://manaclinic.org/video-call-live?join=abc123&joincall=1')throw new Exception('Share URL incorrect');
 $_SERVER['REQUEST_URI']='/video-call-live?join=abc123&joincall=1';$login=login_url_keeping_staff_app('');parse_str(parse_url($login,PHP_URL_QUERY),$q);
 if(($q['next']??'')!==$_SERVER['REQUEST_URI'])throw new Exception('Login loses invitation');
 echo json_encode($queries);
 `);
 if(result!==0)throw Error(output);
 const queries=JSON.parse(output).filter(q=>q.sql.startsWith('SELECT a.id')),db=new DatabaseSync(':memory:');
 db.exec(`CREATE TABLE users(id TEXT,role TEXT);CREATE TABLE doctor_profiles(id TEXT,user_id TEXT);CREATE TABLE appointments(id TEXT,doctor_id TEXT,patient_id TEXT,created_by_user_id TEXT,session_mode TEXT,status TEXT,starts_at TEXT,ends_at TEXT);
 INSERT INTO users VALUES('secretary','SECRETARY'),('patient','PATIENT');INSERT INTO doctor_profiles VALUES('profile','doctor');
 INSERT INTO appointments VALUES('appointment','profile','patient','secretary','ONLINE','CONFIRMED','2026-10-18 15:00:00','2026-10-18 16:00:00');`);
 let checks=4;const test=(ok,msg)=>{if(!ok)throw Error(msg);checks++};const rows=q=>db.prepare(q.sql).all(...q.params);
 for(const [i,expected] of [[0,0],[1,1],[2,1],[3,1],[4,0],[5,1]])test(rows(queries[i]).length===expected,'Schedule boundary '+i);
 db.exec("UPDATE appointments SET status='CANCELLED'");test(rows(queries[2]).length===0,'Cancelled appointment denied');
 db.exec("UPDATE appointments SET status='CONFIRMED',created_by_user_id='patient'");test(rows(queries[2]).length===0,'Non-secretary booking denied');
 db.exec("UPDATE appointments SET created_by_user_id='secretary',session_mode='IN_PERSON'");test(rows(queries[2]).length===0,'In-person appointment denied');
 console.log('PASS '+checks+' invitation/login, membership and appointment timing checks');
})().catch(e=>{console.error(e);process.exitCode=1});
