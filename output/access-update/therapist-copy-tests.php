<?php
header('Content-Type: text/plain');
$s=file_get_contents(__DIR__.'/0.txt');$a=strpos($s,'function sms_queue_extended(');$z=strpos($s,'function sms_entity_previous',$a);eval(substr($s,$a,$z-$a));
function ensure_outreach_schema($p){}
function sms_iran_mobile($s){return $s;}
function cuid(){static $i=0;return 'test'.++$i;}
class CopyDb extends PDO{
 public array $jobs=[];public function __construct(){}
 public function prepare(string $s,array $o=[]):PDOStatement|false{return new CopyStmt($this,$s);}
 public function query(string $s,?int $m=null,mixed ...$a):PDOStatement|false{return new CopyStmt($this,$s);}
}
class CopyStmt extends PDOStatement{
 public function __construct(public CopyDb $p,public string $s){}
 public function execute(?array $a=null):bool{$this->p->jobs[]=$a;return true;}
 public function rowCount():int{return 1;}
 public function fetch(int $m=PDO::FETCH_DEFAULT,int $o=PDO::FETCH_ORI_NEXT,int $n=0):mixed{return ['id'=>'emad','phone'=>'09120000002'];}
}
$p=new CopyDb();sms_queue_extended($p,'09120000001','test','doctor_daily_digest','key',null,null,['entity'=>'doctor_digest']);
if(count($p->jobs)!==2||$p->jobs[0][2]!==$p->jobs[1][2]||$p->jobs[1][3]!=='therapist_copy')throw new Exception('Copy mismatch');
$p=new CopyDb();sms_queue_extended($p,'09120000002','test','doctor_daily_digest','key',null,null,['entity'=>'doctor_digest']);if(count($p->jobs)!==1)throw new Exception('Self duplicate');
$p=new CopyDb();sms_queue_extended($p,'09120000001','test','patient_notice','key');if(count($p->jobs)!==1)throw new Exception('Patient message copied');
echo 'PASS therapist copies match, no recursion or self duplicates, patient messages not copied';
