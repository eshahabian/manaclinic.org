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
$file = 'includes/layout.php'
$original = Transfer-Ftp $file 'RETR' $null
[IO.File]::WriteAllBytes((Join-Path (Get-Location) ('output/access-update/about-order-before-' + $stamp + '.php')), $original)
$utf8 = [Text.UTF8Encoding]::new($false)
$content = $utf8.GetString($original)
$pattern = '(?m)^(?<about>[^\r\n]*<a[^\r\n]*href="<\?= e\(url\(''/about''\)\) \?>">درباره ما</a>)\r?\n(?<faq>[^\r\n]*<a[^\r\n]*href="<\?= e\(url\(''/faq''\)\) \?>">سوالات متداول</a>)'
$content = [regex]::Replace($content, $pattern, '${faq}' + "`n" + '${about}')
if ($content -eq $utf8.GetString($original)) { throw 'Expected menu order not found' }
$bytes = $utf8.GetBytes($content)
Transfer-Ftp $file 'STOR' $bytes
$remote = Transfer-Ftp $file 'RETR' $null
if ([Convert]::ToBase64String($remote) -ne [Convert]::ToBase64String($bytes)) { throw 'Verification failed' }
Write-Output 'Deployed and verified: about menu order'
foreach ($path in @('/robots.txt','/sitemap.xml','/','/about','/services/couples','/tests')) {
    $response = Invoke-WebRequest ('https://manaclinic.org' + $path) -UseBasicParsing
    $name = if ($path -eq '/') { 'home' } else { $path.TrimStart('/').Replace('/','_') }
    [IO.File]::WriteAllText((Join-Path (Get-Location) ('output/access-update/seo-live-' + $name + '.txt')), $response.Content, $utf8)
    Write-Output ($path + ' status=' + $response.StatusCode)
}