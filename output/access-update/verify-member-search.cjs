const { chromium } = require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try{
  const page=await browser.newPage();
  await page.goto('file:///D:/Psychology/output/workshop-ui/fixture.html');
  await page.addScriptTag({path:'D:/Psychology/cpanel/assets/js/search-select.js'});
  await page.addScriptTag({path:'D:/Psychology/cpanel/assets/js/workshop-member-search.js'});
  await page.locator('label[for="wcard-demo-member"]').click();
  const input=page.locator('.workshop-member-form .search-select-input');
  for(const query of ['عماد','eshahabian','09121234567','۰۹۱۲۱۲۳۴۵۶۷','+989121234567','4567']){
   await input.fill(query);
   const result=page.locator('.workshop-member-form .search-select-option');
   if(await result.count()!==1)throw new Error('Search failed: '+query);
   console.log('PASS member search: '+query);
  }
  await page.locator('.workshop-member-form .search-select-option').click();
  if(await page.locator('#member-patient-demo').inputValue()!=='demo-admin')throw new Error('Wrong candidate selected');
  console.log('PASS search selects original account id');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
