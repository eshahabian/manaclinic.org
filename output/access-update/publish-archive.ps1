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
$archiveBytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location) 'cpanel/includes/staff_app_archive.php'))
Transfer-Ftp 'includes/staff_app_archive.php' 'STOR' $archiveBytes
$archiveRemote = Transfer-Ftp 'includes/staff_app_archive.php' 'RETR' $null
if ([Convert]::ToBase64String($archiveRemote) -ne [Convert]::ToBase64String($archiveBytes)) { throw 'Archive helper verification failed' }
Write-Output 'Deployed and verified: includes/staff_app_archive.php'
$files = @('includes/staff_app.php','pages/staff_app.php')
foreach ($file in $files) {
    $original = Transfer-Ftp $file 'RETR' $null
    [IO.File]::WriteAllBytes((Join-Path (Get-Location) ('output/access-update/archive-before-' + $file.Replace('/','_'))), $original)
}
Write-Output 'Remote originals backed up.'

foreach ($file in $files) {
    $bytes = [IO.File]::ReadAllBytes((Join-Path (Get-Location) ('cpanel/' + $file)))
    Transfer-Ftp $file 'STOR' $bytes
    $remote = Transfer-Ftp $file 'RETR' $null
    if ([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)) { throw ('Verification failed: ' + $file) }
    Write-Output ('Deployed and verified: ' + $file)
}