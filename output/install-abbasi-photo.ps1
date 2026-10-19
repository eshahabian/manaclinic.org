$ErrorActionPreference = 'Stop'
$config = Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
$credential = [Net.NetworkCredential]::new($config.username, $config.password)
function Transfer([string]$path,[string]$method,[byte[]]$bytes) {
  $r=[Net.FtpWebRequest]::Create('ftp://'+$config.host+'/public_html/'+$path)
  $r.Credentials=$credential; $r.Method=$method; $r.UseBinary=$true; $r.Timeout=30000
  if($method -eq 'STOR') {$r.ContentLength=$bytes.Length; $s=$r.GetRequestStream(); $s.Write($bytes,0,$bytes.Length); $s.Close()}
  try {$response=$r.GetResponse()} catch {throw ("FTP " + $method + " " + $path + ": " + $_.Exception.Message)}
  if($method -eq 'RETR') {$m=[IO.MemoryStream]::new(); $response.GetResponseStream().CopyTo($m); $response.Close(); return ,$m.ToArray()}
  $response.Close()
}
$asset='uploads/doctors/nahal-abbasi-20261019.jpg'
$bytes=[IO.File]::ReadAllBytes((Join-Path $PWD ('cpanel/'+$asset)))
Transfer $asset 'STOR' $bytes
$remote=Transfer $asset 'RETR' $null
if([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)){throw 'Image verification failed'}
Transfer 'uploads/doctors/shiva-geranmayehpour-20261019.jpg' 'STOR' ([IO.File]::ReadAllBytes((Join-Path $PWD 'cpanel/uploads/doctors/shiva-geranmayehpour-20261019.jpg')))
$tag=[Guid]::NewGuid().ToString('N')
$token=[Guid]::NewGuid().ToString('N')
$dir='photo_'+$tag
Transfer $dir 'MKD' $null
$script=$dir+'/index.php'
$php=@'
<?php
header('Content-Type: application/json');
if (!hash_equals('TOKEN', $_GET['token'] ?? '')) {http_response_code(403);exit;}
$c=require dirname(__DIR__).'/config.php';
$pdo=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$q=$pdo->prepare('SELECT dp.id,u.name,dp.avatar_url FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id=? FOR UPDATE');
$q->execute(['e1fbc5c5a6befff2ee5fe918']);$d=$q->fetch(PDO::FETCH_ASSOC);
if (!$d || !str_contains($d['name'],'عباسی')) {throw new RuntimeException('Profile mismatch');}
$u=$pdo->prepare('UPDATE doctor_profiles SET avatar_url=? WHERE id=?');
$u->execute(['/uploads/doctors/nahal-abbasi-20261019.jpg',$d['id']]);
$q->execute(['dprofile001mana']);$s=$q->fetch(PDO::FETCH_ASSOC);
if (!$s || !str_contains($s['name'],'شیوا')) {throw new RuntimeException('Profile mismatch');}
$u->execute(['/uploads/doctors/shiva-geranmayehpour-20261019.jpg',$s['id']]);
$pdo->commit();echo json_encode(['ok'=>true,'before'=>[$d,$s]],JSON_UNESCAPED_UNICODE);
'@
$php=$php.Replace('TOKEN',$token)
try {
 Transfer $script 'STOR' ([Text.Encoding]::UTF8.GetBytes($php))
 $res=Invoke-WebRequest ('https://manaclinic.org/'+$script+'?token='+$token) -UseBasicParsing
 $res.Content | Set-Content output/abbasi-photo-before.json -Encoding utf8
 if(!($res.Content | ConvertFrom-Json).ok){throw 'Update failed'}
 Write-Output 'Profile image updated; previous value backed up.'
} catch {Write-Output ($_.Exception.Message); throw} finally {try {Transfer $script 'DELE' $null} catch {}; try {Transfer ($dir+'/') 'RMD' $null} catch {Write-Output 'Empty temporary folder cleanup pending'}}
$page=Invoke-WebRequest 'https://manaclinic.org/doctors/e1fbc5c5a6befff2ee5fe918' -UseBasicParsing
if(!$page.Content.Contains('/uploads/doctors/nahal-abbasi-20261019.jpg')){throw 'Public profile verification failed'}
Write-Output 'Public profile verified.'





