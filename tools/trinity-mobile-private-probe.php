<?php
// CLI-only acceptance; isolated synthetic guests, no account, order or payment.
if(PHP_SAPI!=='cli'){exit(1);}
$directory=__DIR__.'/mobile-private-'.getmypid();mkdir($directory,0700);$rows=array();$checks=array();
function mpRequest($who,$path='index.php?route=common/home',$post=null){
    global $directory,$rows;$headers=array();$ch=curl_init('https://trinityconcept.ro/'.$path);
    curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36',CURLOPT_COOKIEFILE=>$directory.'/'.$who.'.cookies',CURLOPT_COOKIEJAR=>$directory.'/'.$who.'.cookies',CURLOPT_COOKIE=>'PHPSESSID=skynova-mobile-test-'.$who.'; jrv=6624',CURLOPT_HEADERFUNCTION=>function($c,$h)use(&$headers){$p=explode(':',$h,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($h);}));
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $body=(string)curl_exec($ch);$row=array('visitor'=>$who,'path'=>$path,'method'=>$post===null?'GET':'POST','http'=>curl_getinfo($ch,CURLINFO_HTTP_CODE),'cache'=>isset($headers['x-furmedia-cache'])?$headers['x-furmedia-cache']:'none','scope'=>isset($headers['x-skynova-cache-scope'])?$headers['x-skynova-cache-scope']:'none','native_timing'=>isset($headers['server-timing'])?$headers['server-timing']:'','seconds'=>curl_getinfo($ch,CURLINFO_TOTAL_TIME),'wire_bytes'=>curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD),'html_bytes'=>strlen($body));$rows[]=$row;curl_close($ch);unset($ch);return array('body'=>$body,'row'=>$row);
}
function mpCheck($name,$ok){global $checks;$checks[]=array('name'=>$name,'passed'=>(bool)$ok);}
$tokens=array();
foreach(array('a','b') as $who){$seen=array();for($i=0;$i<5;$i++){$r=mpRequest($who);$seen[]=$r['row']['cache'];mpCheck('complete home '.$who.' '.$i,$r['row']['http']===200&&strpos($r['body'],'</html>')!==false&&strpos($r['body'],'data-skynova-fragment')===false);if(preg_match('/data-bis-csrf="([a-f0-9]{48})"/i',$r['body'],$m)){$tokens[$who]=$m[1];}if($who==='b'&&$i===0){mpCheck('private visitor b cannot reuse visitor a entry',$r['row']['cache']!=='HIT');}}
    mpCheck('repeat home gets private HIT '.$who,in_array('HIT',$seen,true)&&$r['row']['scope']==='session-fragments');
}
mpCheck('footer tokens remain visitor-specific',isset($tokens['a'],$tokens['b'])&&$tokens['a']!==$tokens['b']);
foreach(array('a','b') as $who){$r=mpRequest($who,'index.php?route=extension/module/back_in_stock/sessionToken');$token=json_decode($r['body'],true);mpCheck('own footer token '.$who,isset($token['csrf'],$tokens[$who])&&hash_equals($token['csrf'],$tokens[$who]));}
foreach(array('index.php?route=product/category&path=64','index.php?route=product/product&product_id=6624') as $path){$seen=array();for($i=0;$i<5;$i++){$r=mpRequest('a',$path);$seen[]=$r['row']['cache'];mpCheck('catalog complete '.$path.' '.$i,$r['row']['http']===200&&strpos($r['body'],'</html>')!==false&&strpos($r['body'],'Fatal error')===false&&strpos($r['body'],'data-skynova-fragment')===false);}
    mpCheck('repeat catalog private HIT '.$path,in_array('HIT',$seen,true));
}
$add=mpRequest('a','index.php?route=checkout/cart/add',array('product_id'=>6624,'quantity'=>1));$json=json_decode($add['body'],true);$added=!empty($json['success']);mpCheck('own test cart add',$added);
if($added){$seen=array();for($i=0;$i<4;$i++){$r=mpRequest('a');$seen[]=$r['row']['cache'];}mpCheck('occupied cart home gets isolated private HIT',in_array('HIT',$seen,true)&&$r['row']['scope']==='session-fragments');
    $cart=mpRequest('a','index.php?route=checkout/cart');mpCheck('cart page remains native',$cart['row']['cache']!=='HIT');$other=mpRequest('b','index.php?route=checkout/cart');mpCheck('other private visitor has no test cart',strpos($other['body'],'cart.remove(')===false);
    $checkout=mpRequest('a','index.php?route=checkout/checkout');mpCheck('checkout remains native without order',$checkout['row']['cache']!=='HIT'&&$checkout['row']['http']===200);
    if(preg_match('/cart\.remove\(\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/',$cart['body'],$m)){mpRequest('a','index.php?route=checkout/cart/remove',array('key'=>$m[1]));$empty=mpRequest('a','index.php?route=checkout/cart');mpCheck('own test cart removed',strpos($empty['body'],'cart.remove(')===false);}else{mpCheck('own removal key found',false);}
}
$report=array('at'=>gmdate('c'),'scope'=>'synthetic Android guests with PHPSESSID and jrv cookies; own test cart only','requests'=>$rows,'checks'=>$checks);file_put_contents(__DIR__.'/trinity-mobile-private-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));chmod(__DIR__.'/trinity-mobile-private-report.json',0600);foreach(glob($directory.'/*.cookies') as $f){unlink($f);}rmdir($directory);echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
