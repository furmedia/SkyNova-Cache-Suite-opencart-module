<?php
/** Native controller/event boundary with a controlled LiteSpeed capability fixture, not a real cache server. */
class NativeEsiLoader {
    public $args;
    public function controller($route,...$args){$this->args=$args;return '<div>native ESI fixture</div>';}
}
$oldProfile=getenv('SKYNOVA_LSCACHE_PROFILE');$oldEsi=getenv('SKYNOVA_LSCACHE_ESI');
putenv('SKYNOVA_LSCACHE_PROFILE=private-v1');putenv('SKYNOVA_LSCACHE_ESI=1');
try{
    $esiRoute=$v4?'extension/furmedia_cache/module/skynova_fixture':'extension/module/skynova_fixture';
    $r=setup('native-esi-session',array('litespeed'=>1,'litespeed_esi'=>1,'esi_modules'=>json_encode(array($esiRoute=>array('scope'=>'private','ttl'=>25)))));
    $r->get('request')->server['LSCACHE_ON']='1';$nativeLoader=new NativeEsiLoader();$r->set('load',$nativeLoader);
    $bridge=new \FurMedia\Cache\Bridge($r);$r->set('furmedia_cache_bridge',$bridge);
    $reflection=new ReflectionObject($bridge);$contextMethod=$reflection->getMethod('context');$contextMethod->setAccessible(true);$context=$contextMethod->invoke($bridge);
    $generation=(new \FurMedia\Cache\FileStore(DIR_CACHE.'furmedia_cache'))->generation();
    $r->get('request')->cookie['skynova_state']=hash('sha256',serialize(array($context,$generation)));
    $bridge->before('common/home');$c=new $moduleClass($r);$output='<div>original native module</div>';
    $nativeArgs=$v4?array(array('module_id'=>12,'limit'=>5)):array('module_id'=>12,'limit'=>5);
    $c->after($esiRoute,$nativeArgs,$output);
    check(strpos($output,'<esi:include')===0,'native after event inserts approved ESI fragment');
    preg_match('/&amp;id=([a-f0-9]+)&amp;signature=([a-f0-9]+)/',$output,$match);
    $fragment=$bridge->esiResponse($match[1],$match[2]);
    check($fragment==='<div>native ESI fixture</div>','native ESI endpoint renders approved controller');
    check($nativeLoader->args===array(array('module_id'=>12,'limit'=>5)),'legacy and variadic ESI invocation arguments preserved');
    $responseReflection=new ReflectionObject($r->get('response'));$headersProperty=$responseReflection->getProperty('headers');$headersProperty->setAccessible(true);$headers=$headersProperty->getValue($r->get('response'));
    check(in_array('X-LiteSpeed-Cache-Control: private,max-age=25',$headers,true),'native ESI endpoint applies fragment TTL and private scope');
    $r->get('customer')->logged=true;$failed=false;try{$bridge->esiResponse($match[1],$match[2]);}catch(Exception $e){$failed=true;}
    check($failed,'native ESI endpoint refuses changed customer state');
}finally{putenv($oldProfile===false?'SKYNOVA_LSCACHE_PROFILE':'SKYNOVA_LSCACHE_PROFILE='.$oldProfile);putenv($oldEsi===false?'SKYNOVA_LSCACHE_ESI':'SKYNOVA_LSCACHE_ESI='.$oldEsi);}
$r=setup('native-conditional',array('conditions'=>'[{"route":"common/home","get":{"page":2},"ttl":45}]','page_rules'=>'{"product/category":{"enabled":false}}'));
$r->get('request')->get['page']=2;$bridge=new \FurMedia\Cache\Bridge($r);$bridge->before('common/home');
$ref=new ReflectionObject($bridge);$property=$ref->getProperty('settings');$property->setAccessible(true);$settings=$property->getValue($bridge);
$rules=\FurMedia\Cache\PageRules::parse($settings['page_rules']);
check($rules['common/home']['ttl']===45,'conditional TTL integrated with native Bridge');
check($rules['product/category']['enabled']===false,'conditional TTL preserves other native page rules');
