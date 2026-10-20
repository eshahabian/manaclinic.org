<?php
function normalize_input($v) {return strtr(trim($v),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));}
function normalize_phone($v) {return preg_replace('/[\s\-\.\(\)]+/','',normalize_input($v));}
function is_valid_phone($v) {return (bool) preg_match('/^(?:\+[1-9][0-9]{7,14}|00[1-9][0-9]{7,14}|[0-9]{8,15})$/',normalize_phone($v));}
eval(substr(file_get_contents(__DIR__.'/phone.txt'),5));
class PhoneDb extends PDO {
 public array $rows=[]; public bool $locked=false; public bool $allow=true;
 function __construct(){}
 public function prepare(string $q,array $options=[]):PDOStatement|false {return new PhoneStmt($this,$q);}
 public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false {return new PhoneStmt($this,$q);}
}
class PhoneStmt extends PDOStatement {
 function __construct(public PhoneDb $db,public string $q){}
 public function execute(?array $params=null):bool {if(str_contains($this->q,'RELEASE_LOCK'))$this->db->locked=false;return true;}
 public function fetchColumn(int $column=0):mixed {if(str_contains($this->q,'GET_LOCK')){$this->db->locked=$this->db->allow;return $this->db->allow?1:0;}return 1;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->db->rows;}
}
function phone_check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$equivalent=['09121234567','+989121234567','00989121234567','989121234567','9121234567','۰۹۱۲۱۲۳۴۵۶۷','٠٩١٢١٢٣٤٥٦٧','(+98) 912-123-4567'];
foreach($equivalent as $v)phone_check(user_phone_key($v)==='989121234567','canonical phone');
phone_check(user_phone_key('+1 (202) 555-0123')===user_phone_key('0012025550123'),'international canonical');
foreach(['','text','123'] as $v)phone_check(user_phone_key($v)===null,'invalid phone');
foreach($equivalent as $v){
 $p=new PhoneDb();$p->rows=[['id'=>'u','phone'=>$v]];$written=false;
 try{user_phone_write($p,'+989121234567',null,function($v)use(&$written){$written=true;});throw new Exception('expected duplicate');}catch(RuntimeException $e){}
 phone_check(!$written&&!$p->locked,'duplicate rejected and lock released');
}
$p=new PhoneDb();$p->rows=[['id'=>'u','phone'=>'+989121234567']];
$value=user_phone_write($p,'09121234567','u',fn($v)=>$v);
phone_check($value==='09121234567'&&!$p->locked,'same account may retain phone');
$p=new PhoneDb();try{user_phone_write($p,'09121234567',null,function(){throw new RuntimeException('write failed');});}catch(RuntimeException $e){}
phone_check(!$p->locked,'release lock on write failure');
$p=new PhoneDb();$p->allow=false;$written=false;try{user_phone_write($p,'09121234567',null,function()use(&$written){$written=true;});}catch(RuntimeException $e){}
phone_check(!$written,'concurrent write lock failure rejected');
echo 'PASS phone regression tests';
