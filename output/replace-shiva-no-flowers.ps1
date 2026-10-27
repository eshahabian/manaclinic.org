$ErrorActionPreference='Stop'
$profile=Invoke-WebRequest 'https://manaclinic.org/doctors/dprofile001mana' -UseBasicParsing
$asset='uploads/doctors/shiva-geranmayehpour-20261019.jpg'
if(!$profile.Content.Contains('/'+$asset)){throw 'Current photo path differs; stopped'}
$config=Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
function Transfer([string]$method,[byte[]]$bytes) {
 $r=[Net.FtpWebRequest]::Create('ftp://'+$config.host+'/public_html/'+$asset)
 $r.Credentials=[Net.NetworkCredential]::new($config.username,$config.password)
 $r.Method=$method; $r.UseBinary=$true; $r.Timeout=30000
 if($method -eq 'STOR') {$r.ContentLength=$bytes.Length; $s=$r.GetRequestStream(); $s.Write($bytes,0,$bytes.Length); $s.Close()}
 $res=$r.GetResponse()
 if($method -eq 'RETR') {$m=[IO.MemoryStream]::new(); $res.GetResponseStream().CopyTo($m); $res.Close(); return ,$m.ToArray()}
 $res.Close()
}
$before=Transfer 'RETR' $null
[IO.File]::WriteAllBytes((Join-Path $PWD 'output/shiva-before-no-flowers-20261027.jpg'),$before)
Add-Type -AssemblyName System.Drawing
$img=[Drawing.Image]::FromFile('C:\Users\Emad\.codex\generated_images\01a152ea-192d-7a41-9467-678bff7bea49\exec-058a576f-cefa-49f8-abd7-bcb37386a15c.png')
$local=Join-Path $PWD ('cpanel/'+$asset)
try {$img.Save($local,[Drawing.Imaging.ImageFormat]::Jpeg)} finally {$img.Dispose()}
$bytes=[IO.File]::ReadAllBytes($local)
Transfer 'STOR' $bytes
$check=Transfer 'RETR' $null
if([Convert]::ToBase64String($bytes) -ne [Convert]::ToBase64String($check)){Transfer 'STOR' $before; throw 'Upload mismatch; restored'}
foreach($path in @('/doctors','/doctors/dprofile001mana')) {
 $page=Invoke-WebRequest ('https://manaclinic.org'+$path) -UseBasicParsing
 if(!$page.Content.Contains('/'+$asset)){throw 'Image reference missing'}
 if(!$page.Content.Contains('object-position: center 20%')){throw 'Framing changed'}
 Write-Output ('Verified '+$path)
}
$public=Invoke-WebRequest ('https://manaclinic.org/'+$asset+'?verify=20261027') -UseBasicParsing
if($public.StatusCode -ne 200){throw 'Public image unavailable'}
Write-Output 'Approved image replaced; previous image backed up; framing preserved.'
