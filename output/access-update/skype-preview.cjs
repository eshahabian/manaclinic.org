const fs = require('fs');
const {PhpNode} = require('./tools/node_modules/php-wasm/PhpNode');
(async()=>{
 const php=new PhpNode({version:'8.3'});let html='';
 php.addEventListener('output',e=>html+=e.detail.join(''));
 await php.mkdir('/app');await php.mkdir('/app/includes');
 for(const file of ['helpers.php','staff_app.php','staff_app_archive.php','web_push.php','video_call.php','doctor_profile_fields.php','user_avatar.php','video_call_skype_lobby.php']) await php.writeFile('/app/includes/'+file,fs.readFileSync('cpanel/includes/'+file));
 let page=fs.readFileSync('cpanel/pages/video_call.php','utf8');
 let composer=page.slice(page.indexOf('  <div class="vc-lobby-main">'),page.indexOf('    <div class="vc-idle"'));
 await php.writeFile('/app/composer.php',composer+'</div>');
 const mocks=fs.readFileSync('output/access-update/theme-regression.php','utf8').split('class PhotoStatement')[1].split("check(staff_app_avatar_src")[0];
 const result=await php.run(`<?php
 function mb_substr($s,$start,$length=null){preg_match_all('/./us',$s,$chars);return implode('',array_slice($chars[0],$start,$length));} require '/app/includes/helpers.php';require '/app/includes/staff_app.php';
 class PhotoStatement${mocks}
 $base='';$user=['id'=>'doctor-new','role'=>'DOCTOR','name'=>'دکتر نازنین محمدی'];
 function current_user(){global $user;return $user;}
 $pdo=new PhotoDatabase('','');$clinician=true;$joinNow=false;$savedRooms=[];$workshops=[];$callActive=false;
 $patients=[];foreach(['سارا احمدی','علی رضایی','مریم کریمی','محمد مرادی','زهرا حسینی','آرمان نوری'] as $i=>$name){$patients[]=['id'=>'person-'.$i,'name'=>$name,'role'=>'PATIENT','online'=>$i%2===0];}
 ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="preview"><link rel="stylesheet" href="/assets/css/style.css"><link rel="stylesheet" href="/assets/css/mana-call-skype.css"><style>body{background:#f3f6f4;margin:0;padding:16px;font-family:Vazirmatn,Tahoma,sans-serif}main{max-width:1050px;margin:auto}.preview-bar{display:flex;justify-content:space-between;padding:12px 4px 20px;color:#24554d;font-weight:bold}</style></head><body><main><header class="preview-bar"><span>Mana Staff</span><a href="/app">بازگشت به برنامه</a></header><div class="vc-shell vc-skype" data-vc-app data-signal-url="/preview-signal"><div class="vc-lobby"><?php require '/app/includes/video_call_skype_lobby.php';require '/app/composer.php';?></div></div></main><script src="/assets/js/video-call-lobby.js"></script></body></html>`);
 if(result!==0 || html.includes('Fatal error')) throw Error(html);
 fs.writeFileSync('output/access-update/skype-preview.html',html);
 console.log('Rendered actual PHP lobby with six fictional contacts.');
})().catch(e=>{console.error(e);process.exitCode=1});
