import {createRequire} from 'node:module';
import {writeFile} from 'node:fs/promises';
const require=createRequire(import.meta.url),{chromium}=require(process.env.SKYNOVA_PLAYWRIGHT);
const browser=await chromium.launch({headless:true,executablePath:process.env.SKYNOVA_CHROME});const checks=[];
function check(ok,name){if(!ok)throw Error(name);checks.push(name);}
try{
 const context=await browser.newContext(),page=await context.newPage();await page.goto('http://127.0.0.1:8799/');
 await page.waitForFunction(()=>navigator.serviceWorker.controller!==null);
 const asset='/image/cache/furmedia_cache/'+'a'.repeat(64)+'.css';
 check(await page.evaluate(async u=>(await fetch(u)).status===200,asset),'Public immutable asset fetched');
 check(await page.evaluate(async()=>{await fetch('/account');await fetch('/checkout');const ns=await caches.keys();for(const n of ns){const c=await caches.open(n);const keys=await c.keys();if(keys.some(k=>/account|checkout/.test(k.url)))return false;}return true;}),'Private account and checkout excluded');
 await context.setOffline(true);check(await page.evaluate(async u=>(await fetch(u)).text(),asset)==='body{color:#123456}','Generated asset available offline');
 check(await page.evaluate(async()=>{try{await fetch('/checkout');return false;}catch{return true;}}),'Checkout remains network dependent');await context.setOffline(false);
 await page.evaluate(async()=>{const c=await caches.open('other-extension');await c.put('/unrelated',new Response('keep'));});
 await writeFile(new URL('../work/local-stage/pwa-epoch.txt',import.meta.url),'second');await page.evaluate(async()=>{const r=await navigator.serviceWorker.getRegistration();await r.update();});
 await page.waitForFunction(async()=>{const names=await caches.keys();return names.filter(n=>n.startsWith('skynova-assets-')).length===0;},{timeout:15000});
 check(await page.evaluate(async()=>await caches.has('other-extension')),'Other extension cache preserved during epoch change');
 await writeFile(new URL('../docs/validation/pwa-browser.json',import.meta.url),JSON.stringify({scope:'own isolated loopback worker fixture',count:checks.length,checks},null,2));console.log('PASS '+checks.length+' PWA browser assertions');
}finally{await browser.close();}
