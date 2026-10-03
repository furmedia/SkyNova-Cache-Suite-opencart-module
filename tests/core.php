<?php
require __DIR__ . '/../src/core/bootstrap.php';
use FurMedia\Cache\Settings;
use FurMedia\Cache\Policy;
use FurMedia\Cache\FileStore;
use FurMedia\Cache\Assets;
use FurMedia\Cache\Optimizer;

$checks=0;
function check($condition,$message) { global $checks; $checks++; if (!$condition) { throw new Exception('FAIL: '.$message); } }
$tmp=__DIR__.'/runtime/core-'.bin2hex(FurMedia\Cache\Entropy::bytes(6)); mkdir($tmp,0700,true);
$now=1000;$s=Settings::normalize(array('status'=>1,'mode'=>'session'));
$store=new FileStore($tmp.'/private',$s,function()use(&$now){return $now;});
check($store->get('unknown')===null,'cold read');
check($store->set('home',array('body'=>'one'),10,array('product:1')),'write');
check($store->get('home')['body']==='one','hot read');
$now=1010;check($store->get('home')===null,'TTL expires exactly at boundary');
$now=1100;$store->set('a','A',30,array('product:1'));$store->set('b','B',30,array('product:2'));
$fence=$store->generation();$store->purge('product:1');
check($store->get('a')===null,'product purge');check($store->get('b')==='B','selective purge preserves unrelated page');
check(!$store->set('stale','stale',30,array(),$fence),'in-flight write cannot resurrect purged product');
$store->purge();check($store->get('b')===null,'global purge');
check(!$store->set('huge',str_repeat('x',2097152),60),'large entries refused');
$store->set('untrusted','safe',5);$p=$tmp.'/private/'.hash('sha256','untrusted').'.cache';file_put_contents($p,'<?php echo "danger";');
check($store->get('untrusted')===null,'corrupt cache never evaluated');
$store->count('hit');$store->count('hit');check($store->stats()['hit']===2,'stats count');
check($store->gc()>=1,'corrupt/expired cleanup');
check(!is_file($tmp.'/private/../home'),'keys cannot become paths');
$request=array('method'=>'GET','route'=>'product/product','uri'=>'/product?product_id=1','query'=>array('product_id'=>1),'authorization'=>false,'ajax'=>false,'range'=>false);
$context=array('session_id'=>'session-A','store'=>0,'language'=>1,'currency'=>'RON','customer'=>false,'cart'=>false,'maintenance'=>false,'affiliate'=>false);
$policy=new Policy();check($policy->reason($request,$context,$s)==='','guest eligible');
foreach (array('customer','cart','maintenance','affiliate') as $field) { $c=$context;$c[$field]=true;check($policy->reason($request,$c,$s)!=='','private '.$field); }
foreach (array('POST','HEAD','PUT','DELETE','OPTIONS') as $method) { $r=$request;$r['method']=$method;check($policy->reason($r,$context,$s)!=='','method '.$method); }
foreach (array('authorization','ajax','range') as $field) { $r=$request;$r[$field]=true;check($policy->reason($r,$context,$s)!=='','private request '.$field); }
foreach (array('token','user_token','api_token','coupon','tracking','fq','utm_source') as $param) { $r=$request;$r['query'][$param]='x';check($policy->reason($r,$context,$s)!=='','query exclusion '.$param); }
foreach (array('account/account','checkout/cart','api/order','journal3/filter','extension/payment/example') as $route) { $r=$request;$r['route']=$route;$over=$s;$over['routes']=$route;$over['exclude_paths']='';check($policy->reason($r,$context,$over)!=='','sensitive exclusion cannot be disabled '.$route); }
$key=$policy->key($request,$context);
foreach (array('session_id'=>'session-B','store'=>1,'currency'=>'EUR','language'=>2) as $field=>$value) { $c=$context;$c[$field]=$value;check($policy->key($request,$c)!==$key,'isolation '.$field); }
$html='<!doctype html><html><head></head><body><p>Shop</p></body></html>';
check($policy->responseAllowed($html,array('Content-Type: text/html; charset=utf-8'),200),'public HTML accepted');
foreach (array('Set-Cookie: auth=1','Location: /account','Cache-Control: no-store','Cache-Control: no-cache','Content-Type: application/json','Content-Disposition: attachment','Content-Encoding: gzip','Vary: *','HTTP/1.1 404 Not Found') as $header) { check(!$policy->responseAllowed($html,array($header),200),'header bypass '.$header); }
foreach (array(301,302,403,404,500) as $code) { check(!$policy->responseAllowed($html,array(),$code),'status '.$code); }
foreach (array('<script nonce="abcd"></script>','<input name="token" value="x">','<input name="csrf" value="x">','user_token=secret') as $private) { check(!$policy->responseAllowed(str_replace('</body>',$private.'</body>',$html),array(),200),'token bypass'); }
mkdir($tmp.'/shop/catalog/view',0755,true);mkdir($tmp.'/shop/image/cache',0755,true);
file_put_contents($tmp.'/shop/catalog/view/app.js','function add(a, b) { /* ordinary comment */ return a + b; }');
file_put_contents($tmp.'/shop/catalog/view/app.css','/* hi */ .hero { color: #ffffff; background: url(../../image/logo.png); }');
file_put_contents($tmp.'/shop/catalog/view/import.css','@import "app.css";');
file_put_contents($tmp.'/shop/config.php','private config');
$settings=Settings::normalize(array('css_minify'=>1,'js_minify'=>1,'html_minify'=>1,'lazy_images'=>1,'lazy_iframes'=>1,'defer_js'=>1,'defer_allow'=>'catalog/view/app.js','cdn'=>'https://cdn.example','critical_css'=>'.hero{color:red}','preconnect'=>'https://fonts.example'));
$assets=new Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$settings);
foreach (array('https://evil.example/x.js','file:///etc/passwd','//evil.example/app.js','catalog/../../config.php','catalog/../config.php','data:text/javascript,alert(1)','catalog/%00/a.js','https://user:pass@shop.example/catalog/view/app.js') as $url) { check($assets->local($url)===false,'unsafe asset path '.$url); }
check($assets->local('catalog/view/app.js')!==false,'local asset resolve');
$js=$assets->minify('catalog/view/app.js','js');check(strpos($js,'image/cache/furmedia_cache/')!==false,'JS written');
check($assets->minify('catalog/view/app.js','js')===$js,'JS cache reused');
$css=$assets->minify('catalog/view/app.css','css');check(strpos($css,'image/cache/furmedia_cache/')!==false,'CSS written');
check($assets->minify('catalog/view/import.css','css')==='catalog/view/import.css','CSS imports not flattened or cached incorrectly');
check($assets->cdn('catalog/view/app.js?v=9')==='https://cdn.example/catalog/view/app.js?v=9','CDN preserves query version');
check($assets->cdn('https://other.example/app.js')==='https://other.example/app.js','external URLs preserved');
$input='<!doctype html><html><head><script>const text = "<img src=\'x\'>"; // KEEP</script><script src="catalog/view/app.js"></script><script src="catalog/view/jquery.js"></script><link rel="stylesheet" href="catalog/view/app.css"></head><body><!-- remove me --><!--[if IE]>keep<![endif]--><pre> A  B </pre><img src="image/1.jpg"><img src="image/2.jpg"><img src="image/3.jpg"><img src="image/4.jpg" fetchpriority="high"><img data-src="image/journal.jpg" src="image/placeholder.jpg"><iframe src="https://video.example"></iframe></body></html>';
$out=(new Optimizer($settings,$assets))->transform($input);
check(strpos($out,'const text = "<img src=\'x\'>"; // KEEP')!==false,'inline JS preserved byte-for-byte');
check(strpos($out,'<pre> A  B </pre>')!==false,'preformatted whitespace preserved');
check(strpos($out,'remove me')===false,'ordinary HTML comment removed');
check(strpos($out,'[if IE]')!==false,'conditional comment retained');
check(substr_count($out,'loading="lazy"')===2,'only third ordinary image and iframe lazy');
check(strpos($out,'data-src="image/journal.jpg" src="image/placeholder.jpg"')!==false,'Journal image untouched');
check(strpos($out,' defer>')!==false,'approved script deferred');
check(strpos($out,'src="catalog/view/jquery.js"></script>')!==false,'jquery preserved');
check(strpos($out,'id="furmedia-critical"')!==false,'critical CSS injected');
check(strpos($out,'rel="preconnect"')!==false,'preconnect injected');
$blocked=false;try { Settings::normalize(array('critical_css'=>'</style><script>bad</script>')); } catch(Exception $e) {$blocked=true;}check($blocked,'critical CSS cannot break out');
$blocked=false;try { Settings::normalize(array('cdn'=>'https://ok.example/" onload="x')); } catch(Exception $e) {$blocked=true;}check($blocked,'CDN injection refused');
check(\FurMedia\Cache\Paths::privateStorage($tmp.'/private',$tmp.'/shop'),'private storage accepted');
check(!\FurMedia\Cache\Paths::privateStorage($tmp.'/shop/image/cache',$tmp.'/shop'),'public cache storage refused');
$small=$s;$small['max_entries']=3;$bounded=new FileStore($tmp.'/bounded',$small);
for($i=0;$i<8;$i++){$bounded->set('key'.$i,str_repeat('x',1024),60);}
check($bounded->stats()['entries']<=3,'entry count bounded under sustained writes');
check($bounded->get('key7')!==null,'newest entry retained under quota eviction');
$shape=$tmp.'/bounded/'.hash('sha256','key7').'.cache';file_put_contents($shape,'{"data":"incomplete"}');
check($bounded->get('key7')===null,'valid JSON with invalid entry shape rejected');
check($bounded->gc()===1,'invalid entry shape cleaned');
foreach(array('http://127.0.0.1/','https://127.0.0.1/','https://localhost/','file:///tmp/test','https://user:pass@example.com/','https://example.com:8443/')as$url){
    $blocked=false;try{(new \FurMedia\Cache\HttpClient())->get($url);}catch(Exception $e){$blocked=true;}
    check($blocked,'HTTP fetch refuses unsafe destination '.$url);
}
$reportSettings=Settings::normalize(array('max_mb'=>999999,'ttl'=>0,'quality'=>999));
check($reportSettings['max_mb']===4096 && $reportSettings['ttl']===10 && $reportSettings['quality']===100,'resource bounds enforced');
$runner=new \FurMedia\Cache\Runner();
check(isset($runner->run(array('cache_directory'=>$tmp.'/private'),'gc')['expired_removed']),'runner GC executes');
$blocked=false;try{$runner->run(array('cache_directory'=>$tmp.'/private'),'delete-all');}catch(Exception $e){$blocked=true;}
check($blocked,'runner action allowlist');
if (function_exists('imagewebp')) {
    $image=imagecreatetruecolor(500,400);$color=imagecolorallocate($image,20,100,160);imagefill($image,0,0,$color);imagepng($image,$tmp.'/shop/image/test.png');imagedestroy($image);
    $s2=$settings;$s2['webp']=1;$a2=new Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$s2);
    $webp=$a2->image('image/test.png','image/webp');check(substr($webp,-5)==='.webp','WebP conversion');check(is_file($tmp.'/shop/image/test.png'),'original preserved');
    check($a2->image('image/test.png','image/jpeg')==='image/test.png','format negotiation fallback');
}
require __DIR__.'/premium-cases.php';
require __DIR__.'/data-cases.php';
require __DIR__.'/completion-cases.php';
require __DIR__.'/webkul-cases.php';
require __DIR__.'/advanced-cases.php';
require __DIR__.'/suite-cases.php';
echo 'PASS '.$checks.' assertions on PHP '.PHP_VERSION."\n";
