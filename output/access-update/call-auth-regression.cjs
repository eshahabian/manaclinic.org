const fs=require('fs');const {PhpNode}=require('./tools/node_modules/php-wasm/PhpNode');
(async()=>{
 const php=new PhpNode({version:'8.3'});let out='';php.addEventListener('output',e=>out+=e.detail.join(''));
 const auth=fs.readFileSync('cpanel/includes/auth.php','utf8');
 const fn=auth.slice(auth.indexOf('function require_login('),auth.indexOf('function is_admin_user('));
 await php.writeFile('/gate.php','<?php '+fn);
 const code=`<?php
 class MockDatabase extends PDO{public function __construct(){}}
 function current_user(){return ['id'=>'new-doctor','role'=>'DOCTOR'];}
 function auth_grant_named_roles($db,$user){return $user;}
 function auth_guard_request(...$args){}
 function user_account_is_disabled(...$args){return false;}
 function doctor_must_complete_profile(...$args){return true;}
 function secretary_daily_tasks_can_review(...$args){return false;}
 function redirect($to){throw new RuntimeException($to);}
 require '/gate.php';$pdo=new MockDatabase();$checks=0;
 foreach(['/video-call','/video-signal','/video-call-live','/livekit-token','/app/profile'] as $path){if(require_login()['id']!=='new-doctor')throw new Exception('Call blocked');$checks++;}
 $path='/doctor/appointments';try{require_login();throw new Exception('Gate lost');}catch(RuntimeException $e){if($e->getMessage()!=='/doctor/profile')throw $e;$checks++;}
 echo 'PASS '.$checks.' incomplete-profile call-route checks';`;
 const result=await php.run(code);console.log(out);if(result!==0||!out.startsWith('PASS'))throw Error('Gate checks failed');
})().catch(e=>{console.error(e);process.exitCode=1});
