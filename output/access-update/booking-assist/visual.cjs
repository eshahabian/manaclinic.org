const fs=require('fs');const {chromium}=require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root='D:/Psychology',d=root+'/output/access-update/booking-assist';
(async()=>{
 const qa=JSON.parse(fs.readFileSync(d+'/tests.json','utf8'));
 const src=fs.readFileSync(root+'/cpanel/includes/staff_app.php','utf8');
 const css=fs.readFileSync(root+'/cpanel/assets/css/style.css','utf8')+'\n'+src.slice(src.indexOf('  <style>',src.indexOf('function staff_app_render('))+9).split('</style>')[0];
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});let checks=0;const errors=[],requests=[];
 function assert(ok,label){if(!ok)throw Error(label);checks++;}
 try{
  const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());if(u.hostname!=='booking-fixture.test')return route.abort();
   if(u.pathname==='/api/availability-days')return route.fulfill({json:{days:['2026-10-30']}});
   if(u.pathname==='/api/slots')return route.fulfill({json:{slots:[{value:'10:00',label:'10:00',minutes:['00','30']},{value:'11:00',label:'11:00',minutes:['00','30']}],booked:[]}});
   if(u.pathname==='/api/room-availability'){
    requests.push({room:u.searchParams.get('room'),date:u.searchParams.get('date')});
    const room=Number(u.searchParams.get('room'));if(room===1)await new Promise(r=>setTimeout(r,200));
    return route.fulfill({json:{room,date:u.searchParams.get('date'),segments:room===1?qa.segments:[{start:'00:00',end:'24:00',busy:false}]}});
   }
   if(u.pathname.startsWith('/assets/')){
    const p=root+'/cpanel'+u.pathname;if(!fs.existsSync(p))return route.abort();
    return route.fulfill({body:fs.readFileSync(p),contentType:u.pathname.endsWith('.js')?'application/javascript':u.pathname.endsWith('.css')?'text/css':'application/octet-stream'});
   }
   return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style></head><body class="sapp"><main class="sapp-main"><h1>ثبت وقت</h1>'+qa.html+'</main>'+qa.scripts+'</body></html>'});
  });
  async function choosePatient(id){await page.locator('#patient_id').evaluate((el,v)=>{el.value=v;el.dispatchEvent(new Event('change',{bubbles:true}));},id);}
  for(const width of [360,390,768]){
   await page.setViewportSize({width,height:1100});await page.goto('http://booking-fixture.test/app/appointments?day=2026-10-30');
   assert(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),'No horizontal overflow '+width);
   assert(!await page.locator('#patient_id').textContent().then(s=>s.includes('private.user')),'No username in patient label');
   assert((await page.locator('label[for="doctor_id"]').textContent()).trim()==='درمانگر','Therapist label');
   await choosePatient('p1');assert(await page.locator('#doctor_id').inputValue()==='d1','Assigned therapist selected');
   assert((await page.locator('#doctor_id').locator('..').locator('input.search-select-input').inputValue()).includes('درمانگر یک'),'Enhanced select synced');
   assert(!await page.locator('#sec-date-view').isDisabled(),'Therapist calendar enabled');
   await page.locator('#sec-doctor-clear').click();assert(await page.locator('#doctor_id').inputValue()==='','Cross clears therapist');
   assert(await page.locator('#sec-date-view').isDisabled(),'Clear resets date availability');
   await page.locator('#doctor_id-list [data-value="d2"]').click();assert(await page.locator('#doctor_id').inputValue()==='d2','Manual alternate therapist allowed');
   await choosePatient('p3');assert(await page.locator('#doctor_id').inputValue()==='','Patient without assignment clears previous therapist');
   await choosePatient('p4');assert(await page.locator('#doctor_id').inputValue()==='d2','Stable history therapist selected');
   await choosePatient('p1');
   await page.locator('#sec-date-view').evaluate(el=>{el.value='1405/08/08';el.dispatchEvent(new Event('change',{bubbles:true}));});
   await page.waitForFunction(()=>document.querySelector('#sec-date').value==='2026-10-30');
   await page.locator('#sec-room-btn').click();await page.locator('[data-room-value="1"]').click();
   await page.waitForFunction(()=>document.querySelectorAll('[data-room-segments] > div').length===3);
   const text=await page.locator('#sec-room-availability').textContent();assert(text.includes('خالی')&&text.includes('پر')&&text.includes('۰۹:۱۵')&&text.includes('۱۱:۰۰'),'Exact free and occupied intervals');
   assert(!text.includes('PRIVATE'),'No patient details in occupancy');
   await page.locator('#sec-room-btn').click();await page.locator('[data-room-value="2"]').click();
   await page.waitForFunction(()=>document.querySelectorAll('[data-room-segments] > div').length===1);
   assert((await page.locator('#sec-room-availability').textContent()).includes('اتاق ۲'),'Room selection updates status');
   if(width===390)await page.screenshot({path:d+'/mobile.png',fullPage:true});
   const n=requests.length;await page.locator('#sec-room-btn').click();await page.locator('[data-room-value="4"]').click();
   assert((await page.locator('#sec-room-availability').textContent()).includes('خارج از کلینیک'),'Outside clinic has no room requirement');assert(requests.length===n,'No occupancy request for outside clinic');
   await page.locator('#sec-doctor-clear').click();assert(await page.locator('#sec-date').inputValue()==='','Therapist change clears stale date');
  }
  assert(errors.length===0,'No JavaScript runtime errors: '+errors.join(';'));
  fs.writeFileSync(d+'/visual.json',JSON.stringify({passed:true,checks,requests:requests.length,errors},null,2));console.log(JSON.stringify({passed:true,checks,requests:requests.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
