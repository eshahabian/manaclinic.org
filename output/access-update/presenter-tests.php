<?php
header('Content-Type: text/plain');
eval(substr(file_get_contents(__DIR__.'/0.txt'),5));
function to_fa_digits($s){return $s;}
function sms_iran_mobile($s){return preg_match('/^09[0-9]{9}$/',$s)?$s:null;}
function ensure_outreach_schema($p){}
function cuid(){static $i=0;return 'test'.++$i;}
function sms_queue_extended($p,$phone,$body,$kind,$key,$due,$expiry,$ctx){$p->jobs[]=[$phone,$body,$kind,$ctx];return true;}
class TestDb extends PDO {
 public array $old=[],$jobs=[];public bool $tx=false;
 public function __construct(){}
 public function exec(string $s):int|false{return 0;}
 public function inTransaction():bool{return $this->tx;}
 public function beginTransaction():bool{$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{$this->tx=false;return true;}
 public function prepare(string $s,array $o=[]):PDOStatement|false{return new TestStmt($this,$s);}
 public function query(string $s,?int $m=null,mixed ...$a):PDOStatement|false{return new TestStmt($this,$s);}
}
class TestStmt extends PDOStatement {
 public array $args=[];
 public function __construct(public TestDb $p,public string $s){}
 public function execute(?array $a=null):bool{$this->args=$a??[];if(str_starts_with($this->s,'INSERT INTO workshop_presenters'))$this->p->old[]=$a[1];return true;}
 public function fetchAll(int $m=PDO::FETCH_DEFAULT,mixed ...$a):array{if(str_contains($this->s,'SELECT doctor_id'))return $this->p->old;return array_map(fn($id)=>['id'=>$id,'name'=>'Therapist '.$id,'phone'=>'09120000001'],$this->args);}
 public function fetch(int $m=PDO::FETCH_DEFAULT,int $o=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{if(str_contains($this->s,'SELECT * FROM workshops'))return ['title'=>'Test workshop','starts_at'=>'2026-10-21 18:00:00','ends_at'=>'2026-10-21 20:30:00','session_interval'=>'WEEKLY','location'=>'Registered location','type'=>'IN_PERSON'];return ['id'=>'emad','phone'=>'09120000002'];}
}
$p=new TestDb();workshop_presenters_save($p,'w',['d1','d2']);if(count($p->jobs)!==4)throw new Exception('Expected originals and copies');
if($p->jobs[0][1]!==$p->jobs[1][1]||$p->jobs[1][3]['copy_for']!=='emad')throw new Exception('Copy mismatch');
workshop_presenters_save($p,'w',['d1','d2']);if(count($p->jobs)!==4)throw new Exception('Resave duplicated messages');
if(!str_contains($p->jobs[0][1],'20:30')||!str_contains($p->jobs[0][1],'Registered location'))throw new Exception('Schedule/place not from data');
echo 'PASS multiple presenters, identical Emad copies, unchanged resave sends nothing, schedule/place from data';
