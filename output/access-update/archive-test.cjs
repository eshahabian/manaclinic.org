const fs=require('fs'),crypto=require('crypto');const {PhpNode}=require('./tools/node_modules/php-wasm/PhpNode');const {DatabaseSync}=require('node:sqlite');
(async()=>{
 const php=new PhpNode({version:'8.3'});let output='';php.addEventListener('output',e=>output+=e.detail.join(''));await php.mkdir('/app');
 for(const f of ['staff_app.php','staff_app_archive.php','web_push.php','video_call.php'])await php.writeFile('/app/'+f,fs.readFileSync('cpanel/includes/'+f));
 const rc=await php.run(`<?php require '/app/staff_app.php';
 class AuditStatement extends PDOStatement{public function __construct(public string $sql){}public function execute(?array $params=null):bool{$GLOBALS['queries'][]=['sql'=>$this->sql,'params'=>$params];return true;}public function fetchColumn(int $c=0):mixed{return 1;}}
 class AuditDatabase extends PDO{public function __construct(){}public function prepare(string $sql,array $opts=[]):PDOStatement|false{return new AuditStatement($sql);}}
 $pdo=new AuditDatabase();$queries=[];
 foreach(['saved','before_edit','edited','deleted'] as $event)staff_app_archive_capture($pdo,'message',null,$event);
 staff_app_archive_capture($pdo,null,'room','room_deleted');
 staff_app_archive_file_retained($pdo,'stored.jpg');
 try{staff_app_archive_records($pdo,['username'=>'other','role'=>'ADMIN']);throw new Exception('Archive access leaked');}catch(RuntimeException $e){}
 echo json_encode($queries);`);
 if(rc!==0)throw Error(output);const q=JSON.parse(output),db=new DatabaseSync(':memory:');
 db.function('CONCAT',{varargs:true},(...a)=>a.join(''));db.function('SHA2',(s,n)=>crypto.createHash('sha256').update(s).digest('hex'));
 db.exec(`CREATE TABLE users(id TEXT,name TEXT);CREATE TABLE staff_app_rooms(id TEXT,title TEXT);CREATE TABLE staff_app_messages(id TEXT,room_id TEXT,user_id TEXT,body TEXT,reply_to TEXT,forward_from TEXT,created_at TEXT,edited_at TEXT);
 CREATE TABLE staff_app_files(id TEXT,message_id TEXT,stored_name TEXT,original_name TEXT,mime TEXT,size INTEGER);
 CREATE TABLE staff_app_chat_archive(id INTEGER PRIMARY KEY AUTOINCREMENT,snapshot_key TEXT UNIQUE,message_id TEXT,room_id TEXT,user_id TEXT,sender_name TEXT,room_title TEXT,body TEXT,reply_to TEXT,forward_from TEXT,message_created_at TEXT,edited_at TEXT,event TEXT);
 CREATE TABLE staff_app_archive_files(id TEXT PRIMARY KEY,message_id TEXT,stored_name TEXT,original_name TEXT,mime TEXT,size INTEGER);
 INSERT INTO users VALUES('author','Author');INSERT INTO staff_app_rooms VALUES('room','Private chat');INSERT INTO staff_app_messages VALUES('message','room','author','original',NULL,NULL,'2026-10-18 01:00:00',NULL);
 INSERT INTO staff_app_files VALUES('file','message','stored.jpg','photo.jpg','image/jpeg',123);`);
 const run=i=>db.prepare(q[i].sql.replace('INSERT IGNORE','INSERT OR IGNORE')).run(...q[i].params);let n=1;const check=(yes,msg)=>{if(!yes)throw Error(msg);n++};
 run(0);run(1);run(0);run(1);check(db.prepare('SELECT COUNT(*) n FROM staff_app_chat_archive').get().n===1,'Snapshot backfill idempotent');
 run(2);run(3);db.exec("UPDATE staff_app_messages SET body='edited',edited_at='2026-10-18 01:01:00'");run(4);run(5);run(6);run(7);run(8);run(9);
 db.exec('DELETE FROM staff_app_files;DELETE FROM staff_app_messages;DELETE FROM staff_app_rooms;DELETE FROM users');
 const snapshots=db.prepare('SELECT * FROM staff_app_chat_archive').all();check(snapshots.length===5,'All send/edit/delete/room-delete snapshots retained');check(snapshots.some(r=>r.event==='saved'&&r.body==='original'),'Original message survives');check(snapshots.some(r=>r.event==='edited'&&r.body==='edited'),'Edited version survives');check(snapshots.some(r=>r.event==='deleted'&&r.body==='edited'),'Deleted message survives');check(snapshots.every(r=>r.sender_name==='Author'&&r.room_title==='Private chat'),'Sender and room survive deletion');check(db.prepare(q[10].sql).get(...q[10].params)!==undefined,'Archived attachment prevents physical deletion');check(db.prepare('SELECT * FROM staff_app_archive_files').all().length===1,'Attachment metadata retained');
 const source=fs.readFileSync('cpanel/includes/staff_app.php','utf8');
 for(const [name,next] of [['send_message','output_file'],['forward_message','pinned_message'],['delete_message','edit_message'],['edit_message','pin_message'],['destroy_room','purge_expired_archive']]){const block=source.slice(source.indexOf('function staff_app_'+name+'('),source.indexOf('function staff_app_'+next+'('));check(block.indexOf('staff_app_archive_capture(')>=0&&block.indexOf('staff_app_archive_capture(')<block.indexOf('$pdo->commit()'),'Archive committed atomically: '+name);}
 console.log('PASS '+n+' archive persistence, attachment, transaction and owner-access checks');
})().catch(e=>{console.error(e);process.exitCode=1});
