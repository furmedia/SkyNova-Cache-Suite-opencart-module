<?php
define('VERSION','3.0.5.1');require dirname(__DIR__).'/src/core/bootstrap.php';
class SJRegistry{public $items;public function get($key){return $this->items[$key];}public function has($key){return isset($this->items[$key]);}}
class SJConfig{public $theme='journal3';public $afs=false;public function get($key){return $key==='module_ai_filter_suite_status'?$this->afs:($key==='config_theme'?$this->theme:($key==='session_name'?'OCSESSID':null));}}
class SJCustomer{public $logged=false;public function isLogged(){return $this->logged;}}
class SJCart{public $full=false;public function hasProducts(){return $this->full;}}
$r=new SJRegistry();$r->items=array('config'=>new SJConfig(),'customer'=>new SJCustomer(),'cart'=>new SJCart(),'journal3'=>new stdClass(),'session'=>(object)array('data'=>array('language'=>'ro-ro','currency'=>'RON')),'request'=>(object)array('cookie'=>array('OCSESSID'=>'guest','language'=>'ro-ro')));
$s=FurMedia\Cache\Settings::normalize(array('mode'=>'shared','shared_routes'=>'common/home'));$q=array('route'=>'common/home');$n=0;
function verify($ok,$name){global $n;if(!$ok){throw new Exception($name);}$n++;}
verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'Journal requires explicit approval');$s['journal_shared']=1;
verify(FurMedia\Cache\SharedPage::eligible($r,$q,$s),'approved empty Journal guest');
$r->items['session']->data['trinity_session_started_at']=time();verify(FurMedia\Cache\SharedPage::eligible($r,$q,$s),'verified startup timestamp marker');unset($r->items['session']->data['trinity_session_started_at']);
$r->items['session']->data['binoclo_bis_csrf']=str_repeat('a',48);verify(FurMedia\Cache\SharedPage::eligible($r,$q,$s),'native footer token allowed');
$r->items['session']->data['binoclo_bis_csrf']='unknown';verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'unknown token format refused');unset($r->items['session']->data['binoclo_bis_csrf']);
foreach(array('wishlist'=>array(1),'compare'=>array(1),'coupon'=>'EXAMPLE','shipping_address'=>array('zone_id'=>1),'journal3_history'=>array(1),'user_id'=>1) as $key=>$value){$r->items['session']->data[$key]=$value;verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'private state '.$key);unset($r->items['session']->data[$key]);}
$r->items['cart']->full=true;verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'occupied cart refused');$r->items['cart']->full=false;
$r->items['customer']->logged=true;verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'customer refused');$r->items['customer']->logged=false;
$r->items['request']->cookie['persistent_cart']='other';verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'unknown cookies refused');unset($r->items['request']->cookie['persistent_cart']);
verify(!FurMedia\Cache\SharedPage::eligible($r,array('route'=>'product/product'),$s),'unapproved route refused');
verify(!FurMedia\Cache\SharedPage::privateEligible($r,$q,$s),'private fragments require explicit routes');$s['journal_private_routes']='common/home';
$r->items['request']->cookie['jrv']='6624';$r->items['request']->cookie['PHPSESSID']='other-native-session';
$r->items['session']->data['journal3_history']=array(6624);$r->items['cart']->full=true;
verify(!FurMedia\Cache\SharedPage::eligible($r,$q,$s),'occupied/private Journal session cannot share');
verify(FurMedia\Cache\SharedPage::privateEligible($r,$q,$s),'private fragment approval retains cookie/history/cart in private context');
$policy=new FurMedia\Cache\Policy();$request=array('uri'=>'/','route'=>'common/home','query'=>array());
$context=array('session_id'=>'session-a','cookies'=>'jrv-a','state'=>'cart-a');
foreach(array('session_id'=>'session-b','cookies'=>'jrv-b','state'=>'cart-b') as $field=>$value){$other=$context;$other[$field]=$value;verify($policy->key($request,$context)!==$policy->key($request,$other),'private key varies '.$field);}
verify(!FurMedia\Cache\SharedPage::privateEligible($r,array('route'=>'checkout/checkout'),$s),'checkout not approved');
$r->items['customer']->logged=true;verify(!FurMedia\Cache\SharedPage::privateEligible($r,$q,$s),'logged customers not enabled by private guest approval');$r->items['customer']->logged=false;
$r->items['cart']->full=false;unset($r->items['session']->data['journal3_history'],$r->items['request']->cookie['jrv'],$r->items['request']->cookie['PHPSESSID']);
$r->items['request']->cookie['jrv']='6624,7';$r->items['session']->data['jrv']=array(6624,7);
verify(FurMedia\Cache\SharedPage::unchangedHistoryCookie('Set-Cookie: jrv=6624%2C7; path=/',$r,'product/product'),'exact Journal history renewal');
verify(!FurMedia\Cache\SharedPage::unchangedHistoryCookie('Set-Cookie: jrv=7%2C6624; path=/',$r,'product/product'),'changed history cookie refused');
verify(!FurMedia\Cache\SharedPage::unchangedHistoryCookie('Set-Cookie: other=6624%2C7; path=/',$r,'product/product'),'other cookie refused');
verify(!FurMedia\Cache\SharedPage::unchangedHistoryCookie('Set-Cookie: jrv=6624%2C7; path=/',$r,'common/home'),'other route cookie refused');
$r->items['session']->data['jrv']=array(7,6624);verify(!FurMedia\Cache\SharedPage::unchangedHistoryCookie('Set-Cookie: jrv=6624%2C7',$r,'product/product'),'session history mismatch refused');unset($r->items['request']->cookie['jrv'],$r->items['session']->data['jrv']);
$reflection=new ReflectionClass('FurMedia\\Cache\\Bridge');$bridge=$reflection->newInstanceWithoutConstructor();$s['status']=1;$s['journal_filter_ids']='36';
foreach(array('registry'=>$r,'settings'=>$s) as $key=>$value){$property=$reflection->getProperty($key);$property->setAccessible(true);$property->setValue($bridge,$value);}
verify(!$bridge->skipDuplicateFilter('journal3/filter',array('module_id'=>36)),'AFS disabled preserves Journal filter');$r->items['config']->afs=true;
verify($bridge->skipDuplicateFilter('journal3/filter',array('module_id'=>36)),'approved duplicate filter suppressed');
verify(!$bridge->skipDuplicateFilter('journal3/filter',array('module_id'=>37)),'other instances preserved');
verify(!$bridge->skipDuplicateFilter('journal3/filter/get',array('module_id'=>36)),'AJAX routes preserved');
$s['mode']='observe';$property=$reflection->getProperty('settings');$property->setAccessible(true);$property->setValue($bridge,$s);verify(!$bridge->skipDuplicateFilter('journal3/filter',array('module_id'=>36)),'observe mode preserves filter');
echo 'PASS '.$n." Journal guest admission checks\n";
