const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const os=require('node:os');
const path=require('node:path');
const {spawn,execFileSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const repo=path.resolve(__dirname,'../..');
const php=process.env.PHP_BINARY||'php';
const port=Number(process.env.CMS_TEST_PORT||8792),prefix=process.env.CMS_TEST_PREFIX||'';
const origin=`http://127.0.0.1:${port}`,url=origin+prefix;
let root,site,data,server,checks=0;
function ok(condition,message){assert.ok(condition,message);checks++;}
function equal(a,b,message){assert.equal(a,b,message);checks++;}
class Client {
 constructor(){this.cookies=new Map();this.csrf='';}
 async request(route,body,options={}) {
  const response=await fetch(url+route,{method:body===undefined?'GET':'POST',headers:{Cookie:[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; '),...(body===undefined?{}:{'Content-Type':'application/json','X-CMS-CSRF':this.csrf}),...options.headers},body:body===undefined?undefined:JSON.stringify(body),redirect:'manual'});
  for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0],at=pair.indexOf('=');this.cookies.set(pair.slice(0,at),pair.slice(at+1));}
  const text=await response.text();let json;try{json=JSON.parse(text);}catch{}
  return {status:response.status,json,text,headers:response.headers};
 }
 async api(action,body,expected=200,options){const r=await this.request('/psy-admin/?api='+action,body,options);equal(r.status,expected,action+': '+(r.json?.error||r.text.slice(0,150)));return r.json;}
 async session(){const s=await this.api('session');this.csrf=s.csrf;return s;}
 async login(name,password,activation){await this.session();const r=await this.api('login',{username:name,password,activation});await this.session();return r;}
 async preview(page='/about'){const r=await this.request(page+'?mana_preview='+this.csrf);equal(r.status,200,'preview '+r.text.slice(0,150));ok(r.text.includes('data-mana-field='),'Authenticated edit markers');return r;}
}
async function copy(relative){const src=path.join(repo,'cpanel',relative),dest=path.join(site,relative);await fs.mkdir(path.dirname(dest),{recursive:true});await fs.cp(src,dest,{recursive:true});}
test('Mana CMS isolated integration: real PHP templates and server security',{timeout:180000},async()=>{
 root=await fs.mkdtemp(path.join(os.tmpdir(),'mana-cms-test-'));site=path.join(root,'public');data=path.join(root,'private');await fs.mkdir(site);
 try {
  for(const f of ['psy-admin','includes','pages/about.php','pages/faq.php','assets/css','assets/js','assets/fonts','assets/img/logo.png','assets/img/hero.webp','index.php']) await copy(f);
  // Never reuse production hashes, credentials, database, activation or users.
  execFileSync(php,['-r',`file_put_contents($argv[1],"<?php return ".var_export(['installation'=>'fixtureonly','activationHash'=>hash('sha256','fixture-activation'),'bootstrapHash'=>password_hash('Temporary-Fixture-2026',PASSWORD_DEFAULT)],true).";");`,path.join(site,'psy-admin/installation.php')]);
  const args=['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=gd','-S',`127.0.0.1:${port}`,'-t',site,path.join(__dirname,'test-router.php')];
  const runId=randomBytes(12).toString('hex');
  let logs='';server=spawn(php,args,{env:{...process.env,MANA_CMS_DATA_DIR:data,CMS_TEST_PREFIX:prefix,CMS_TEST_RUN:runId},stdio:['ignore','pipe','pipe'],windowsHide:true});server.stdout.on('data',x=>logs+=x);server.stderr.on('data',x=>logs+=x);
  let ready=false;
  for(let i=0;i<80;i++){try{const response=await fetch(url+'/psy-admin/');if(response.headers.get('x-cms-fixture')!==runId)throw new Error('Port already serves a different process');ready=true;break;}catch(e){if(e.message.includes('different process'))throw e;await new Promise(r=>setTimeout(r,50));}}
  if(!ready)throw new Error('Isolated PHP server could not start');
  const owner=new Client(),stranger=new Client();
  let s=await owner.session();ok(s.needsActivation,'activation required');
  const cookieResponse=await new Client().request('/psy-admin/?api=session');
  ok(cookieResponse.headers.getSetCookie().some(c=>/HttpOnly/i.test(c)&&/SameSite=Strict/i.test(c)),'HttpOnly and SameSite');
  const secureResponse=await new Client().request('/psy-admin/?api=session',undefined,{headers:{'X-Fixture-Https':'1'}});ok(secureResponse.headers.getSetCookie().some(c=>/; secure/i.test(c)),'Secure cookie on HTTPS');
  await stranger.api('pages',undefined,401);
  await owner.api('login',{username:'eshahabian',password:'wrong',activation:'fixture-activation'},401);
  await owner.api('login',{username:'eshahabian',password:'Temporary-Fixture-2026',activation:'wrong'},401);
  equal((await owner.session()).needsActivation,true,'bad activation never creates users');
  const oldSid=owner.cookies.get('MANA_CMS_fixtureonly');
  await owner.login('eshahabian','Temporary-Fixture-2026','fixture-activation');
  ok(owner.cookies.get('MANA_CMS_fixtureonly')!==oldSid,'session rotated on login');
  for(const route of ['pages','menu','images','users']) await owner.api(route,undefined,403);
  await owner.api('save',{page:'/about',version:0,changes:[]},403);
  equal((await owner.request('/about?mana_preview='+owner.csrf)).status,403,'preview blocked before password change');
  await owner.api('password',{current:'Temporary-Fixture-2026',password:'Permanent-Fixture-2026',confirm:'Permanent-Fixture-2026'});
  await owner.session();
  await owner.api('menu',{version:0,items:[{label:'خانه',href:'/'}]},403,{headers:{'X-CMS-CSRF':'bad'}});
  await owner.api('menu',{version:0,items:[{label:'خانه',href:'/'}]},403,{headers:{Origin:'https://evil.invalid'}});
  await owner.preview();
  let page=await owner.api('page&id=%2Fabout');ok(page.fields.some(f=>f.scope==='header'),'header fields');ok(page.fields.some(f=>f.scope==='footer'),'footer fields');
  let field=page.fields.find(f=>f.value==='درباره مانا کلینیک');ok(!!field,'real about heading found');
  await owner.api('save',{page:'/about',version:page.version,changes:[{id:field.id,value:'<img src=x onerror=alert(1)> & متن'}]});
  let html=await stranger.request('/about');ok(html.text.includes('&lt;img src=x onerror=alert(1)&gt; &amp; متن'),'text escaped');ok(!html.text.includes('data-mana-field'),'no public edit markers');
  await owner.api('save',{page:'/about',version:page.version,changes:[{id:field.id,value:'stale'}]},409);
  await owner.preview();page=await owner.api('page&id=%2Fabout');
  await owner.api('save',{page:'/about',version:page.version,changes:[{id:field.id,value:''}]});
  await owner.preview();page=await owner.api('page&id=%2Fabout');equal(page.fields.find(f=>f.id===field.id).value,'','empty remains editable');
  await owner.api('save',{page:'/about',version:page.version,changes:[{id:field.id,value:'درباره مانا کلینیک'}]});
  html=await stranger.request('/about');ok(html.text.includes('<h1>درباره مانا کلینیک</h1>'),'same original text restored');
  await owner.preview();page=await owner.api('page&id=%2Fabout');field=page.fields.find(f=>f.scope==='footer'&&f.value.includes('فضای امن'));
  await owner.api('save',{page:'/about',version:page.version,changes:[{id:field.id,value:'پابرگ مشترک آزمایشی'}]});
  html=await stranger.request('/faq');ok(html.text.includes('پابرگ مشترک آزمایشی'),'shared footer on different real template');
  await owner.preview('/');page=await owner.api('page&id=%2F');field=page.fields.find(f=>f.value==='عنوان آزمایشی');
  await owner.api('save',{page:'/',version:page.version,changes:[{id:field.id,value:'عنوان تازه'}]});
  html=await stranger.request('/');ok(html.text.includes('<h2 id="section-one">عنوان تازه</h2>'),'heading updated');ok(html.text.includes('href="#section-one">عنوان تازه</a>'),'TOC updated with stable anchor');ok(html.text.includes('<strong>درون تگ</strong>'),'nested HTML retained');
  let m=await owner.api('menu');
  for(const href of ['javascript:alert(1)','//evil.invalid','/missing','/about#missing','/../about','/about%00',origin+'/missing']) await owner.api('menu',{version:m.version,items:[{label:'بد',href}]},400);
  const items=[{label:'درباره ما',href:'/about'},{label:'بخش نخست',href:'/#section-one'},{label:'تماس',href:'tel:+982122065774'},{label:'ایمیل',href:'mailto:example@example.invalid'},{label:'خارجی',href:'https://example.invalid/'}];
  await owner.api('menu',{version:m.version,items});html=await stranger.request('/faq');equal((html.text.match(/>بخش نخست<\/a>/g)||[]).length,2,'both desktop and mobile menu replaced');ok(html.text.includes(`href="${prefix}/#section-one"`),'subdirectory-safe links: '+html.text.match(/href="[^"]*">بخش نخست/g));
  let im=await owner.api('images');ok(im.images.some(i=>i.id==='/assets/img/logo.png'),'logo catalog');ok(im.images.some(i=>i.id==='/assets/img/hero.webp'),'content image catalog');
  await owner.api('image',{id:'/assets/img/logo.png',version:im.version,base64:Buffer.from('<?php echo 1;').toString('base64')},400);
  await owner.api('image',{id:'/../../config.php',version:im.version,base64:'a'},400);
  await owner.api('image',{id:'/assets/img/logo.png',version:im.version,base64:Buffer.from('GIF89a').toString('base64')},400);
  const oversized=execFileSync(php,['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=gd','-r','$i=imagecreatetruecolor(9000,1);ob_start();imagepng($i);echo base64_encode(ob_get_clean());']).toString();
  await owner.api('image',{id:'/assets/img/logo.png',version:im.version,base64:oversized},400);
  const png=execFileSync(php,['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=gd','-r','$i=imagecreatetruecolor(4,5);ob_start();imagepng($i);echo base64_encode(ob_get_clean());']).toString();
  const before=await stranger.request('/assets/img/logo.png');
  await owner.api('image',{id:'/assets/img/logo.png',version:im.version,base64:png});
  im=await owner.api('images');equal(im.images.find(i=>i.id==='/assets/img/logo.png').width,4,'reencoded image width');
  const bytes=await fetch(url+'/assets/img/logo.png');equal(bytes.headers.get('content-type'),'image/png','media safe MIME');const changed=Buffer.from(await bytes.arrayBuffer());ok(changed.length>0,'replacement served');
  await owner.api('undo',{version:im.version-1},409);await owner.api('undo',{version:im.version});
  const restored=await stranger.request('/assets/img/logo.png');equal(restored.text,before.text,'image undo restores original');
  for(const format of ['jpeg','webp']) {
   im=await owner.api('images');
   const image=execFileSync(php,['-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=gd','-r',`$i=imagecreatetruecolor(3,4);ob_start();image${format}($i);echo base64_encode(ob_get_clean());`]).toString();
   await owner.api('image',{id:'/assets/img/logo.png',version:im.version,base64:image});const response=await fetch(url+'/assets/img/logo.png');equal(response.headers.get('content-type'),'image/'+format,'uploaded '+format+' is safely served');
   im=await owner.api('images');await owner.api('undo',{version:im.version});
  }
  await owner.api('users',{action:'add',username:'editorfixture',password:'Editor-Temporary-2026',role:'editor',adminPassword:'Permanent-Fixture-2026'});
  await owner.api('users',{action:'add',username:'EDITORFIXTURE',password:'Editor-Temporary-2026',role:'editor',adminPassword:'Permanent-Fixture-2026'},409);
  const editor=new Client();await editor.login('editorfixture','Editor-Temporary-2026');await editor.api('pages',undefined,403);await editor.api('password',{current:'Editor-Temporary-2026',password:'Editor-Permanent-2026',confirm:'Editor-Permanent-2026'});await editor.session();await editor.api('users',undefined,403);await editor.api('users',{action:'add'},403);
  let users=(await owner.api('users')).users,editorUser=users.find(u=>u.username==='editorfixture'),ownerUser=users.find(u=>u.username==='eshahabian');
  equal((await editor.api('pages')).pages.length,0,'new designer has no default page grants');
  equal((await editor.request('/about?mana_preview='+editor.csrf)).status,403,'unassigned preview denied');
  await editor.api('permission-catalog',undefined,403);
  await owner.api('users',{action:'permissions',id:editorUser.id,permissions:{pages:['/admin']},adminPassword:'Permanent-Fixture-2026'},400);
  const originalPage=await owner.api('page&id=%2Fabout');const allowedField=originalPage.fields.find(f=>f.scope==='page'),deniedField=originalPage.fields.find(f=>f.scope==='footer');
  await owner.api('users',{action:'permissions',id:editorUser.id,permissions:{fields:[allowedField.id]},adminPassword:'Permanent-Fixture-2026'});
  await editor.api('pages',undefined,401);await editor.login('editorfixture','Editor-Permanent-2026');
  equal((await editor.api('pages')).pages.map(p=>p.id).join(','),'/about','only specifically assigned page listed');
  const scopedPreview=await editor.preview('/about');equal((scopedPreview.text.match(/data-mana-field=/g)||[]).length,1,'preview only marks the granted text');
  let ep=await editor.api('page&id=%2Fabout');equal(ep.fields.length,1,'only granted text returned');field=ep.fields[0];
  await editor.api('save',{page:'/about',version:ep.version,changes:[{id:field.id,value:'should not commit'},{id:deniedField.id,value:'forbidden footer'}]},403);
  equal((await owner.api('pages')).version,ep.version,'mixed authorized and forbidden changes do not partially commit');
  await editor.api('page&id=%2Ffaq',undefined,403);
  await editor.api('save',{page:'/faq',version:ep.version,changes:[]},403);
  await editor.api('menu',undefined,403);await editor.api('menu',{version:ep.version,items:[{label:'خانه',href:'/'}]},403);
  await editor.api('image',{id:'/assets/img/logo.png',version:ep.version,base64:png},403);
  await editor.api('undo',{version:ep.version},403);
  equal((await editor.api('images')).images.length,0,'unassigned images hidden');
  await editor.api('users',{action:'permissions',id:editorUser.id,permissions:{pages:['/faq']}},403);
  await editor.api('save',{page:'/about',version:ep.version,changes:[{id:field.id,value:'ویرایش کاربر آزمایشی'}]});
  await owner.api('users',{action:'permissions',id:editorUser.id,permissions:{pages:['/about'],menu:true,images:['/assets/img/logo.png']},adminPassword:'Permanent-Fixture-2026'});
  await editor.api('pages',undefined,401);await editor.login('editorfixture','Editor-Permanent-2026');
  await editor.preview('/about');ep=await editor.api('page&id=%2Fabout');ok(ep.fields.length>1&&ep.fields.every(f=>f.scope==='page'),'whole page grant excludes shared header and footer');
  await editor.api('menu');let assignedImages=await editor.api('images');equal(assignedImages.images.length,1,'only explicit image granted');
  await editor.api('image',{id:'/assets/img/hero.webp',version:assignedImages.version,base64:png},403);
  await editor.api('image',{id:'/assets/img/logo.png',version:assignedImages.version,base64:png});let v=await owner.api('pages');await owner.api('undo',{version:v.version});
  await owner.api('users',{action:'permissions',id:editorUser.id,permissions:{footer:true},adminPassword:'Permanent-Fixture-2026'});
  await editor.login('editorfixture','Editor-Permanent-2026');await editor.preview('/faq');const footerPage=await editor.api('page&id=%2Ffaq');ok(footerPage.fields.length>0&&footerPage.fields.every(f=>f.scope==='footer'),'shared footer grant does not grant page text');
  await owner.api('users',{action:'permissions',id:editorUser.id,permissions:{fields:[allowedField.id]},adminPassword:'Permanent-Fixture-2026'});
  await editor.api('pages',undefined,401);
  await owner.api('users',{action:'update',id:ownerUser.id,role:'editor',active:false,adminPassword:'Permanent-Fixture-2026'},400);
  await owner.api('users',{action:'reset',id:ownerUser.id,password:'Reset-Fixture-2026',adminPassword:'Permanent-Fixture-2026'},400);
  await owner.api('users',{action:'update',id:editorUser.id,role:'editor',active:false,adminPassword:'Permanent-Fixture-2026'});await editor.api('pages',undefined,401);
  await owner.api('users',{action:'update',id:editorUser.id,role:'editor',active:true,adminPassword:'Permanent-Fixture-2026'});await editor.login('editorfixture','Editor-Permanent-2026');
  await owner.api('users',{action:'update',id:editorUser.id,role:'admin',active:true,adminPassword:'Permanent-Fixture-2026'});await editor.api('pages',undefined,401);
  await editor.login('editorfixture','Editor-Permanent-2026');
  await owner.api('users',{action:'reset',id:editorUser.id,password:'Reset-Temporary-2026',adminPassword:'Permanent-Fixture-2026'});await editor.api('pages',undefined,401);
  await editor.login('editorfixture','Reset-Temporary-2026');await editor.api('images',undefined,403);
  // Last finalized administrator remains protected, even when a pending admin exists.
  await editor.api('password',{current:'Reset-Temporary-2026',password:'Editor-Second-Final-2026',confirm:'Editor-Second-Final-2026'});await editor.session();
  await editor.api('users',{action:'update',id:ownerUser.id,role:'editor',active:true,adminPassword:'Editor-Second-Final-2026'});await owner.api('pages',undefined,401);
  await owner.login('eshahabian','Permanent-Fixture-2026');await owner.api('users',undefined,403);
  await editor.api('users',{action:'update',id:ownerUser.id,role:'admin',active:true,adminPassword:'Editor-Second-Final-2026'});await owner.login('eshahabian','Permanent-Fixture-2026');
  s=await owner.api('pages');await owner.api('save',{page:'/about',version:s.version,changes:[{id:field.id,value:'هزینه جلسه ۵۰۰ تومان'}]},400);
  await owner.api('logout',{});await owner.api('pages',undefined,401);await owner.login('eshahabian','Permanent-Fixture-2026');
  await stranger.session();await stranger.api('login',{username:'eshahabian',password:'Temporary-Fixture-2026',activation:'fixture-activation'},401);
  // Expiry is tested on a synthetic session, never on an owner session.
  const expired=new Client();await expired.login('eshahabian','Permanent-Fixture-2026');const sid=expired.cookies.get('MANA_CMS_fixtureonly');const sessFile=path.join(data,'sessions','sess_'+sid);let content=await fs.readFile(sessFile,'utf8');content=content.replace(/last\|i:\d+;/,'last|i:1;');await fs.writeFile(sessFile,content);await expired.api('pages',undefined,401);
  const beforeDeploy=await fs.readFile(path.join(data,'content.json'),'utf8');await fs.cp(path.join(repo,'cpanel/psy-admin'),path.join(site,'psy-admin'),{recursive:true,filter:src=>path.basename(src)!=='installation.php'});equal(await fs.readFile(path.join(data,'content.json'),'utf8'),beforeDeploy,'redeploy preserves content');await owner.api('pages');
  const raw=await fs.readFile(path.join(data,'users.json'),'utf8');ok(!raw.includes('Permanent-Fixture-2026'),'no plaintext passwords');
  // A failed atomic replacement leaves the live content and undo record untouched.
  await fs.mkdir(path.join(data,'blocked.json'));
  const untouched=await fs.readFile(path.join(data,'content.json'),'utf8');
  execFileSync(php,['-d','display_errors=0','-d','log_errors=0','-r',`require $argv[1];try{\\ManaCms\\write('blocked',['test'=>1]);exit(1);}catch(Throwable $e){exit(0);}`,path.join(site,'psy-admin/core.php')],{env:{...process.env,MANA_CMS_DATA_DIR:data}});
  equal(await fs.readFile(path.join(data,'content.json'),'utf8'),untouched,'failed write leaves content intact');ok(!(await fs.readdir(data)).some(f=>f.endsWith('.tmp')),'failed write cleans temporary file');
  // A template change must never apply edits to unrelated new markup.
  await owner.preview('/about');let currentPage=await owner.api('page&id=%2Fabout');const aboutSource=await fs.readFile(path.join(site,'pages/about.php'),'utf8');await fs.appendFile(path.join(site,'pages/about.php'),'\n<?php /* changed template fixture */ ?>');
  await owner.api('save',{page:'/about',version:currentPage.version,changes:[]},409);
  html=await stranger.request('/about');ok(!html.text.includes('ویرایش کاربر آزمایشی'),'template fingerprint invalidates obsolete edits');await fs.writeFile(path.join(site,'pages/about.php'),aboutSource);
  equal((await stranger.request('/psy-admin/installation.php')).status,403,'config direct access denied');equal((await stranger.request('/private/content.json')).status,403,'private data inaccessible');equal((await stranger.request('/about?mana_preview=wrong')).status,401,'anonymous preview denied');
  const attacker=new Client();await attacker.session();let throttled=false;for(let i=0;i<10;i++){const r=await attacker.request('/psy-admin/?api=login',{username:'doesnotexist',password:'bad'});if(r.status===429){throttled=true;break;}}ok(throttled,'login rate limit');
  ok(!/PHP (?:Fatal error|Warning|Deprecated)/.test(logs),'no PHP runtime warnings');
  console.log(`PASS: ${checks} checks. Real about/FAQ/layout + synthetic public records. No production account or database touched.`);
  if(process.env.CMS_KEEP_TEST==='1'){await fs.writeFile(path.join(data,'attempts.json'),'{}');await fs.writeFile(path.join(root,'server.json'),JSON.stringify({pid:server.pid,root,site,data,url}));console.log('Temporary test site: '+site);console.log('Synthetic test data: '+data);console.log('Test server remains available at '+url+'/psy-admin/');server.unref();server.stdout.destroy();server.stderr.destroy();server=null;}
 } finally {if(server&&server.exitCode===null){server.kill();await new Promise(r=>server.once('exit',r));}if(process.env.CMS_KEEP_TEST!=='1'&&root)await fs.rm(root,{recursive:true,force:true});}
});
