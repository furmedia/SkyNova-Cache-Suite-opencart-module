<?php
// Real OpenCart Registry/Config/Controller/Response/Action/Event; controlled session, cart and DB-free fixture.
$family=$argv[1];$version=$argv[2];$original=$argv[3];
$v4=version_compare($version,'4.0.0.0','>=');
if (!preg_match('/define\(\'VERSION\',\s*\'([^\']+)\'\)/',file_get_contents($original.'/index.php'),$versionMatch) || $versionMatch[1]!==$version) { throw new Exception('Fixture version does not match native source'); }
$project=dirname(__DIR__);$runtime=__DIR__.'/runtime/native-'.$family.'-'.getmypid();
@mkdir($runtime.'/shop/catalog/controller/common',0755,true);@mkdir($runtime.'/shop/image/cache',0755,true);@mkdir($runtime.'/cache',0700,true);
define('VERSION',$version);define('DIR_CACHE',$runtime.'/cache/');define('DIR_IMAGE',$runtime.'/shop/image/');define('DIR_APPLICATION',$runtime.'/shop/catalog/');
define('HTTP_SERVER','https://shop.example/');define('HTTPS_SERVER',HTTP_SERVER);
define('DIR_SYSTEM',$project.'/build/'.$family.'/upload/system/');
define('DIR_EXTENSION',$runtime.'/extension/');
if ($v4) {
    @mkdir(DIR_EXTENSION.'furmedia_cache',0755,true);
    // Core lives in build; wrapper require follows standard extension location via linked fixture files.
    function copyTree($source,$target) { @mkdir($target,0755,true);foreach(new DirectoryIterator($source) as $f){if($f->isDot()){continue;}if($f->isDir()){copyTree($f->getPathname(),$target.'/'.$f->getFilename());}else{copy($f->getPathname(),$target.'/'.$f->getFilename());}} }
    copyTree($project.'/build/oc4/system',DIR_EXTENSION.'furmedia_cache/system');
}
require $original.'/system/engine/registry.php';require $original.'/system/engine/controller.php';require $original.($v4?'/system/engine/config.php':'/system/library/config.php');require $original.'/system/library/response.php';require $original.'/system/engine/action.php';require $original.'/system/engine/event.php';
$ns=$v4?'Opencart\\System\\':'';
$registryClass=$ns.'Engine\\Registry';$controllerClass=$ns.'Engine\\Controller';$configClass=$ns.($v4?'Engine\\Config':'Library\\Config');$responseClass=$ns.'Library\\Response';$eventClass=$ns.'Engine\\Event';
if(!$v4){$registryClass='Registry';$controllerClass='Controller';$configClass='Config';$responseClass='Response';$eventClass='Event';}
$wrapper=$project.'/build/'.$family.($v4?'/catalog/controller/module/furmedia_cache.php':'/upload/catalog/controller/extension/module/furmedia_cache.php');
require $wrapper;
$moduleClass=$v4?'Opencart\\Catalog\\Controller\\Extension\\FurmediaCache\\Module\\FurmediaCache':'ControllerExtensionModuleFurmediaCache';
$route=$v4?'extension/furmedia_cache/module/furmedia_cache':'extension/module/furmedia_cache';
$checks=0;function check($v,$message){global $checks;$checks++;if(!$v){throw new Exception('FAIL: '.$message);}}
class FixtureSession {public $data=array('currency'=>'RON');public $id='guest-A';public function getId(){return $this->id;}}
class FixtureCustomer {public $logged=false;public function isLogged(){return $this->logged;}}
class FixtureCart {public $products=false;public function hasProducts(){return $this->products;}}
class FixtureLog {public $lines=array();public function write($x){$this->lines[]=$x;}}
class FixtureFactory {public $registry;public $moduleClass;public function controller($route){$class=$this->moduleClass;return new $class($this->registry);}}
function setup($session='guest-A',$settings=array()) {
    global $registryClass,$configClass,$responseClass,$eventClass,$moduleClass;
    $r=new $registryClass();$c=new $configClass();
    $s=\FurMedia\Cache\Settings::normalize(array_merge(array('status'=>1,'mode'=>'session','html_minify'=>1),$settings));
    foreach(array('module_furmedia_cache_status'=>1,'module_furmedia_cache_settings'=>$s,'config_store_id'=>0,'config_language_id'=>1,'config_customer_group_id'=>1,'config_url'=>HTTP_SERVER,'config_ssl'=>HTTP_SERVER,'config_theme'=>'default','action_default'=>'common/home','session_name'=>'OCSESSID','application'=>'Catalog')as$k=>$v){$c->set($k,$v);}
    $q=new stdClass();$q->get=array('route'=>'common/home');$q->cookie=array('OCSESSID'=>$session);$q->server=array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/','HTTP_HOST'=>'shop.example','HTTP_ACCEPT'=>'text/html','HTTPS'=>'on');
    $ss=new FixtureSession();$ss->id=$session;
    foreach(array('config'=>$c,'request'=>$q,'session'=>$ss,'response'=>new $responseClass(),'customer'=>new FixtureCustomer(),'cart'=>new FixtureCart(),'log'=>new FixtureLog())as$k=>$v){$r->set($k,$v);}
    $f=new FixtureFactory();$f->registry=$r;$f->moduleClass=$moduleClass;$r->set('factory',$f);$r->set('event',new $eventClass($r));
    return $r;
}
function dispatch($r,$body,$afterChange=null) {
    global $moduleClass,$v4,$version,$runtime,$route;
    $c=new $moduleClass($r);$main='common/home';$args=array();$out=null;
    // Startup response headers are native on OC4. On all versions session renewal must not be replayed.
    if($v4){$r->get('response')->addHeader('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');}
    $r->get('response')->addHeader('Set-Cookie: OCSESSID='.$r->get('session')->id.'; Path=/; HttpOnly');
    $actionClass=$v4?'Opencart\\System\\Engine\\Action':'Action';
    $suffix=$v4?'.':'/';
    if(!$v4){@mkdir(DIR_APPLICATION.'controller/extension/module',0755,true);file_put_contents(DIR_APPLICATION.'controller/extension/module/furmedia_cache.php',"<?php // Native Action fixture file\n");}
    $r->get('event')->register('controller/*/before',new $actionClass($route.$suffix.'before'));
    $r->get('event')->register('controller/*/after',new $actionClass($route.$suffix.'after'));
    $result=$r->get('event')->trigger('controller/'.$main.'/before',array(&$main,&$args));
    $hit = $main !== 'common/home';
    if($hit){
        if($v4 && version_compare($version,'4.1.0.0','<')){check($result instanceof \Opencart\System\Engine\Action,'OC4.0 returns replacement Action'); $executed=$result->execute($r,$args);check(!($executed instanceof Exception),'native OC4.0 Action executes cached route');}
        // OC2/3 and 4.1 route replacement branch is verified by the actual native Action below.
        if(!$v4){
            @mkdir(DIR_APPLICATION.'controller/extension/module',0755,true);
            file_put_contents(DIR_APPLICATION.'controller/extension/module/furmedia_cache.php',"<?php // Class already loaded\n");
            $action=new Action($main);$executed=$action->execute($r);check(!($executed instanceof Exception),'native legacy Action executes cached route');
        }elseif(version_compare($version,'4.1.0.0','>=')){
            $action=new \Opencart\System\Engine\Action($main);$executed=$action->execute($r,$args);check(!($executed instanceof Exception),'native 4.1 Action executes cached route');
        }
    }else{
        $r->get('response')->addHeader('Content-Type: text/html; charset=utf-8');$r->get('response')->setOutput($body);
        if($afterChange){call_user_func($afterChange,$r);}
    }
    $r->get('event')->trigger('controller/'.$main.'/after',array(&$main,&$args,&$out));
    check(count($r->get('log')->lines)===0,'no swallowed exceptions: '.implode(';',$r->get('log')->lines));
    return $hit;
}
$body='<!doctype html><html><head></head><body><!--remove--><p>Guest A</p></body></html>';
$r=setup();check(!dispatch($r,$body),'first request miss');
check(strpos($r->get('response')->getOutput(),'remove')===false,'native response optimized');
$r=setup();check(dispatch($r,'WRONG'),'same session hits');check(strpos($r->get('response')->getOutput(),'Guest A')!==false,'cached response rendered');
$r=setup('guest-B');check(!dispatch($r,str_replace('Guest A','Guest B',$body)),'other guest misses');check(strpos($r->get('response')->getOutput(),'Guest B')!==false,'other guest receives own page');
$r=setup();$r->get('customer')->logged=true;check(!dispatch($r,$body),'logged-in user bypasses');
$r=setup();$r->get('cart')->products=true;check(!dispatch($r,$body),'nonempty cart bypasses');
$r=setup();$r->get('session')->data['currency']='EUR';check(!dispatch($r,$body),'currency has separate cache');
$r=setup();$r->get('config')->set('config_store_id',2);check(!dispatch($r,$body),'store has separate cache');
$r=setup();$r->get('request')->server['REQUEST_METHOD']='POST';check(!dispatch($r,$body),'POST bypasses');
$r=setup('changing');dispatch($r,$body,function($r){$r->get('session')->data['flash']='new';});
$r=setup('changing');check(!dispatch($r,$body),'changed session response not cached');
$r=setup('private-header');dispatch($r,$body,function($r){$r->get('response')->addHeader('Set-Cookie: private=secret');});
$r=setup('private-header');check(!dispatch($r,$body),'new response cookie blocks caching');
$r=setup('observe',array('mode'=>'observe'));check(!dispatch($r,$body),'observe misses');check($r->get('response')->getOutput()===$body,'observe never rewrites page');
$r=setup('journal',array('lazy_images'=>1));$r->set('journal3',new stdClass());$journal=str_replace('</body>','<img src="image/a.png"><img src="image/b.png"><img src="image/c.png"></body>',$body);dispatch($r,$journal);check(strpos($r->get('response')->getOutput(),'loading="lazy"')===false,'Journal native lazy-loading retained');
$r=setup();$c=new $moduleClass($r);$mutation=$v4?'checkout/order.addHistory':'checkout/order/addOrderHistory';$a=array();$o=null;$c->invalidate($mutation,$a,$o);
$r=setup();check(!dispatch($r,$body),'order mutation invalidates cached price/stock output');
if (!$v4) {
    $options=array('model_cache'=>1,'model_allow'=>'catalog/category/getCategory');
    $r=setup('model-test',$options);$c=new $moduleClass($r);$method='catalog/category/getCategory';$a=array(20);
    check($c->modelBefore($method,$a)===null,'first model read cold');
    $result=array('category_id'=>20,'name'=>'Desktops');$c->modelAfter($method,$a,$result);
    $r=setup('model-test',$options);$c=new $moduleClass($r);
    check($c->modelBefore($method,$a)===$result,'allowlisted model result reused');
    $r=setup('another-model-session',$options);$c=new $moduleClass($r);
    check($c->modelBefore($method,$a)===null,'model results isolated by session');
    $unsafe='catalog/product/getProduct';check($c->modelBefore($unsafe,$a)===null,'non-allowlisted product data stays native');
}

