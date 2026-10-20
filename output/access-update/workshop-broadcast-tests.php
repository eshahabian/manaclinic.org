<?php
header('Content-Type: text/plain; charset=utf-8');
eval(substr(file_get_contents(__DIR__.'/broadcast.txt'),5));
function check($value,$name){if(!$value)throw new RuntimeException($name);echo "PASS $name\n";}
function sms_iran_mobile($v){$v=str_replace('+98','0',$v);return preg_match('/^09[0-9]{9}$/',$v)?$v:null;}
function sms_job_still_valid($p,$j){return $p->valid;}
function sms_connection_config($p){return [];}
function sms_provider_send($config,$phone,$body){global $testDb;$testDb->sends++;if($testDb->fail)throw new RuntimeException('timeout');return ['status'=>'SENT','id'=>'test'];}
class BroadcastDb extends PDO {
 public array $rows=[];public array $queries=[];public int $sends=0;public bool $valid=true;public bool $fail=false;public string $status='PENDING';
 public function __construct(){}
 public function prepare(string $q,array $opts=[]):PDOStatement|false{$this->queries[]=$q;return new BroadcastStmt($this,$q);}
 public function query(string $q,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new BroadcastStmt($this,$q);}
}
class BroadcastStmt extends PDOStatement {
 public function __construct(public BroadcastDb $db,public string $q){}
 public function execute(?array $args=null):bool {if(str_contains($this->q,"SET status='PROCESSING'"))$this->db->status='PROCESSING';elseif(str_contains($this->q,"SET status='SKIPPED'"))$this->db->status='SKIPPED';elseif(str_contains($this->q,'SET status=?'))$this->db->status=$args[0];return true;}
 public function fetchColumn(int $column=0):mixed{return 1;}
 public function rowCount():int{return 1;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->db->rows;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->db->status==='PENDING'?['id'=>'test','phone'=>'09121234567','body'=>'test']:false;}
}
$p=new BroadcastDb();$p->rows=[['patient_id'=>'a','phone'=>'09121234567'],['patient_id'=>'b','phone'=>'+989121234567'],['patient_id'=>'c','phone'=>'invalid']];
$result=workshop_broadcast_members($p,'w');check(count($result['phones'])===1&&$result['invalid']===1,'unique normalized recipients and invalid phone exclusion');check(count(array_values($result['phones'])[0])===2,'shared phone retains membership identity checks');check(str_contains($p->queries[0],"'CONFIRMED','COMPLETED'")&&str_contains($p->queries[0],'is_disabled'),'recipient SQL excludes cancelled and disabled members');
check(workshop_broadcast_members($p,'w',['a'])['phones']===['09121234567'=>['a']],'selection excludes unchecked identities even on shared phone');check(workshop_broadcast_members($p,'w',[])['phones']===[],'empty selection has no recipients');
$p=new BroadcastDb();$p->rows=[];check(!workshop_broadcast_job_valid($p,['phone'=>'09121234567'],['members'=>['a'],'workshop_id'=>'w']),'removed membership skipped before send');$p->rows=[['phone'=>'09121234567']];check(workshop_broadcast_job_valid($p,['phone'=>'09121234567'],['members'=>['a'],'workshop_id'=>'w']),'current member phone remains valid');check(!workshop_broadcast_job_valid($p,['phone'=>'09120000000'],['members'=>['a'],'workshop_id'=>'w']),'changed phone is not sent stale message');
$testDb=new BroadcastDb();workshop_broadcast_process($testDb,'batch');workshop_broadcast_process($testDb,'batch');check($testDb->sends===1&&$testDb->status==='SENT','completed job is not sent twice');
$testDb=new BroadcastDb();$testDb->valid=false;workshop_broadcast_process($testDb,'batch');check($testDb->sends===0&&$testDb->status==='SKIPPED','cancelled membership never reaches provider');
$testDb=new BroadcastDb();$testDb->fail=true;workshop_broadcast_process($testDb,'batch');workshop_broadcast_process($testDb,'batch');check($testDb->sends===1&&$testDb->status==='UNKNOWN','uncertain send is not automatically retried');
echo 'PASS broadcast regression tests';
