$ErrorActionPreference='Stop'
$config=Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
function Transfer([string]$method,[byte[]]$bytes) {
 $r=[Net.FtpWebRequest]::Create('ftp://'+$config.host+'/public_html/includes/doctor_profile_fields.php')
 $r.Credentials=[Net.NetworkCredential]::new($config.username,$config.password)
 $r.Method=$method; $r.UseBinary=$true; $r.Timeout=30000
 if($method -eq 'STOR') {$r.ContentLength=$bytes.Length; $s=$r.GetRequestStream(); $s.Write($bytes,0,$bytes.Length); $s.Close()}
 $res=$r.GetResponse()
 if($method -eq 'RETR') {$m=[IO.MemoryStream]::new(); $res.GetResponseStream().CopyTo($m); $res.Close(); return ,$m.ToArray()}
 $res.Close()
}
$before=Transfer 'RETR' $null
[IO.File]::WriteAllBytes((Join-Path $PWD 'output/shiva-framing-before.php'),$before)
$source=[Text.Encoding]::UTF8.GetString($before)
$local=[IO.File]::ReadAllText((Join-Path $PWD 'cpanel/includes/doctor_profile_fields.php'))
$old=@'
        return '<img class="' . e($class) . '" src="' . e($src) . '" alt="' . e($name) . '">';
'@
$new=@'
        $position = (string) ($doc['id'] ?? '') === 'dprofile001mana' ? ' style="object-position: center 20%"' : '';
        return '<img class="' . e($class) . '" src="' . e($src) . '" alt="' . e($name) . '"' . $position . '>';
'@
if(!$source.Contains($old.TrimEnd())){throw 'Remote source differs; stopped'}
$updated=$source.Replace($old.TrimEnd(),$new.TrimEnd())
$bytes=[Text.UTF8Encoding]::new($false).GetBytes($updated)
Transfer 'STOR' $bytes
$check=Transfer 'RETR' $null
if([Convert]::ToBase64String($bytes) -ne [Convert]::ToBase64String($check)){throw 'Upload mismatch'}
foreach($path in @('/doctors','/doctors/dprofile001mana')) {
 $res=Invoke-WebRequest ('https://manaclinic.org'+$path) -UseBasicParsing
 if(!$res.Content.Contains('object-position: center 20%')){throw ('Framing missing on '+$path)}
 Write-Output ('Verified '+$path)
}
