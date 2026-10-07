<?php
if(PHP_SAPI!=='cli'){exit(1);}
$directory=__DIR__.'/shared-'.getmypid();mkdir($directory,0700);$rows=array();$tokens=array();$checks=array();
function spRequest($who,$path='index.php?route=common/home'){
 global $directory,$rows;$headers=array();$ch=curl_init('https://trinityconcept.ro/'.$path);
 curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Mozilla/5.0 (Linux; Android 14; '.($who==='a'?'Pixel 8':'Samsung S24').') AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36',CURLOPT_COOKIEFILE=>$directory.'/'.$who.'.cookies',CURLOPT_COOKIEJAR=>$directory.'/'.$who.'.cookies',CURLOPT_HEADERFUNCTION=>function($c,$h)use(&$headers){$p=explode(':',$h,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($h);}));
 $body=curl_exec($ch);$rows[]=array('visitor'=>$who,'path'=>$path,'http'=>curl_getinfo($ch,CURLINFO_HTTP_CODE),'cache'=>isset($headers['x-furmedia-cache'])?$headers['x-furmedia-cache']:'none','seconds'=>curl_getinfo($ch,CURLINFO_TOTAL_TIME),'bytes'=>strlen((string)$body));curl_close($ch);return (string)$body;
}
foreach(array('a','a','b','b') as $who){$body=spRequest($who);if(preg_match('/data-bis-csrf="([a-f0-9]{48})"/i',$body,$m)){$tokens[$who]=$m[1];}$checks[]=array('name'=>'complete HTML '.$who,'passed'=>strpos($body,'</html>')!==false && strpos($body,'Fatal error')===false);}
$checks[]=array('name'=>'different phone gets shared HIT','passed'=>$rows[2]['cache']==='HIT');
$checks[]=array('name'=>'fresh footer tokens differ','passed'=>isset($tokens['a'],$tokens['b']) && $tokens['a']!==$tokens['b']);
foreach(array('a','b') as $who){$data=json_decode(spRequest($who,'index.php?route=extension/module/back_in_stock/sessionToken'),true);$checks[]=array('name'=>'footer token belongs to current session '.$who,'passed'=>isset($tokens[$who],$data['csrf']) && hash_equals($tokens[$who],$data['csrf']));}
foreach(array('a','a','b') as $who){$body=spRequest($who,'index.php?route=product/category&path=64');$checks[]=array('name'=>'category complete '.$who,'passed'=>strpos($body,'</html>')!==false && strpos($body,'class="afs-panel')!==false && strpos($body,'module-filter-36')===false && strpos($body,'data-skynova-fragment')===false);}
$checks[]=array('name'=>'different phone gets category HIT','passed'=>end($rows)['cache']==='HIT');
$safe=true;$shells=0;foreach(glob(dirname(__DIR__).'/cache/furmedia_cache/*.cache') as $file){$entry=json_decode(file_get_contents($file),true);if(isset($entry['data']['shared'])){$shells++;$serialized=json_encode($entry['data']);foreach($tokens as $token){if(strpos($serialized,$token)!==false){$safe=false;}}if(strpos($serialized,'data-bis-csrf')!==false){$safe=false;}}}
$checks[]=array('name'=>'shared entries contain no visitor footer tokens','passed'=>$shells>0 && $safe);
$report=array('at'=>gmdate('c'),'requests'=>$rows,'checks'=>$checks);file_put_contents(__DIR__.'/shared-live-report.json',json_encode($report,JSON_PRETTY_PRINT));foreach(glob($directory.'/*.cookies') as $f){unlink($f);}rmdir($directory);echo json_encode($report,JSON_PRETTY_PRINT);