class DataLoader {
    public $calls=0;public $registry;public $change=false;public $form=false;public $responseChange=false;
    public function controller($route,...$args){$this->calls++;if($this->responseChange){$this->registry->get('response')->setOutput('native side effect '.$this->calls);}if($this->change){$this->registry->get('session')->data['changed']=$this->calls;}return $this->form?'<form>private</form>':'<div>static block</div>';}
}
function componentFixture($session,$change=false,$form=false,$logged=false,$responseChange=false){
    $settings=array('component_cache'=>1,'component_allow'=>'extension/module/html','hide_category_count'=>1);
    $r=setup($session,$settings);$loader=new DataLoader();$loader->registry=$r;$loader->change=$change;$loader->form=$form;$loader->responseChange=$responseChange;$r->set('load',$loader);$r->get('customer')->logged=$logged;$r->get('config')->set('config_product_count',1);
    $c=new \FurMedia\Cache\Bridge($r);$c->before('common/home');
    $a=$r->get('load')->controller('extension/module/html',array('module_id'=>1));$b=$r->get('load')->controller('extension/module/html',array('module_id'=>1));
    $r->get('response')->setOutput('<html><body><script nonce="fresh"></script></body></html>');$c->after('common/home');
    return array($loader->calls,$a,$b,$r->get('config')->get('config_product_count'));
}
$component=componentFixture('fragment-tests');check($component[0]===1,'component hit skips native repeated rendering');check($component[1]===$component[2],'component output retained');check($component[3]===0,'native category counts disabled');
$component=componentFixture('fragment-tests');check($component[0]===0,'component reused on next eligible request');
$component=componentFixture('fragment-other');check($component[0]===1,'component isolated across sessions');
$component=componentFixture('fragment-changes',true);check($component[0]===2,'component session side effects stay native');
$component=componentFixture('fragment-forms',false,true);check($component[0]===2,'component form output stays native');
$component=componentFixture('fragment-response',false,false,false,true);check($component[0]===2,'component response side effects stay native');
$component=componentFixture('fragment-auth',false,false,true);check($component[0]===2,'component logged customer bypass');
$r=setup('counts-observe',array('mode'=>'observe','hide_category_count'=>1));$r->get('config')->set('config_product_count',1);(new \FurMedia\Cache\Bridge($r))->before('common/home');check($r->get('config')->get('config_product_count')===1,'observation preserves counts');

require __DIR__.'/native-completion.php';
echo 'PASS '.$family.' / OC '.$version.' / PHP '.PHP_VERSION.' / '.$checks." assertions (native classes; session/cart doubles, no full store)\n";
