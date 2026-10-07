<?php
use FurMedia\Cache\FileStore;
class MemoryFixture {
    public $data=array();public $fail=false;
    public function get($key){if($this->fail){throw new Exception('offline');}return isset($this->data[$key])?$this->data[$key]:false;}
    public function setex($key,$ttl,$data){$this->data[$key]=$data;return true;}
    public function set($key,$data,$ttl){return $this->setex($key,$ttl,$data);}
}
foreach(array('redis','memcached') as $backend){
    $memory=new MemoryFixture();$cs=new FurMedia\Cache\CacheStore($tmp.'/'.$backend,array('backend'=>$backend),$memory);
    check($cs->set('a','first',300,array('product:7')),'memory write '.$backend);
    check($cs->get('a')==='first','memory hit '.$backend);
    $disk=new FileStore($tmp.'/'.$backend);$generation=$disk->generation();$disk->purge('product:7');
    check($cs->get('a')===null,'native disk purge fences memory '.$backend);
    check(!$cs->set('a','stale',300,array(),$generation),'stale writer fenced '.$backend);
    $cs->set('b','fallback',300);$memory->fail=true;
    check($cs->get('b')==='fallback','backend outage falls back '.$backend);
}
$one=new FileStore($tmp.'/leases');$two=new FileStore($tmp.'/leases');$lease=$one->lease('same');
check(is_resource($lease) && $two->lease('same')===false,'concurrent cache population excluded');
flock($lease,LOCK_UN);fclose($lease);$lease=$two->lease('same');check(is_resource($lease),'lease released');flock($lease,LOCK_UN);fclose($lease);
$one->count('hit');check(isset($one->stats()['daily'][gmdate('Y-m-d')]['hit']),'daily counters stored');
$history=new FurMedia\Cache\History($tmp.'/history');for($i=0;$i<35;$i++){$history->append('pagespeed',array('score'=>$i));}
$quotaCalls=0;$quotaClient=new FurMedia\Cache\PageSpeed(function($url)use(&$quotaCalls){$quotaCalls++;throw new Exception('Fetch failed (HTTP 429)');},$tmp.'/pagespeed-quota');
for($i=0;$i<2;$i++){$quotaRejected=false;try{$quotaClient->analyze('https://shop.example/');}catch(Exception $e){$quotaRejected=strpos($e->getMessage(),'HTTP 429')!==false;}check($quotaRejected,'PageSpeed quota surfaced clearly');}
check($quotaCalls===1,'PageSpeed quota cooldown prevents repeated Google calls');
check(count($history->rows('pagespeed'))===30,'bounded history');check($history->rows('pagespeed')[0]['report']['score']===5,'old reports evicted');
$time=1000;$calls=array();$failOnce=true;
$fetch=function($url)use(&$calls,&$failOnce){$calls[]=$url;if(strpos($url,'sitemap')!==false){return array('body'=>'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://shop.example/item</loc></url><url><loc>https://other.example/private</loc></url></urlset>');}if($failOnce){$failOnce=false;throw new Exception('retry');}return array('body'=>'ok');};
$queue=new FurMedia\Cache\WarmQueue($tmp.'/queue','https://shop.example',$fetch,function()use(&$time){return $time;});
check($queue->url('//evil.example')===false && $queue->url('/%2561ccount/login')===false,'queue sensitive URLs rejected');
check($queue->url('https://shop.example@evil.example/item')===false,'queue userinfo rejected');
$config=array('paths'=>array(),'sitemaps'=>array('/sitemap.xml'),'limit'=>10,'interval'=>3600);
$r=$queue->run($config);check($r['total']===2 && $r['fetched']===1,'sitemap discovers same-origin page');
$r=$queue->run($config);check($r['failed']===1,'transient failure tracked');
$r=$queue->run($config);check($r['fetched']===0 && $r['failed']===0,'retry backoff respected');
$forcedRetry=$config;$forcedRetry['force']=true;$r=$queue->run($forcedRetry);check($r['fetched']===0 && $r['failed']===0,'manual refresh does not hammer failed jobs or sitemaps');
$time+=31;$queue=new FurMedia\Cache\WarmQueue($tmp.'/queue','https://shop.example',$fetch,function()use(&$time){return $time;});
$r=$queue->run($config);check($r['fetched']===1 && $r['remaining']===0,'queue resumes across instances');
$r=$queue->run($config);check($r['fetched']===0,'scheduled warming keeps interval after success');
$manual=$config;$manual['force']=true;$manual['limit']=1;
$r=$queue->run($manual);check($r['fetched']===1 && $r['failed']===0,'manual warming refreshes completed page before interval');
$queue->pause(true);$r=$queue->run($manual);check($r['paused'] && $r['fetched']===0,'manual warming respects pause');$queue->pause(false);
$bad=false;try{$queue->sitemap('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///secret">]><urlset/>');}catch(Exception $e){$bad=true;}check($bad,'sitemap external entities refused');
putenv('SKYNOVA_CF_ZONE=0123456789abcdef0123456789abcdef');putenv('SKYNOVA_CF_TOKEN=fixture-not-a-real-token');
$api=new FurMedia\Cache\Cloudflare(function($method,$url,$body,$headers){check($method==='POST' && strpos($url,'https://api.cloudflare.com/client/v4/zones/')===0,'fixed Cloudflare endpoint');check(json_decode($body,true)['files']===array('https://shop.example/item'),'Cloudflare exact URL payload');return array('body'=>'{"success":true}');});
check($api->purge('https://shop.example/',array('https://shop.example/item'))['success'],'Cloudflare response validation');
$bad=false;try{$api->purge('https://shop.example/',array('https://other.example/item'));}catch(Exception $e){$bad=true;}check($bad,'Cloudflare store boundary');
putenv('SKYNOVA_CF_ZONE');putenv('SKYNOVA_CF_TOKEN');
class DocumentFixture {
    public function getTitle(){return 'Product';}public function getDescription(){return 'Description';}public function getKeywords(){return '';}
    public function getLinks(){return array();}public function getStyles(){return array();}public function getScripts($p){return array();}
}
$header='<html><head></head><body><header>PERSONAL-A</header>';$footer='<footer>PERSONAL-A</footer></body></html>';
$entry=FurMedia\Cache\SharedPage::pack($header.'<main>Public product</main>'.$footer,array('common/header'=>$header,'common/footer'=>$footer),new DocumentFixture());
check(is_array($entry) && strpos($entry['shell'],'PERSONAL-A')===false,'shared shell excludes dynamic personal fragments');
check(FurMedia\Cache\SharedPage::pack($header.'<input name="token">'.$footer,array('common/header'=>$header,'common/footer'=>$footer),new DocumentFixture())===null,'shared shell with token refused');
check(FurMedia\Cache\SharedPage::pack($header.'public'.$footer,array(),new DocumentFixture())===null,'missing fragments refuse sharing');
$h="<html>\n<body><header>Fresh</header>";$f="<footer data-bis-csrf=\"".str_repeat('b',48)."\">Fresh</footer>\n</body></html>";$nativeMinifier=function($html){return str_replace("\n",'',$html);};$minified=$nativeMinifier($h.'<main>Public</main>'.$f);$why='';
$packed=FurMedia\Cache\SharedPage::pack($minified,array('common/header'=>$h,'common/footer'=>$f),new DocumentFixture(),$why,$nativeMinifier);
check(is_array($packed) && strpos($packed['shell'],'data-bis-csrf')===false && strpos($packed['shell'],str_repeat('b',48))===false,'native minification still excludes footer tokens');
check(FurMedia\Cache\SharedPage::pack($nativeMinifier($h.'<input name="csrf" value="private">'.$f),array('common/header'=>$h,'common/footer'=>$f),new DocumentFixture(),$why,$nativeMinifier)===null,'minified shell with unknown token refused');
check(FurMedia\Cache\SharedPage::pack($minified.'<header>Fresh</header>',array('common/header'=>'<header>Fresh</header>','common/footer'=>$f),new DocumentFixture(),$why,$nativeMinifier)===null,'ambiguous fragment matches refuse sharing');
$marked=FurMedia\Cache\SharedPage::marker('header-start').$h.FurMedia\Cache\SharedPage::marker('header-end').'<main>Public</main>'.FurMedia\Cache\SharedPage::marker('footer-start').$f.FurMedia\Cache\SharedPage::marker('footer-end');
$packed=FurMedia\Cache\SharedPage::pack($marked,array('_marked'=>true,'common/header'=>'changed','common/footer'=>'changed'),new DocumentFixture(),$why);
check(is_array($packed) && strpos($packed['shell'],'data-bis-csrf')===false && strpos($packed['shell'],'data-skynova-fragment')===false,'final template boundaries exclude transformed fragments');
check(FurMedia\Cache\SharedPage::stripMarkers($marked)===$h.'<main>Public</main>'.$f,'native response markers removed');
check(FurMedia\Cache\SharedPage::pack($marked.FurMedia\Cache\SharedPage::marker('header-start'),array('_marked'=>true),new DocumentFixture(),$why)===null,'duplicate template boundaries refused');
$delays=FurMedia\Cache\Settings::normalize(array('delay_js'=>1,'delay_allow'=>'catalog/view/app.js'));
$a=new FurMedia\Cache\Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$delays);
$html='<html><head></head><body><script src="catalog/view/app.js"></script><script type=\'module\' src="catalog/view/app.js"></script></body></html>';
$out=(new FurMedia\Cache\Optimizer($delays,$a))->transform($html);
check(substr_count($out,'type="application/x-skynova-delayed"')===1,'only classic approved script delayed');
check(strpos($out,'s.addEventListener("load",next')!==false,'delayed scripts retain serial order');
check(strpos($out,"type='module' src=\"catalog/view/app.js\"")!==false,'single quoted module left untouched');
if(function_exists('imagewebp')){
    $s2['responsive_images']=1;$a=new FurMedia\Cache\Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$s2);
    $variants=$a->variants('image/test.png','image/webp');check(strpos($variants,'320w')!==false && strpos($variants,'500w')!==false,'responsive width descriptors');
    $url=$a->image('image/test.png','image/webp',320);$image=imagecreatefromwebp($a->local($url));check(imagesx($image)===320 && imagesy($image)===256,'responsive image keeps aspect ratio');imagedestroy($image);
}
$h=FurMedia\Cache\Sthree::headers('bucket.s3.eu-west-1.amazonaws.com','/image/cache/a.css','body','text/css','eu-west-1','EXAMPLE','fixture-secret','20260101T000000Z');
check(strpos(implode("\n",$h),'Credential=EXAMPLE/20260101/eu-west-1/s3/aws4_request')!==false,'S3 signature scope');
check(strpos(implode("\n",$h),'x-amz-content-sha256: '.hash('sha256','body'))!==false,'S3 signature binds payload');
check($h!==FurMedia\Cache\Sthree::headers('bucket.s3.eu-west-1.amazonaws.com','/image/cache/a.css','changed','text/css','eu-west-1','EXAMPLE','fixture-secret','20260101T000000Z'),'S3 payload change changes signature');
putenv('SKYNOVA_S3_BUCKET=fixture-bucket');putenv('SKYNOVA_S3_REGION=eu-west-1');putenv('SKYNOVA_S3_ACCESS_KEY=EXAMPLE');putenv('SKYNOVA_S3_SECRET_KEY=fixture-secret');
$uploads=array();$transport=function($method,$url,$body,$headers)use(&$uploads){check($method==='PUT' && strpos($url,'https://fixture-bucket.s3.eu-west-1.amazonaws.com/image/cache/furmedia_cache/')===0,'S3 destination constrained');$uploads[]=$url;return array('body'=>'');};
$s3=new FurMedia\Cache\Sthree();$first=$s3->upload($tmp.'/shop/image/cache/furmedia_cache',1,$transport,$tmp.'/s3-state');$second=$s3->upload($tmp.'/shop/image/cache/furmedia_cache',1,$transport,$tmp.'/s3-state');
check($first['uploaded']===1 && $second['uploaded']===1 && $uploads[0]!==$uploads[1],'S3 batch resumes without repeating completed assets');
foreach(array('BUCKET','REGION','ACCESS_KEY','SECRET_KEY') as $key){putenv('SKYNOVA_S3_'.$key);}
