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
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$utf8 = [Text.UTF8Encoding]::new($false)
foreach ($file in @('includes/layout.php', 'pages/services.php')) {
    $original = Transfer-Ftp $file 'RETR' $null
    [IO.File]::WriteAllBytes((Join-Path (Get-Location) ('output/access-update/tests-before-' + $stamp + '-' + $file.Replace('/','_'))), $original)
    $content = $utf8.GetString($original)
    if ($file -eq 'includes/layout.php') {
        $content = [regex]::Replace($content, '(?m)^.*<a[^\r\n]*href="<\?= e\(url\(''/tests''\)\) \?>">آزمون‌ها</a>\r?\n', '')
    } else {
        $content = $content.Replace("'href' => '/services/assessments',", "'href' => '/tests',")
        $content = $content.Replace("'icon' => 'test',", "'icon' => 'test',`n        'cta' => 'مشاهده آزمون‌ها',")
        $content = $content.Replace('<span class="service-card-more">بیشتر بدانید ←</span>', '<span class="service-card-more"><?= e((string) ($item[''cta''] ?? ''بیشتر بدانید'')) ?> ←</span>')
    }
    if ($content -eq $utf8.GetString($original)) { throw ('No change made: ' + $file) }
    $bytes = $utf8.GetBytes($content)
    Transfer-Ftp $file 'STOR' $bytes
    $remote = Transfer-Ftp $file 'RETR' $null
    if ([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)) { throw ('Verification failed: ' + $file) }
    Write-Output ('Deployed and verified: ' + $file)
}