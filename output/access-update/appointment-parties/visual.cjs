const fs=require('fs');
const {chromium}=require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root='D:/Psychology';const dir=root+'/output/access-update/appointment-parties';
(async()=>{
 const qa=JSON.parse(fs.readFileSync(dir+'/tests.json','utf8'));
 const mainCss=fs.readFileSync(root+'/cpanel/assets/css/style.css','utf8');
 const source=fs.readFileSync(root+'/cpanel/includes/staff_app.php','utf8');
 const css=source.slice(source.indexOf('  <style>',source.indexOf('function staff_app_render('))+9).split('</style>')[0];
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 const checks=[];
 try{
  const page=await browser.newPage();
  await page.route('**/*',route=>route.abort());
  for(const width of [360,390,768]){
   await page.setViewportSize({width,height:1000});
   for(const type of ['debt_html','credit_html','empty_html']){
    const html='<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+mainCss+'\n'+css+'</style></head><body class="sapp"><main class="sapp-main"><h1>ثبت وقت</h1><form class="panel" id="booking-fixture"><label class="label">مراجعه‌کننده</label><select class="input"><option>مراجع نمونه</option></select><label class="label">درمانگر</label><select class="input"><option>درمانگر نمونه</option></select><button class="btn btn-primary" type="button" style="margin-top:1rem">ثبت نوبت</button></form>'+qa[type]+'<h2>وقت‌های روز</h2></main></body></html>';
    await page.setContent(html);await page.evaluate(()=>document.fonts.ready);
    if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Horizontal overflow '+width+' '+type);
    const tabs=page.locator('#appointment-parties .sapp-check-tab');
    if(await tabs.count()!==2)throw Error('Missing tabs');
    if(await page.locator('#appointment-parties [aria-current="page"]').count()!==1)throw Error('Active state');
    for(const box of await tabs.evaluateAll(items=>items.map(x=>({w:x.getBoundingClientRect().width,h:x.getBoundingClientRect().height}))))if(box.h<43||box.w<80)throw Error('Tab touch target');
    const position=await page.evaluate(()=>({form:document.querySelector('#booking-fixture').getBoundingClientRect().bottom,finance:document.querySelector('#appointment-parties').getBoundingClientRect().top}));
    if(position.finance<position.form)throw Error('Finance above form');
    if(width===390&&type!=='empty_html')await page.screenshot({path:dir+'/'+type+'-mobile.png',fullPage:true});
    checks.push({width,type,overflow:false});
   }
  }
  fs.writeFileSync(dir+'/visual.json',JSON.stringify({passed:true,cases:checks},null,2));
  console.log(JSON.stringify({passed:true,cases:checks.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
