$config=require dirname(__DIR__).'/config.php';
$pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require dirname(__DIR__).'/includes/helpers.php';require dirname(__DIR__).'/includes/user_phone.php';
$target=user_phone_key('09354162590');$lock='mana-phone:'.substr(hash('sha256',$target),0,48);
$q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new Exception('Lock unavailable');
$expected=['36dfdb042124cb0a89814c30'=>['عطیه گارسچی','atieh'],'5ab59a6159031fe471789a1b'=>['عطی','atig'],'9a7bd82618bd8fe9f168d1aa'=>['باران شاکری','bshakeri']];
try{
 $pdo->beginTransaction();$rows=$pdo->query('SELECT id,name,username,phone,is_disabled FROM users FOR UPDATE')->fetchAll();$found=[];
 foreach($rows as $u)if(user_phone_key((string)($u['phone']??''))===$target){
  $id=(string)$u['id'];if(!isset($expected[$id])||[$u['name'],$u['username']]!==$expected[$id])throw new Exception('Account changed');$found[]=$id;
 }
 if(count($found)!==3)throw new Exception('Unexpected matching accounts');
 $col=$pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();$empty=$col['Null']==='YES'?null:'';
 $update=$pdo->prepare('UPDATE users SET phone=? WHERE id=? AND phone=?');$removed=0;
 foreach($rows as $u)if(in_array($u['id'],['5ab59a6159031fe471789a1b','9a7bd82618bd8fe9f168d1aa'],true)){
  $update->execute([$empty,$u['id'],$u['phone']]);if($update->rowCount()!==1)throw new Exception('Concurrent change');$removed++;
 }
 $remaining=[];foreach($pdo->query("SELECT id,phone FROM users WHERE phone IS NOT NULL AND phone<>''")->fetchAll() as $u)if(user_phone_key($u['phone'])===$target)$remaining[]=$u['id'];
 if($remaining!==['36dfdb042124cb0a89814c30']||$removed!==2)throw new Exception('Verification failed');
 $pdo->commit();echo json_encode(['status'=>'verified','removed_from_accounts'=>$removed,'sole_owner'=>'عطیه گارسچی','username'=>'atieh'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
