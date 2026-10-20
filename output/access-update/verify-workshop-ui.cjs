const { chromium } = require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try{
  const page=await browser.newPage();
  for(const width of [1190,390]){
   await page.setViewportSize({width,height:950});
   await page.goto('file:///D:/Psychology/output/workshop-ui/fixture.html');
   await page.evaluate(()=>document.fonts.ready);
   const dimensions=await page.locator('.wcard-tabbar').evaluate(el=>{
    const boxes=[...el.querySelectorAll(':scope > label,.workshop-card-actions .btn')].map(e=>e.getBoundingClientRect());
    return {count:boxes.length,widths:boxes.map(r=>r.width),heights:boxes.map(r=>r.height),overflow:document.documentElement.scrollWidth>innerWidth};
   });
   if(width===1190){const rows=await page.locator('.wcard-tabbar').evaluate(el=>new Set([...el.querySelectorAll(':scope > label,.workshop-card-actions .btn')].map(e=>Math.round(e.getBoundingClientRect().top))).size);if(rows!==2)throw Error('Expected two rows, got '+rows);}if(!await page.locator('.wcard-panel-summary').isVisible())throw Error('Summary not default');
   if(dimensions.overflow||new Set(dimensions.widths.map(Math.round)).size>2)throw new Error(JSON.stringify(dimensions));
   await page.locator('label[for="wcard-demo-member"]').click();
   if(!await page.locator('.wcard-panel-member').isVisible())throw new Error('Member panel did not open');
   if(await page.locator('#member-patient-demo option[value="demo-admin"]').count()!==1)throw new Error('Admin candidate missing');
   await page.locator('label[for="wcard-demo-enroll"]').click();
   if(!await page.locator('.workshop-remove-member').isVisible())throw new Error('Removal control missing');
   await page.screenshot({path:'D:/Psychology/output/workshop-ui/'+width+'.png',fullPage:true});
   console.log('PASS responsive workshop toolbar '+width+'px; '+dimensions.count+' aligned controls; member tab works');
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
