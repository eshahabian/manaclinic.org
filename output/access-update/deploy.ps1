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
$files = @('includes/staff_app.php','pages/staff_app.php','actions/login.php')
foreach ($file in $files) {
    $original = Transfer-Ftp $file 'RETR' $null
    [IO.File]::WriteAllBytes((Join-Path (Get-Location) ('output/access-update/' + $file.Replace('/','_'))), $original)
}
Write-Output 'Remote originals backed up.'

$tag = [Guid]::NewGuid().ToString('N')
$staged = @()
try {
    foreach ($file in $files) {
        $target = 'includes/check_' + $tag + '_' + $file.Replace('/','_')
        Transfer-Ftp $target 'STOR' ([IO.File]::ReadAllBytes((Join-Path (Get-Location) ('cpanel/' + $file))))
        $staged += $target
    }
    $test = (Get-Content output/access-update/verify-template.php -Raw).Replace('TAG',$tag)
    $harness = 'verify_' + $tag + '.php'
    Transfer-Ftp $harness 'STOR' ([Text.Encoding]::UTF8.GetBytes($test))
    $staged += $harness
    $result = Invoke-WebRequest ('https://' + $config.host + '/' + $harness) -UseBasicParsing
    if (!$result.Content.StartsWith('PASS ')) { throw 'Remote permission validation failed' }
    Write-Output $result.Content
    foreach ($file in $files) {
        $bytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location) ('cpanel/' + $file)))
        Transfer-Ftp $file 'STOR' $bytes
        $remote = Transfer-Ftp $file 'RETR' $null
        if ([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)) { throw ('Verification failed: ' + $file) }
        Write-Output ('Deployed and verified: ' + $file)
    }
} finally {
    foreach ($target in $staged) { Transfer-Ftp $target 'DELE' $null }
}
