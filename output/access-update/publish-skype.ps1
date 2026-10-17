$ErrorActionPreference = 'Stop'
$config = Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
$credential = [System.Net.NetworkCredential]::new($config.username, $config.password)
function Transfer-Ftp([string]$relative, [string]$method, [byte[]]$bytes) {
    $request = [System.Net.FtpWebRequest]::Create(('ftp://' + $config.host + '/public_html/' + $relative.TrimStart('/')))
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
        $memory = [System.IO.MemoryStream]::new()
        $response.GetResponseStream().CopyTo($memory)
        $response.Close()
        return ,$memory.ToArray()
    }
    $response.Close()
}
$files = @('includes/auth.php','includes/video_call.php','includes/video_call_skype_lobby.php','assets/js/video-call-lobby.js','pages/video_call.php')
foreach ($file in $files) {
    $original = Transfer-Ftp $file 'RETR' $null
    [IO.File]::WriteAllBytes((Join-Path (Get-Location) ('output/access-update/skype-before-' + $file.Replace('/','_'))), $original)
}
Write-Output 'Remote originals backed up.'

foreach ($file in $files) {
    $bytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location) ('cpanel/' + $file)))
    Transfer-Ftp $file 'STOR' $bytes
    $remote = Transfer-Ftp $file 'RETR' $null
    if ([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)) { throw ('Verification failed: ' + $file) }
    Write-Output ('Deployed and verified: ' + $file)
}
Transfer-Ftp 'mana-call-preview-8c4f7a.html' 'STOR' ([IO.File]::ReadAllBytes((Join-Path (Get-Location) 'output/access-update/skype-preview.html')))