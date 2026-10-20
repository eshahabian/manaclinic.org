const fs=require('fs');
const {chromium}=require('C:/Users/Emad/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('assert');
(async()=>{
const browser=await chromium.launch({headless:true,channel:'chrome'});
const page=await browser.newPage({viewport:{width:1280,height:950}});
let markup=fs.readFileSync('cpanel/includes/home_therapist_guide.php','utf8'); markup=markup.slice(markup.indexOf('?>')+2);
markup=markup.slice(0,markup.indexOf('<script type="application/json"'));
markup=markup.replace(/<link[^>]+>/g,'').replace(/<\?=[\s\S]*?\?>/g,'#');
const data={doctors:[{name:'درمانگر نمونه',href:'/doctors/example',domains:['individual'],focus:['anxiety']}],courses:[{title:'کارگاه اضطراب'}],domains:{individual:'مشاوره فردی',child:'کودک و نوجوان',couples:'زوج درمانی',family:'خانواده درمانی',premarital:'پیش از ازدواج'},focus:{anxiety:'اضطراب',growth:'رشد فردی'},directory:'/doctors',workshops:'/workshops',contact:'/contact'};
await page.setContent('<html dir="rtl" lang="fa"><style>'+fs.readFileSync('cpanel/assets/css/style.css','utf8')+fs.readFileSync('cpanel/assets/css/therapist-guide.css','utf8')+'</style><main class="container-page">'+markup+'</main><script id="mana-guide-data" type="application/json">'+JSON.stringify(data)+'</script></html>');
await page.addScriptTag({path:'cpanel/assets/js/therapist-guide.js'});
const start=page.locator('[data-guide-start]'); await start.click();
await page.getByRole('button',{name:'ادامه',exact:true}).click();
assert(await page.getByRole('alert').innerText());
async function next(value){await page.locator('input[value="'+value+'"]').check();await page.getByRole('button',{name:'ادامه',exact:true}).click();}
await next('individual');await next('adult');
await page.locator('input[value="anxiety"]').check();await page.locator('input[value="growth"]').check();await page.getByRole('button',{name:'ادامه',exact:true}).click();
assert.equal(await page.locator('#mana-guide-content input').count(),2);
await next('anxiety');await next('high');await page.locator('input[value="learn"]').check();await page.getByRole('button',{name:'دیدن مسیر پیشنهادی'}).click();
assert(await page.getByRole('link',{name:/درمانگر نمونه/}).count()); assert(await page.getByRole('link',{name:/کارگاه اضطراب/}).count());assert((await page.locator('#mana-guide-content').innerText()).includes('اولویت'));
await page.getByRole('button',{name:'شروع دوباره'}).click();await next('individual');await next('child');await next('growth');await next('growth');await next('mild');await page.locator('input[value="therapy"]').check();await page.getByRole('button',{name:'دیدن مسیر پیشنهادی'}).click();
assert((await page.locator('#mana-guide-content').innerText()).includes('کودک و نوجوان'));assert((await page.locator('#mana-guide-content').innerText()).includes('پیدا نشد'));
await page.keyboard.press('Escape');assert(!(await page.locator('dialog').evaluate(n=>n.open)));await start.click();assert.equal(await page.locator('input:checked').count(),0);await page.keyboard.press('Escape');
await page.screenshot({path:'output/therapist-guide/desktop.png',fullPage:true});
await page.setViewportSize({width:390,height:844});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await start.click();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'output/therapist-guide/mobile.png',fullPage:true});
await browser.close();console.log('PASS: validation, multiple topics, primary topic, verified-field matches, workshop title matches, high impact, child routing, no match, escape/reset, desktop/mobile width');
})().catch(e=>{console.error(e);process.exit(1)});
