/** Optional offline worker. npm install playwright; npx playwright install chromium.
 * node tools/critical-css.mjs https://shop.example/product output.css
 * Set SKYNOVA_PLAYWRIGHT to an installed playwright-core entry for an existing runtime.
 * Does not change the shop or remove its stylesheets. Import generated CSS in admin after review.
 */
import {createRequire} from 'node:module';
import {writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.SKYNOVA_PLAYWRIGHT || 'playwright');
const [url,output]=process.argv.slice(2);
if(!url || !output) throw new Error('Usage: node critical-css.mjs URL output.css');
const target=new URL(url);
if(!['https:','http:'].includes(target.protocol)||target.username||target.password)throw new Error('HTTP(S) page required');
if(target.protocol!=='https:' && !['127.0.0.1','localhost'].includes(target.hostname))throw new Error('HTTPS required for remote pages');
const browser=await chromium.launch({headless:true,...(process.env.SKYNOVA_CHROME?{executablePath:process.env.SKYNOVA_CHROME}:{})});
try{
 const blocks=new Set(),warnings=new Set();
 for(const viewport of [{width:390,height:844},{width:1440,height:1000}]){
  const context=await browser.newContext({viewport});
  const page=await context.newPage();
  await page.goto(url,{waitUntil:'networkidle',timeout:45000});
  const result=await page.evaluate(()=>{
   const warnings=[],visible=Array.from(document.querySelectorAll('*')).slice(0,20000).filter(el=>{const r=el.getBoundingClientRect();return r.width&&r.height&&r.top<innerHeight&&r.bottom>0;});
   const rebase=(css,base)=>css.replace(/url\(\s*(["']?)([^)"']+)\1\s*\)/gi,(m,q,value)=>{if(value.startsWith('data:')||value.startsWith('#'))return m;try{return 'url("'+new URL(value,base).href.replace(/"/g,'%22')+'")';}catch{return m;}});
   let budget=20000;
   const rules=(list,base)=>Array.from(list).map(rule=>{
    if(--budget<0)return '';
    if(/^@(?:keyframes|-webkit-keyframes)/i.test(rule.cssText))return rebase(rule.cssText,base);
    if(rule.selectorText){let match=false;try{match=visible.some(el=>el.matches(rule.selectorText));}catch{match=true;}return match?rebase(rule.cssText,base):'';}
    if(rule.cssRules){const inner=rules(rule.cssRules,base);return inner?rule.cssText.slice(0,rule.cssText.indexOf('{')+1)+inner+'}':'';}
    if(/^@(?:font-face|keyframes|-webkit-keyframes|property)/i.test(rule.cssText))return rebase(rule.cssText,base);
    if(rule.href)warnings.push('Imported stylesheet requires separate verification');
    return '';
   }).join('\n');
   const css=Array.from(document.styleSheets).map(sheet=>{try{return rules(sheet.cssRules,sheet.href||location.href);}catch{warnings.push('Cross-origin stylesheet unavailable to CSSOM');return '';}}).join('\n');
   if(budget<0)warnings.push('Rule budget exceeded');return {css,warnings};
  });
  blocks.add(result.css);result.warnings.forEach(w=>warnings.add(w));await context.close();
 }
 const css=Array.from(blocks).join('\n');
 if(!css || css.length>262144 || /[<>]/.test(css))throw new Error('Generated CSS empty, unsafe, or exceeds admin 256KiB limit; narrow the template');
 await writeFile(resolve(output),css,'utf8');
 await writeFile(resolve(output)+'.json',JSON.stringify({viewports:['390x844','1440x1000'],bytes:Buffer.byteLength(css),warnings:Array.from(warnings),note:'Automatically extracted; verify visual rendering before applying. Original CSS must remain available.'},null,2));
 console.log('Critical CSS generated; see adjacent JSON for coverage warnings.');
}finally{await browser.close();}
