from pathlib import Path
import json,urllib.request,urllib.parse,http.cookiejar,sys
root=Path.cwd();d=root/'output/access-update/sms-nightly';c=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'));v=json.loads((d/'verification.json').read_text(encoding='utf-8'))
php='/opt/cpanel/ea-php83/root/usr/bin/php';assert php in v['cli']
jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
req=urllib.request.Request('https://'+c['host']+':2083/login/?login_only=1',data=urllib.parse.urlencode({'user':c['username'],'pass':c['password']}).encode())
with opener.open(req,timeout=20) as r:login=json.load(r)
assert login.get('status')==1 and login.get('security_token'),'Host login failed'
base='https://'+c['host']+':2083'+login['security_token']+'/json-api/cpanel'
def api(fn,params=None):
 args={'cpanel_jsonapi_user':c['username'],'cpanel_jsonapi_apiversion':'2','cpanel_jsonapi_module':'Cron','cpanel_jsonapi_func':fn};args.update(params or {})
 req=urllib.request.Request(base,data=urllib.parse.urlencode(args).encode())
 with opener.open(req,timeout=25) as r:res=json.load(r)['cpanelresult']
 assert res['event']['result']==1,'Cron API failed'
 return res['data']
rows=api('listcron');matches=[r for r in rows if '/cron/sms' in r.get('command','') or 'sms-worker.php' in r.get('command','')]
assert len(matches)==1,'Expected exactly one SMS cron job'
old=matches[0];quiet='--quiet' in sys.argv
command=php+' -q '+v['root']+'/sms-worker.php'+(' > /dev/null 2>&1' if quiet else ' > /home3/slhgjbht/.mana-sms-worker.log 2>&1')
params={k:'*' for k in ['minute','hour','day','month','weekday']};params.update({'linekey':old['linekey'],'command':command})
result=api('edit_line',params);assert result[0]['status']==1,'Cron installation failed'
verified=api('listcron');matches=[r for r in verified if 'sms-worker.php' in r.get('command','')]
assert len(matches)==1 and matches[0]['command']==command and all(matches[0][k]=='*' for k in ['minute','hour','day','month','weekday']),'Cron verification failed'
proof={'scheduler':'cPanel cron','interval':'every minute','mode':'PHP CLI 8.3','job_count':len(matches),'nightly_send_time':'21:00 Asia/Tehran','log_path':None if quiet else '/home3/slhgjbht/.mana-sms-worker.log'}
(d/'cron-verification.json').write_text(json.dumps(proof,indent=2),encoding='utf-8');print(json.dumps(proof),flush=True)
