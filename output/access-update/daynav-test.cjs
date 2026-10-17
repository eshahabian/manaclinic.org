const fs=require('fs');const {PhpNode}=require('./tools/node_modules/php-wasm/PhpNode');
(async()=>{const php=new PhpNode({version:'8.3'});let out='';php.addEventListener('output',e=>out+=e.detail.join(''));await php.mkdir('/app');
for(const f of ['staff_app.php','staff_app_archive.php','helpers.php','web_push.php','video_call.php'])await php.writeFile('/app/'+f,fs.readFileSync('cpanel/includes/'+f));
const result=await php.run(`<?php require '/app/helpers.php';require '/app/staff_app.php';$n=0;
function check_day($yes){global $n;if(!$yes)throw new Exception('Date navigation failed');$n++;}
$d=staff_app_schedule_selected_day('2026-10-18');check_day($d->format('Y-m-d')==='2026-10-18');check_day($d->getTimezone()->getName()==='Asia/Tehran');check_day(to_jalali_label($d->format('Y-m-d'))==='۲۶ مهر ۱۴۰۵');
check_day($d->modify('-1 day')->format('Y-m-d')==='2026-10-17');check_day($d->modify('+1 day')->format('Y-m-d')==='2026-10-19');check_day(staff_app_schedule_selected_day('2027-01-01')->modify('-1 day')->format('Y-m-d')==='2026-12-31');check_day(staff_app_schedule_selected_day('2026-02-30')->format('Y-m-d')===(new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->format('Y-m-d'));echo 'PASS '.$n.' day-navigation checks';`);
console.log(out);if(result!==0||!out.startsWith('PASS'))throw Error('Day checks failed');})().catch(e=>{console.error(e);process.exitCode=1});
