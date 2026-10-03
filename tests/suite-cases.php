<?php
use FurMedia\Cache\Settings;
use FurMedia\Cache\Drivers;
use FurMedia\Cache\Maintenance;
use FurMedia\Cache\Sqlmetrics;
use FurMedia\Cache\Telemetry;
use FurMedia\Cache\Pwa;
class SuiteMemoryDouble {public $entries=array();public function get($k){return isset($this->entries[$k])?$this->entries[$k]:false;}public function set($k,$v,$ttl){$this->entries[$k]=$v;return true;}}
$driver=new SuiteMemoryDouble();Drivers::register('suite_test',function()use($driver){return $driver;},'Test custom');
$customSettings=Settings::normalize(array('backend'=>'suite_test'));
check($customSettings['backend']==='suite_test','Registered custom driver selectable');
$customStore=new FurMedia\Cache\CacheStore($tmp.'/custom-driver',$customSettings);$customStore->set('a','before',60);
check(count($driver->entries)===1&&$customStore->get('a')==='before','Custom driver stores and reads');$customStore->purge();check($customStore->get('a')===null,'Custom driver obeys disk generation purge');
$rule=array('id'=>'daily','action'=>'ANALYZE','tables'=>array('oc_product'),'at'=>'03:30','timezone'=>'Europe/Bucharest','days'=>array(7));
check(count(Maintenance::parse(json_encode(array($rule))))===1,'Schedule schema');
check(Maintenance::slot($rule,1792893600)==='2026-10-25','Scheduler handles repeated DST hour');
check(Maintenance::slot($rule,1792807200)===null,'Scheduler respects weekdays');
$bad=$rule;$bad['at']='25:00';$blocked=false;try{Maintenance::parse(json_encode(array($bad)));}catch(Exception $e){$blocked=true;}check($blocked,'Invalid schedule rejected');
$sample=array('digest'=>hash('sha256','SELECT'), 'shape'=>'SELECT product_id FROM oc_product WHERE status=?','ms'=>2.0,'area'=>'catalog','ok'=>true);
Sqlmetrics::append($tmp.'/metrics',array($sample,$sample));$rows=Sqlmetrics::rows($tmp.'/metrics');check($rows[0]['count']===2&&$rows[0]['avg_ms']===2.0,'Measured SQL digests aggregate');
Telemetry::hit($tmp.'/views',0,'product/product','UTC',1700000000);Telemetry::hit($tmp.'/views',1,'common/home','UTC',1700000000);$views=Telemetry::rows($tmp.'/views',0);
check(count($views)===1&&$views[0]['total']===1&&!isset($views[0]['ip']),'Views isolated by store without IP identifiers');
check(Pwa::names("owned-a\nowned-b")===array('owned-a','owned-b'),'PWA exact owned names');
$blocked=false;try{Pwa::names('../escape');}catch(Exception $e){$blocked=true;}check($blocked,'PWA arbitrary names refused');
$worker=Pwa::worker('/shop/','epoch');check(strpos($worker,'u.search')!==false&&strpos($worker,'immutable')!==false&&strpos($worker,'furmedia_cache')!==false,'Worker restricts generated immutable assets');
check(Pwa::worker('/shop/','epoch')!==Pwa::worker('/shop/','new-epoch'),'Worker generation changes asset namespace');
