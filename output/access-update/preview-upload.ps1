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
Transfer-Ftp 'assets/css/mana-call-skype.css' 'STOR' ([IO.File]::ReadAllBytes((Join-Path (Get-Location) 'cpanel/assets/css/mana-call-skype.css')))
Transfer-Ftp 'mana-call-preview-8c4f7a.html' 'STOR' ([IO.File]::ReadAllBytes((Join-Path (Get-Location) 'output/access-update/skype-preview.html')))
Write-Output 'Synthetic preview uploaded for visual QA.'
Transfer-Ftp 'assets/js/mana-call-preview.js' 'STOR' ([IO.File]::ReadAllBytes((Join-Path (Get-Location) 'cpanel/assets/js/video-call-lobby.js')))