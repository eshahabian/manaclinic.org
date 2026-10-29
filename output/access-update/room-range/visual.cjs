const fs=require('fs');const {chromium}=require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root='D:/Psychology',d=root+'/output/access-update/room-range';
(async()=>{
 const qa=JSON.parse(fs.readFileSync(d+'/tests.json','utf8'));
 const src=fs.readFileSync(root+'/cpanel/includes/staff_app.php','utf8');
 const css=fs.readFileSync(root+'/cpanel/assets/css/style.css','utf8')+'\n'+src.slice(src.indexOf('  <style>',src.indexOf('function staff_app_render('))+9).split('</style>')[0];
 const scripts=qa.scripts.replace(/<script src="[^"]*"><\/script>/g,'');
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});let checks=0,posted=[];
 try{
  const page=await browser.newPage();
  await page.route('**/*',async route=>{
   const r=route.request(),u=new URL(r.url());if(u.hostname!=='room-fixture.test')return route.abort();
   let room=u.searchParams.get('room')||'1';
   if(r.method()==='POST'){const data=new URLSearchParams(r.postData());posted.push(Object.fromEntries(data));room=data.get('room_no')||data.get('room_tab')||'1';}
   const html=qa.fixtures['room'+room]||qa.fixtures.room1;
   await route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style></head><body class="sapp"><main class="sapp-main">'+html+'</main>'+scripts+'</body></html>'});
  });
  for(const width of [360,390,768])for(const room of [1,2,3]){
   await page.setViewportSize({width,height:1000});await page.goto('http://room-fixture.test/app/rooms?day=2030-10-29&room='+room);
   if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Overflow');checks++;
   const tabs=page.locator('nav[aria-label="انتخاب اتاق"] a');if(await tabs.count()!==3)throw Error('Three tabs required');
   const ys=await tabs.evaluateAll(items=>items.map(x=>x.getBoundingClientRect().y));if(Math.max(...ys)-Math.min(...ys)>1)throw Error('Tabs wrap');checks++;
   const form=page.locator('form').filter({has:page.locator('input[value="assign"]')});if(await form.count()!==1)throw Error('Multiple booking forms');
   const box=await form.boundingBox(),heading=await page.getByRole('heading',{name:'رزروهای ثبت‌شده'}).boundingBox();if(box.y+box.height>heading.y+1)throw Error('Reservations before form');checks++;
   if(await form.locator('input[name="room_no"]').inputValue()!==String(room))throw Error('Wrong room');checks++;
   const startBox=await form.locator('[name="start_time"]').boundingBox(),endBox=await form.locator('[name="end_time"]').boundingBox();if(Math.abs(startBox.y-endBox.y)>1||startBox.width<80||endBox.width<80||!await form.locator('[name="end_time"]').evaluate(el=>el.required))throw Error('Two time boxes');checks++;
   if(width===390&&room===1)await page.screenshot({path:d+'/mobile.png',fullPage:true});
  }
  await page.setViewportSize({width:390,height:1000});await page.goto('http://room-fixture.test/app/rooms?day=2030-10-29&room=1');
  await page.locator('nav[aria-label="انتخاب اتاق"] a').nth(1).click();if(new URL(page.url()).searchParams.get('room')!=='2')throw Error('Tab navigation');checks++;
  const form=page.locator('form').filter({has:page.locator('input[value="assign"]')});
  await form.locator('[name="start_time"]').fill('10:30');await form.locator('[name="end_time"]').fill('12:15');await form.locator('[name="purpose"]').selectOption('block');await form.locator('[name="block_title"]').fill('رزرو آزمایشی');
  if(!await form.locator('[name="patient_id"]').isDisabled())throw Error('Hidden patient fields enabled');checks++;
  await form.locator('[name="repeat_weekly"]').check();await form.locator('[name="repeat_weeks"]').fill('2');
  await form.getByRole('button',{name:'رزرو اتاق',exact:true}).click();
  const post=posted[0];if(!post||post.action!=='assign'||post.room_no!=='2'||post.room_tab!=='2'||post.repeat_weeks!=='2'||post.csrf_token!=='test-only'||post.hour_only!==undefined||post.start_time!=='10:30'||post.end_time!=='12:15')throw Error('POST fields changed');checks++;
  await page.locator('form').filter({has:page.locator('input[value="release_hour"]')}).getByRole('button').click();
  if(posted[1]?.action!=='release_hour'||posted[1].room_tab!=='2'||!posted[1].slice_start)throw Error('Release fields changed');checks++;
  fs.writeFileSync(d+'/visual.json',JSON.stringify({passed:true,checks,mockedPosts:posted.length},null,2));console.log(JSON.stringify({passed:true,checks,mockedPosts:posted.length}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
