<?php
use FurMedia\Cache\Settings;
use FurMedia\Cache\Assets;
use FurMedia\Cache\Advanced;
use FurMedia\Cache\Conditions;
use FurMedia\Cache\Dbtools;
use FurMedia\Cache\Sqlprofile;
$as=Settings::normalize(array('conditions'=>'[{"route":"product/category","get":{"page":2},"ttl":45}]'));
check(Conditions::match($as['conditions'],'product/category',array('page'=>2),array(),array())['ttl']===45,'conditional GET TTL matches');
check(Conditions::match($as['conditions'],'product/category',array('page'=>3),array(),array())===null,'conditional GET mismatch');
foreach(array('[{"route":"checkout/cart","session":{"token":"secret"}}]','[{"route":"product/product","ttl":1}]') as $bad){$caught=false;try{Conditions::parse($bad);}catch(Exception $x){$caught=true;}check($caught,'unsafe conditional configuration rejected');}
$shape=Sqlprofile::shape("SELECT * FROM oc_product_description WHERE name='personal@example.com' AND product_id=123");
check(strpos($shape,'personal')===false&&strpos($shape,'123')===false,'SQL profile removes literals');
check(Sqlprofile::shape("SELECT * FROM oc_product WHERE name='unterminated private")==='[unparsed SELECT]','malformed SQL never leaks raw values');
check(Dbtools::safeSelect('SELECT product_id FROM oc_product WHERE status=1 ORDER BY sort_order LIMIT 10','oc_'),'catalog explain approved');
check(Dbtools::safeSelect('SELECT p.product_id FROM oc_product p JOIN oc_product_description pd ON pd.product_id=p.product_id WHERE pd.language_id=1','oc_'),'approved catalog join explain');
foreach(array('SELECT * FROM oc_product STRAIGHT_JOIN oc_customer ON 1=1','SELECT * FROM oc_product, oc_customer','SELECT * FROM oc_product; DELETE FROM oc_product','SELECT * FROM oc_customer','SELECT SLEEP(1) FROM oc_product','SELECT * FROM oc_product FOR UPDATE','SELECT * FROM oc_product UNION SELECT * FROM oc_customer','SELECT * FROM oc_product.secret','SELECT * FROM oc_product JOIN oc_customer ON 1=1') as $bad){check(!Dbtools::safeSelect($bad,'oc_'),'unsafe EXPLAIN refused');}
$as=Settings::normalize(array('lazy_media'=>1,'lazy_blocks'=>'later','custom_css'=>'body{color:red}','custom_js'=>'window.test=1;','resource_attributes'=>'{"catalog/test.css":{"media":"print"},"catalog/test.js":{"async":"false"}}'));
$html='<html><head><link href="catalog/test.css"></head><body><video preload="auto" src="clip.mp4"></video><audio autoplay src="sound.mp3"></audio><section id="later">Content</section><script src="catalog/test.js"></script></body></html>';
$out=Advanced::transform($html,$as);
check(strpos($out,'media="print"')!==false,'approved CSS attribute inserted');
check(strpos($out,'async=')===false,'false boolean attribute stays absent');
check(strpos($out,'preload="none"')!==false&&strpos($out,'audio autoplay')!==false,'media preload narrowed without changing autoplay');
check(strpos($out,'content-visibility:auto')!==false&&strpos($out,'>Content<')!==false,'lazy block retains DOM content');
check(strpos($out,'<style>body{color:red}</style>')!==false&&strpos($out,'window.test=1;')!==false,'custom CSS and JS inserted');
$as=Settings::normalize(array('delay_rules'=>'{"catalog/independent.js":{"event":"scroll","ms":5000}}'));
file_put_contents($tmp.'/shop/catalog/independent.js','window.independent=1;');
$aa=new Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$as);
$tag='<script src="catalog/independent.js"></script>';
check(Advanced::transform($tag,$as,$aa)===$tag,'delay preserves script without insertion boundary');
check(strpos(Advanced::transform('<body>'.$tag.'</body>',$as,$aa),'jobs=')!==false,'approved local delay injects scheduler');
file_put_contents($tmp.'/shop/catalog/independent.js','document.write("unsafe")');
check(strpos(Advanced::transform('<body>'.$tag.'</body>',$as,$aa),'src="catalog/independent.js"')!==false,'document.write resource never delayed');
$as=Settings::normalize(array('replacements'=>'[{"find":"OLD","replace":"NEW","type":"html","route":"common/home"}]'));
check(Advanced::transform('<body>OLD</body>',$as,null,'common/home')==='<body>NEW</body>','replacement route matches');
check(Advanced::transform('<body>OLD</body>',$as,null,'product/product')==='<body>OLD</body>','replacement route isolated');
$caught=false;try{Settings::normalize(array('replacements'=>'[{"find":"x","replace":"</script>","type":"js"}]'));}catch(Exception $x){$caught=true;}check($caught,'JS replacement cannot escape script tag');
$body='window.imported=1;';$import=FurMedia\Cache\ExternalAssets::import('https://cdn.example/test.js',hash('sha256',$body),'js',$tmp.'/import',function()use($body){return array('body'=>$body);});
check(file_get_contents($tmp.'/import/'.$import['file'])===$body,'hash-pinned external import writes original bytes');
$caught=false;try{FurMedia\Cache\ExternalAssets::import('https://cdn.example/test.js',str_repeat('0',64),'js',$tmp.'/import',function()use($body){return array('body'=>$body);});}catch(Exception $x){$caught=true;}check($caught,'external import refuses changed bytes');
foreach(array('apcu','memcache') as $backend){$ms=new FurMedia\Cache\CacheStore($tmp.'/memory-'.$backend,Settings::normalize(array('backend'=>$backend)));$ms->set('a','ok',60);check($ms->get('a')==='ok','optional memory driver disk fallback');}
$schema=FurMedia\Cache\Admin::schema();check(isset($schema['advanced']['fields']['conditions'],$schema['database']['fields']['sql_profile']),'new settings reachable in admin schema');

$as=Settings::normalize(array('replacements'=>'[{"find":"OLD","replace":"NEW","type":"html"}]','lazy_media'=>1,'lazy_blocks'=>'later'));
$protected='<script>var x="<video src=\"x\"></video><div id=\"later\">OLD</div>";</script><pre>OLD</pre>';
check(Advanced::transform($protected,$as)===$protected,'HTML and multimedia rules preserve script/pre contents');
$as=Settings::normalize(array('replacements'=>'[{"find":"#123456","replace":"#abcdef","type":"css","url":"catalog/replacement.css"}]'));
file_put_contents($tmp.'/shop/catalog/replacement.css','body{color:#123456;background:url(test.png)}');
$aa=new Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$as);
$out=Advanced::transform('<head><link href="catalog/replacement.css"></head>',$as,$aa);
preg_match('~href="([^"]+)"~',$out,$match);$file=$aa->local($match[1]);
check($file&&strpos(file_get_contents($file),'#abcdef')!==false,'resource replacement writes generated CSS');
check(strpos(file_get_contents($tmp.'/shop/catalog/replacement.css'),'#123456')!==false,'resource replacement preserves original CSS');
check(Sqlprofile::shape('SELECT * FROM oc_product WHERE product_id=0x706572736f6e616c')==='SELECT * FROM oc_product WHERE product_id=?','SQL profile hides hex literals');
