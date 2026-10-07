<?php
// Regression: OpenCart's admin <base> must not send section links to an unauthenticated URL.
require dirname(__DIR__).'/src/core/bootstrap.php';
$checks=0;
foreach(array('token','user_token') as $token){
 $action='https://shop.example/admin/index.php?route=extension/module/furmedia_cache&amp;'.$token.'=fixture&amp;store_id=2';
 $data=array('settings'=>\FurMedia\Cache\Settings::normalize(array()),'action'=>$action,'nonce'=>'fixture','cards'=>array(),'message'=>'','error'=>'','health'=>array(),'pagespeed'=>'https://pagespeed.web.dev/','report'=>array('score'=>100,'strategy'=>'mobile','measured_at'=>'fixture','metrics'=>array(),'recommendations'=>array(array('section'=>'assets','title'=>'Fixture','action'=>'Check','audit'=>'fixture','savings_ms'=>0,'savings_bytes'=>0))));
 $html=\FurMedia\Cache\Admin::render($data);
 $dom=new DOMDocument();@$dom->loadHTML($html);
 $count=0;
 foreach($dom->getElementsByTagName('a') as $a){
  $href=$a->getAttribute('href');if(strpos($href,'#fm-')===false)continue;
  $url=parse_url($href);parse_str(isset($url['query'])?$url['query']:'',$query);
  if(!isset($url['host'])||$url['host']!=='shop.example'||$url['path']!=='/admin/index.php'||!isset($query[$token])||$query[$token]!=='fixture'||$query['store_id']!=='2')throw new Exception('Section link loses authenticated URL');
  if(!$dom->getElementById($url['fragment']))throw new Exception('Missing section target');
  $count++;$checks++;
 }
 if($count<20)throw new Exception('Navigation coverage missing');
}
echo 'Authenticated section links: '.$checks." checks passed\n";
