from pathlib import Path
import ftplib, io, json, secrets, time, urllib.request, sys
sys.stdout.reconfigure(encoding='utf-8')

root=Path('D:/Psychology')
cfg=json.loads((root/'.vscode/sftp.json').read_text('utf-8-sig'))
folder=root/'output/workshop-image-change'
folder.mkdir(exist_ok=True)
token=secrets.token_hex(32)
tag='workshop-image-'+secrets.token_hex(12)
name=tag+'/index.php'
php='''<?php
if(time()>EXPIRES || !hash_equals('SECRET_VALUE',$_SERVER['HTTP_X_VERIFY_TOKEN']??'')){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');
$c=require dirname(__DIR__).'/config.php';
$p=new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($_SERVER['REQUEST_METHOD']==='POST'){
$v=json_decode(file_get_contents('php://input'),true);
$q=$p->prepare("UPDATE workshops SET banner_url=? WHERE id=? AND COALESCE(banner_url,'')=?");
$q->execute([$v['new'],$v['id'],$v['old']]);echo json_encode(['updated'=>$q->rowCount()]);exit;
}
$q=$p->query("SELECT id,title,banner_url,created_at,starts_at,status FROM workshops WHERE title LIKE '%زوج%' ORDER BY created_at DESC,id DESC LIMIT 10");
echo json_encode($q->fetchAll(),JSON_UNESCAPED_UNICODE);
'''.replace('EXPIRES',str(int(time.time())+600)).replace('SECRET_VALUE',token)
with ftplib.FTP() as f:
    f.connect(cfg['host'],int(cfg.get('port',21)),timeout=45)
    f.login(cfg['username'],cfg['password']);f.cwd('/public_html')
    f.mkd(tag)
    f.storbinary('STOR '+name,io.BytesIO(php.encode()))
    def call(data=None):
        req=urllib.request.Request('https://manaclinic.org/'+tag+'/',data=json.dumps(data).encode() if data else None,headers={'X-Verify-Token':token,'Content-Type':'application/json'})
        return json.loads(urllib.request.urlopen(req,timeout=45).read())
    try:
        rows=call()
        if len(sys.argv)>1 and sys.argv[1]=='restore':
            saved=json.loads((folder/'rollback.json').read_text('utf-8'))
            original=saved['workshop']
            selected=next(r for r in rows if r['id']==original['id'])
            old_image=original['banner_url']
            assert f.size(old_image.lstrip('/'))>0, 'Original image missing'
            assert selected['banner_url'] in [saved['new'],old_image], 'Image changed since replacement'
            if selected['banner_url']!=old_image:
                result=call({'id':original['id'],'old':saved['new'],'new':old_image})
                assert result['updated']==1,result
            after=call()
            assert next(r for r in after if r['id']==original['id'])['banner_url']==old_image
            page=urllib.request.urlopen('https://manaclinic.org/workshops?verify='+secrets.token_hex(6),timeout=45).read().decode()
            assert old_image in page and saved['new'] not in page, 'Public image verification failed'
            print('PASS original image restored and verified on public workshops page')
        elif len(sys.argv)==1:
            print(json.dumps(rows,ensure_ascii=False))
        else:
            selected=rows[0]
            assert selected['id']==sys.argv[1], 'Latest workshop changed'
            old=selected['banner_url'] or ''
            new='/uploads/workshops/couples-20261020-'+secrets.token_hex(4)+'.jpg'
            source=Path('C:/Users/Emad/Downloads/Telegram Desktop/photo_2026-10-20_23-56-02.jpg').read_bytes()
            if old and old.startswith('/'):
                b=io.BytesIO();f.retrbinary('RETR '+old.lstrip('/'),b.write)
                (folder/'previous-image').write_bytes(b.getvalue())
            (folder/'rollback.json').write_text(json.dumps({'workshop':selected,'new':new},ensure_ascii=False,indent=2),encoding='utf-8')
            f.storbinary('STOR '+new.lstrip('/'),io.BytesIO(source))
            assert urllib.request.urlopen('https://manaclinic.org'+new,timeout=40).read()==source
            result=call({'id':selected['id'],'old':old,'new':new})
            assert result['updated']==1,result
            after=call();assert after[0]['banner_url']==new
            print(json.dumps({'updated':selected['title'],'image':new,'previous_preserved':True},ensure_ascii=False))
    finally:
        f.delete(name)
        f.rmd(tag)
        assert tag not in f.nlst()
