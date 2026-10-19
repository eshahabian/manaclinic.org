$base=Get-Content output/access-update/publish-bahramizadeh-photo.ps1 -Raw
Invoke-Expression $base.Substring(0,$base.IndexOf('$photo ='))
$folder='photo-inspect-'+[Guid]::NewGuid().ToString('N')
$token=[Guid]::NewGuid().ToString('N')
$php=@'
<?php
if (!hash_equals('__SECRET__', $_SERVER['HTTP_X_PHOTO_TOKEN'] ?? '')) { http_response_code(404); exit; }
$c=require dirname(__DIR__).'/config.php';
$p=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$s=$p->query("SELECT dp.id,u.name,dp.is_active,dp.is_approved FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE u.name LIKE '%سارا%' OR u.name LIKE '%کولی%' OR u.name LIKE '%كولي%'");
header('Content-Type: application/json; charset=utf-8'); echo json_encode($s->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_UNICODE);
'@
Transfer-Ftp $folder 'MKD' $null
try {
 Transfer-Ftp ($folder+'/index.php') 'STOR' ([Text.Encoding]::UTF8.GetBytes($php.Replace('__SECRET__',$token)))
 (Invoke-WebRequest ('https://'+$config.host+'/'+$folder+'/index.php') -Headers @{'X-Photo-Token'=$token} -UseBasicParsing).Content
} finally { Transfer-Ftp ($folder+'/index.php') 'DELE' $null; Transfer-Ftp $folder 'RMD' $null }
