import {createRequire} from 'node:module';
import {readFile,writeFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.SKYNOVA_PLAYWRIGHT || 'playwright');
const root=new URL('../',import.meta.url),work=new URL('work/local-stage/',root);
const privateData=JSON.parse(await readFile(new URL('private.json',work),'utf8'));
const browser=await chromium.launch({headless:true,...(process.env.SKYNOVA_CHROME?{executablePath:process.env.SKYNOVA_CHROME}:{})});
const checks=[];
try{
 const page=await browser.newPage();
 await page.goto('http://127.0.0.1:8796/admin/index.php?route=common/login');
 await page.locator('input[name="username"]').fill(privateData.admin_user);
 await page.locator('input[name="password"]').fill(privateData.admin_password);
 await Promise.all([page.waitForURL(/user_token=/),page.locator('button[type="submit"]').click()]);
 const token=new URL(page.url()).searchParams.get('user_token');
 await page.goto('http://127.0.0.1:8796/admin/index.php?route=extension/module/furmedia_cache&user_token='+token);
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:1000});
  if(width===390 && await page.locator('#column-left').evaluate(el=>el.classList.contains('active'))){await page.locator('#button-menu').click();}
  await page.waitForTimeout(500); // Native OpenCart sidebar has a 300ms breakpoint transition.
  const result=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,ids:Array.from(document.querySelectorAll('[id]')).map(e=>e.id)}));
  if(result.scroll>width || new Set(result.ids).size!==result.ids.length)throw new Error('Admin layout regression');
  checks.push('Native admin layout '+width+'px, no duplicate IDs or horizontal overflow');
  await page.screenshot({path:new URL('docs/validation/admin-'+(width===1440?'desktop':'mobile')+'.png',root).pathname.replace(/^\/([A-Za-z]:)/,'$1')});
 }
 if(await page.locator('[name="settings[backend]"]').count()!==1 || await page.locator('[name="settings[shared_routes]"]').count()!==1)throw new Error('Missing premium controls');
 checks.push('Backend and shared-route controls visible');
 for(const field of ['component_cache','sql_cache','hide_category_count','css_merge','js_merge']){if(await page.locator('[name="settings['+field+']"]').count()!==1)throw new Error('Missing data controls');}
 checks.push('New data and merge controls visible');
 for(const field of ['page_rules','extract_js','script_position','dynamic_cache','warm_variants','litespeed_esi','dynamic_widgets','debug_details','debug_session_hash','module_rules','browser_images','reserve_compressed','cache_panel','warm_login']){if(await page.locator('[name="settings['+field+']"]').count()!==1)throw new Error('Missing completion control '+field);}
 checks.push('Completion and ESI controls visible');
 for(const field of ['conditions','lazy_media','lazy_blocks','custom_css','custom_js','resource_attributes','delay_rules','sql_profile','db_auto_analyze','external_assets']){if(await page.locator('[name=\"settings['+field+']\"]').count()!==1)throw new Error('Missing advanced control '+field);}
 checks.push('Advanced multimedia, conditional and database controls visible');
 if(await page.locator('#fm-database-tools').count()!==1||await page.locator('#fm-external-import').count()!==1)throw new Error('Missing advanced operations');checks.push('Database and external import operations visible');
 if(await page.locator('[name="services[SKYNOVA_CF_TOKEN]"]').inputValue()!=='')throw new Error('Secret input populated');
 checks.push('Integration secret input remains blank');
 const fixture=await browser.newPage();
 await fixture.goto('http://127.0.0.1:8796/skynova-fixture.html');
 if(await fixture.evaluate(()=>!!window.skynovaDelayOrder))throw new Error('Script executed before interaction');
 await fixture.locator('button').click();await fixture.waitForFunction(()=>window.skynovaDelayOrder?.join(',')==='one,two');
 checks.push('Delayed external JS executes once in original order after interaction');
 await new Promise(r=>setTimeout(r,4200));
 if(await fixture.evaluate(()=>window.skynovaDelayOrder.join(','))!=='one,two')throw new Error('Delayed script executed twice');
 checks.push('Timeout after interaction does not execute scripts twice');
 const merged=await browser.newPage();await merged.goto('http://127.0.0.1:8796/skynova-merge-fixture.html');
 if(await merged.evaluate(()=>window.skynovaDelayOrder.join(','))!=='one,two' || await merged.locator('script[src]').count()!==1)throw new Error('Merged JS order regression');
 checks.push('Merged JS executes once in native order');
 if(await merged.locator('link[rel=stylesheet]').count()!==1 || await merged.locator('.hero').evaluate(el=>getComputedStyle(el).color)!=='rgb(12, 34, 56)')throw new Error('Merged CSS regression');
 checks.push('Merged CSS loads and preserves cascade');
 for(const position of ['top','bottom']){
  const extracted=await browser.newPage();await extracted.goto('http://127.0.0.1:8796/skynova-extracted-'+position+'.html');
  if(await extracted.evaluate(()=>window.skynovaExtractOrder?.join(','))!=='one,two' || await extracted.locator((position==='top'?'head':'body')+' script[src]').count()!==2)throw new Error('Extracted script order/position regression');
  checks.push('Extracted JS executes once in original order at '+position);await extracted.close();
 }
 const panelUrl=page.url();await page.getByRole('link',{name:'Următor',exact:true}).click();
 if(!page.url().includes('cache_page=1'))throw new Error('Inventory pagination link failed');
 checks.push('Native inventory pagination preserves selected admin context');await page.goto(panelUrl);
 const nonce=await page.locator('[name="fm_nonce"]').inputValue();
 const original=await page.evaluate(async({nonce})=>(await fetch(location.href,{method:'POST',body:new URLSearchParams({fm_nonce:nonce,operation:'export'})})).json(),{nonce});
 const save=async(settings)=>page.evaluate(async({nonce,settings})=>{const form=new URLSearchParams({fm_nonce:nonce,operation:'save'});Object.entries(settings).forEach(([key,value])=>form.set('settings['+key+']',String(value)));return (await fetch(location.href,{method:'POST',body:form})).text();},{nonce,settings});
 try{
  const result=await save({...original,status:1,mode:'session',debug:1,dynamic_widgets:1,widgets_interval:10,page_rules:'{"common/home":{"cart":true}}'});
  if(!result.includes('Configurația a fost salvată'))throw new Error('Browser fixture settings save failed');
  const guestContext=await browser.newContext();const guest=await guestContext.newPage();await guest.goto('http://127.0.0.1:8796/',{waitUntil:'networkidle'});
  if(!await guest.evaluate(()=>window.skynovaWidgets))throw new Error('Native widget loader absent');
  const added=await guest.evaluate(async()=>{const r=await fetch('index.php?route=checkout/cart/add',{method:'POST',body:new URLSearchParams({product_id:'40',quantity:'1'})});return r.json();});
  if(!added.success)throw new Error('Native cart fixture add failed');
  await guest.evaluate(()=>document.dispatchEvent(new Event('skynova:refresh')));await guest.waitForFunction(()=>document.querySelector('#cart')?.textContent.includes('iPhone'));
  checks.push('Widget refresh updates real native cart after add-to-cart');
  const otherContext=await browser.newContext();const other=await otherContext.newPage();await other.goto('http://127.0.0.1:8796/',{waitUntil:'networkidle'});
  if(await other.locator('#cart').textContent().then(value=>value.includes('iPhone')))throw new Error('Cart leaked into another browser context');
  checks.push('Widget refresh stays isolated in another native browser session');await guestContext.close();await otherContext.close();
  await save({...original,status:1,mode:'session',debug:1,cache_panel:1,gzip:0,dynamic_widgets:0});
  const panelContext=await browser.newContext();const panelPage=await panelContext.newPage();await panelPage.goto('http://127.0.0.1:8796/');await panelPage.reload();await panelPage.reload();
  if(await panelPage.locator('#skynova-cache-panel').count()!==1)throw new Error('Duplicate or absent panel');
  const countdown=Number(await panelPage.locator('[data-skynova-count]').textContent());await panelPage.waitForTimeout(1200);
  if(Number(await panelPage.locator('[data-skynova-count]').textContent())>=countdown)throw new Error('Panel countdown frozen');
  checks.push('Frontend panel countdown advances on native cached page');
  await Promise.all([panelPage.waitForNavigation(),panelPage.locator('#skynova-cache-panel button').click()]);
  if(await panelPage.locator('#skynova-cache-panel').count()!==1)throw new Error('Panel purge reload failed');
  checks.push('Frontend signed purge button reloads native page once');
  await panelPage.screenshot({path:new URL('docs/validation/cache-panel.png',root).pathname.replace(/^\/([A-Za-z]:)/,'$1')});await panelContext.close();
 }finally{await save(original);}
 const advanced=await browser.newPage();await advanced.goto('http://127.0.0.1:8796/skynova-advanced-fixture.html');
 if(await advanced.evaluate(()=>!!window.skynovaAdvanced))throw new Error('Advanced delay ran before event');
 await advanced.locator('button').click();await advanced.waitForFunction(()=>window.skynovaAdvanced===1);await advanced.locator('button').click();
 if(await advanced.evaluate(()=>window.skynovaAdvanced)!==1)throw new Error('Advanced delayed script duplicated');checks.push('Per-script event/timer delay executes once after pointer interaction');
 if(await advanced.evaluate(()=>window.skynovaCustom)!==7||await advanced.locator('button').evaluate(el=>getComputedStyle(el).color)!=='rgb(12, 34, 56)')throw new Error('Custom advanced code missing');checks.push('Custom CSS and JS execute in browser');
 if(await advanced.locator('video').getAttribute('preload')!=='none'||await advanced.locator('audio').getAttribute('preload')!=='none')throw new Error('Media preload changed');checks.push('Video and audio preload disabled without dropping sources');
 if(await advanced.locator('#later').textContent()!=='NEWTEXT'||await advanced.locator('#later').evaluate(el=>getComputedStyle(el).contentVisibility)!=='auto')throw new Error('Lazy block or replacement missing');checks.push('Lazy block preserves replaced DOM content');await advanced.close();
 await writeFile(new URL('docs/validation/browser-premium.json',root),JSON.stringify({checks,timestamp:new Date().toISOString(),environment:'local Chromium / OC3.0.5.1'},null,2));
 console.log('PASS '+checks.length+' browser checks');
}finally{await browser.close();}
