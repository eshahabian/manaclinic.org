$ErrorActionPreference='Stop'
$c=Get-Content .vscode/sftp.json -Raw | ConvertFrom-Json
$auth=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($c.username+':'+$c.password))
$api='https://'+$c.host+':2083/json-api/cpanel?cpanel_jsonapi_user='+[uri]::EscapeDataString($c.username)+'&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=listcron'
try {
 $r=Invoke-RestMethod $api -Headers @{Authorization='Basic '+$auth} -TimeoutSec 20
 if($r.cpanelresult.event.result -ne 1){Write-Output 'cPanel cron API authentication or access was not available.'; exit 2}
 $jobs=@($r.cpanelresult.data)
 $matching=@($jobs | Where-Object { $_.command -like '*sms-worker.php*' })
 Write-Output ('cPanel cron access OK; matching SMS jobs: '+$matching.Count)
 $r | ConvertTo-Json -Depth 12 | Set-Content output/access-update/cpanel-cron-state.private.json
} catch { Write-Output ('cPanel cron access unavailable: '+$_.Exception.GetType().Name); exit 2 }
