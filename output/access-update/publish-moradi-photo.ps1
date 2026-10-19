$ErrorActionPreference = 'Stop'
$config = Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
$credential = [System.Net.NetworkCredential]::new($config.username, $config.password)
function Transfer-Ftp([string]$relative, [string]$method, [byte[]]$bytes) {
    $request = [System.Net.FtpWebRequest]::Create(('ftp://' + $config.host + '/public_html/' + $relative))
    $request.Credentials = $credential
    $request.Method = $method
    $request.UseBinary = $true
    $request.UsePassive = $true
    $request.Timeout = 30000
    if ($method -eq 'STOR') {
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
    }
    $response = $request.GetResponse()
    if ($method -eq 'RETR') {
        $memory = [IO.MemoryStream]::new()
        $response.GetResponseStream().CopyTo($memory)
        $response.Close()
        return ,$memory.ToArray()
    }
    $response.Close()
}
$photo = 'uploads/doctors/mahboubeh-moradi-20261019.png'
$bytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location) ('cpanel/' + $photo)))
Transfer-Ftp $photo 'STOR' $bytes
$remote = Transfer-Ftp $photo 'RETR' $null
if ([Convert]::ToBase64String($bytes) -ne [Convert]::ToBase64String($remote)) { throw 'Photo verification failed' }
$token = [Guid]::NewGuid().ToString('N')
$folder = 'photo-update-' + [Guid]::NewGuid().ToString('N')
Transfer-Ftp $folder 'MKD' $null
$harness = $folder + '/index.php'
$php = @'
<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals('__SECRET__', $_SERVER['HTTP_X_PHOTO_TOKEN'] ?? '')) { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$id = 'e174114fa640d58a8eef7f24';
$pdo->beginTransaction();
$s = $pdo->prepare('SELECT dp.avatar_url, u.name FROM doctor_profiles dp JOIN users u ON u.id=dp.user_id WHERE dp.id=? FOR UPDATE');
$s->execute([$id]);
$old = $s->fetch(PDO::FETCH_ASSOC);
if (!$old || mb_strpos($old['name'], 'محبوبه مرادی') === false) { $pdo->rollBack(); http_response_code(409); exit; }
$pdo->prepare('UPDATE doctor_profiles SET avatar_url=? WHERE id=?')->execute(['/uploads/doctors/mahboubeh-moradi-20261019.png', $id]);
$pdo->commit();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true, 'previous'=>$old], JSON_UNESCAPED_UNICODE);
'@
$php = $php.Replace('__SECRET__', $token)
try {
    Transfer-Ftp $harness 'STOR' ([Text.Encoding]::UTF8.GetBytes($php))
    $result = Invoke-WebRequest ('https://' + $config.host + '/' + $harness) -Method POST -Headers @{'X-Photo-Token'=$token} -UseBasicParsing
    $result.Content | Set-Content output/access-update/moradi-photo-previous.json -Encoding UTF8
    if (!(($result.Content | ConvertFrom-Json).ok)) { throw 'Profile update failed' }
} finally {
    Transfer-Ftp $harness 'DELE' $null
    Transfer-Ftp $folder 'RMD' $null
}
$page = Invoke-WebRequest ('https://' + $config.host + '/doctors/e174114fa640d58a8eef7f24') -UseBasicParsing
if (!$page.Content.Contains('/' + $photo)) { throw 'Profile page does not show the new image' }
$image = Invoke-WebRequest ('https://' + $config.host + '/' + $photo) -Method Head -UseBasicParsing
Write-Output ('PASS: doctor profile shows approved photo; image HTTP ' + $image.StatusCode)

