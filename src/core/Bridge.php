<?php
namespace FurMedia\Cache;

/** One instance per OpenCart registry. Integration runs after native startup/session/SEO. */
class Bridge {
    private $registry;
    private $settings;
    private $store;
    private $request;
    private $context;
    private $generation;
    private $key;
    private $route;
    private $started = false;
    private $hit = false;
    private $models = array();
    private $startupHeaders = array();
    private $lease;
    private $shared=false;
    private $resource=false;
    private $esiUsed=false;
    private $esiEndpoint=false;
    private $fragments=array(); private $delivered=false; private $pageExpires=0;
    private $components=array(); private $componentOutput; private $sql;

    public function __construct($registry) {
        $this->registry = $registry;
        $config = $registry->get('config');
        $saved = $config->get('module_furmedia_cache_settings');
        $this->settings = Settings::normalize(is_array($saved) ? $saved : array());
        $this->settings['status'] = (int)$config->get('module_furmedia_cache_status');
        if ($this->settings['status'] && $this->settings['mode']!=='observe' && !Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))) {
            throw new \RuntimeException('Private storage outside the web root is required.');
        }
        $this->store = new CacheStore(DIR_CACHE . 'furmedia_cache', $this->settings);
    }

    public function pwaResponse(){
        if(!$this->settings['status']||!$this->settings['pwa_assets']||$this->settings['mode']==='observe'){throw new \RuntimeException('Worker disabled');}
        $base=$this->get('config')->get('config_url');$scope=parse_url($base,PHP_URL_PATH);$scope=rtrim($scope?:'/','/').'/';if(!preg_match('~^/[a-zA-Z0-9_/-]*$~D',$scope)){throw new \RuntimeException('Worker scope unavailable');}
        return array('scope'=>$scope,'body'=>Pwa::worker($scope,$this->store->generation(),$this->settings['pwa_limit']));
    }

    private function get($key) { return $this->registry->get($key); }
    private function context() {
        $config = $this->get('config'); $session = $this->get('session');
        $customer = $this->get('customer'); $cart = $this->get('cart');
        $profile=array();if($customer && $customer->isLogged()){foreach(array('getFirstName','getLastName','getEmail','getTelephone','getAddressId','getGroupId') as $method){if(method_exists($customer,$method)){$profile[$method]=$customer->$method();}}}
        $cookies = $this->get('request')->cookie; unset($cookies['skynova_state']);ksort($cookies);
        return array(
            'store'=>(int)$config->get('config_store_id'),
            'origin'=>(string)$config->get('config_url'),
            'language'=>(string)$config->get('config_language_id'),
            'currency'=>isset($session->data['currency']) ? $session->data['currency'] : $config->get('config_currency'),
            'group'=>$customer && $customer->isLogged() && method_exists($customer,'getGroupId')?$customer->getGroupId():$config->get('config_customer_group_id'),
            'customer_key'=>$customer && method_exists($customer,'getId')?hash('sha256',(string)$customer->getId()):'',
            'customer_profile'=>hash('sha256',serialize($profile)),
            'tax_state'=>$this->taxState(),
            'customer_token_hash'=>isset($session->data['customer_token']) && is_string($session->data['customer_token'])?hash('sha256',$session->data['customer_token']):'',
            'cart_state'=>$cart && $this->settings['page_rules'] && method_exists($cart,'getProducts')?hash('sha256',serialize($cart->getProducts())):'',
            'theme'=>$config->get('config_theme'),
            'session_id'=>method_exists($session,'getId') ? hash('sha256',$session->getId()) : '',
            'state'=>hash('sha256',serialize($session->data)),
            'cookies'=>hash('sha256',serialize($cookies)),
            'customer'=>$customer ? (bool)$customer->isLogged() : true,
            'cart'=>$cart ? (bool)$cart->hasProducts() : true,
            'affiliate'=>!empty($session->data['affiliate_id']),
            'maintenance'=>(bool)$config->get('config_maintenance'),
            'user_agent'=>$this->settings['device_vary']?Litespeed::device(isset($this->get('request')->server['HTTP_USER_AGENT'])?$this->get('request')->server['HTTP_USER_AGENT']:''):hash('sha256',isset($this->get('request')->server['HTTP_USER_AGENT']) ? $this->get('request')->server['HTTP_USER_AGENT'] : ''),
            'accept'=>isset($this->get('request')->server['HTTP_ACCEPT']) ? $this->get('request')->server['HTTP_ACCEPT'] : '',
            'https'=>!empty($this->get('request')->server['HTTPS']) && $this->get('request')->server['HTTPS'] !== 'off',
            'host'=>isset($this->get('request')->server['HTTP_HOST']) ? $this->get('request')->server['HTTP_HOST'] : '',
            'settings'=>hash('sha256',serialize($this->settings))
        );
    }

    private function diagnostic($status) {
        if(!$this->settings['debug'] || ($this->settings['debug_session_hash']!=='' && (!isset($this->context['session_id']) || !hash_equals($this->settings['debug_session_hash'],$this->context['session_id'])))){return;}
        $this->get('response')->addHeader('X-FurMedia-Cache: ' . $status);
        if(!empty($this->context['session_id'])){$this->get('response')->addHeader('X-SkyNova-Session: '.$this->context['session_id']);}
        if($this->settings['debug_details']){
            try{(new History(DIR_CACHE.'furmedia_cache-diagnostic-'.(int)$this->context['store']))->append('diagnostic',array('status'=>$status,'route'=>$this->route,'store'=>(int)$this->context['store']));}catch(\Exception $e){}
        }
    }

    public function before($route) {
        if ($this->started || !$this->settings['status']) { return false; }
        // Only the main requested route. Nested common/header, module and Journal AJAX stay native.
        $q = $this->get('request');
        $main = isset($q->get['route']) ? $q->get['route'] : $this->get('config')->get('action_default');
        if ($main !== $route) { return false; }
        $this->started = true; $this->route = $route;
        try{$this->loginWarm();}catch(\Exception $e){$this->get('log')->write('SkyNova: login warm queue unavailable; native rendering preserved');}
        if ($this->settings['mode']!=='observe' && $this->settings['hide_category_count']) { $this->get('config')->set('config_product_count',0); }
        $s = $q->server;
        $this->request = array('route'=>$route,'query'=>$q->get,'uri'=>isset($s['REQUEST_URI'])?$s['REQUEST_URI']:'/', 'method'=>isset($s['REQUEST_METHOD'])?$s['REQUEST_METHOD']:'GET', 'authorization'=>!empty($s['HTTP_AUTHORIZATION']) || !empty($s['PHP_AUTH_USER']), 'ajax'=>!empty($s['HTTP_X_REQUESTED_WITH']), 'range'=>!empty($s['HTTP_RANGE']));
        $predicates=array();foreach(array('config_store_id','config_language_id','config_customer_group_id','config_currency','config_theme') as $field){$predicates[$field]=$this->get('config')->get($field);}
        $condition=Conditions::match($this->settings['conditions'],$route,$q->get,$predicates,$this->get('session')->data);
        if($condition&&isset($condition['ttl'])){$rules=json_decode($this->settings['page_rules'],true);if(!is_array($rules)){$rules=array();}if(!isset($rules[$route])){$rules[$route]=array();}$rules[$route]['ttl']=(int)$condition['ttl'];$this->settings['page_rules']=json_encode($rules);}
        $this->context = $this->context();
        if($this->settings['search_acceleration']&&$this->settings['mode']!=='observe'&&!($this->settings['journal']&&($this->registry->has('journal3')||$this->registry->has('journal2')||$this->get('config')->get('config_theme')==='journal3'))){$root=DIR_CACHE.'furmedia_cache-search';$this->registry->set('db',new Searchquery($this->get('db'),new SearchIndex($this->get('db'),DB_PREFIX,$root,DIR_CACHE.'furmedia_cache-db-archives',$this->settings['db_archive_mb']),DB_PREFIX,$root));}
        if($this->settings['sql_profile']&&$this->settings['mode']!=='observe'){$this->registry->set('db',new Sqlprofile($this->get('db'),DIR_CACHE.'furmedia_cache-sqlprofile-'.$this->context['store'],$this->settings['sql_slow_ms']));}
        if($condition&&isset($condition['enabled'])&&!$condition['enabled']){$this->diagnostic('BYPASS-condition');return false;}
        if($this->settings['litespeed']){$this->get('response')->addHeader('X-LiteSpeed-Cache-Control: no-cache');$this->litespeedState($this->context);}
        $this->startupHeaders = $this->headers();
        $this->resource=Resources::eligible($this->request,$this->context,$this->settings);
        $reason = $this->resource?'':(new Policy())->reason($this->request,$this->context,$this->settings);
        if ($reason) { $this->diagnostic('BYPASS-' . $reason); $this->store->count('bypass'); return false; }
        if ($this->settings['component_cache'] && $this->settings['mode']!=='observe') { $this->registry->set('load',new Components($this->get('load'),$this)); }
        if ($this->settings['sql_cache'] && $this->settings['mode']!=='observe') {
            $this->sql=new Sqlcache($this->get('db'),$this->store,$this->settings,function(){return $this->dataContext();},DB_PREFIX);
            $this->registry->set('db',$this->sql);
        }
        $this->shared=!$this->settings['cache_panel'] && !$this->resource && SharedPage::eligible($this->registry,$this->request,$this->settings);
        $this->key = ($this->resource?'resource:':'') . (new Policy())->key($this->request,$this->shared ? SharedPage::context($this->context) : $this->context);
        $this->generation = $this->store->generation();
        if ($this->settings['mode'] === 'observe') { $this->diagnostic('OBSERVE'); return false; }
        $entry = $this->store->get($this->key);
        if (is_array($entry) && isset($entry['body'])) {
            $this->pageExpires=isset($entry['expires_at'])?(int)$entry['expires_at']:time()+PageRules::ttl($this->settings,$route);
            $body=$this->shared ? $this->optimizeBody(SharedPage::render($entry['shared'],$this->registry)) : $entry['body'];
            $this->get('response')->setOutput($body);
            foreach ($entry['headers'] as $header) { $this->get('response')->addHeader($header); }
            if(isset($entry['status']) && $entry['status']===404){$this->get('response')->addHeader('HTTP/1.1 404 Not Found');}
            $this->get('response')->addHeader('Cache-Control: private, no-store');
            $this->get('response')->addHeader('Vary: Cookie, Accept, Accept-Encoding');
            if ($this->settings['gzip'] && Delivery::gzipAllowed(isset($this->get('request')->server['HTTP_ACCEPT_ENCODING'])?$this->get('request')->server['HTTP_ACCEPT_ENCODING']:'')) { $this->get('response')->setCompression(6); }
            $this->hit = true; $this->diagnostic('HIT'); $this->store->count('hit');
            if(!$this->resource && isset($entry['tags']) && (!isset($entry['status']) || $entry['status']===200)){$this->esiUsed=!empty($entry['esi']);$this->litespeedPage($entry['tags'],PageRules::ttl($this->settings,$this->route));}
            return true;
        }
        $this->lease=$this->store->lease($this->key);
        if (!$this->lease) { $this->key=null;$this->diagnostic('BYPASS-busy');$this->store->count('bypass');return false; }
        $this->diagnostic('MISS'); $this->store->count('miss');
        return false;
    }

    private function headers() {
        $response = $this->get('response');
        if (method_exists($response,'getHeaders')) { return array_merge($response->getHeaders(), headers_list()); }
        $reflection = new \ReflectionObject($response);
        if (!$reflection->hasProperty('headers')) { return array('Cache-Control: no-store'); }
        $property = $reflection->getProperty('headers'); $property->setAccessible(true);
        return array_merge((array)$property->getValue($response), headers_list());
    }

    public function fragment($route,&$output,array $args=array()) {
        if(!$this->esiEndpoint && $this->settings['litespeed_esi'] && $this->litespeedStable() && getenv('SKYNOVA_LSCACHE_ESI')==='1' && $this->request && $this->request['method']==='GET' && !$this->hit && is_string($output)){
            $base=parse_url($this->context['origin'],PHP_URL_PATH);$base=$base?rtrim($base,'/'):'';
            $endpoint=$base.'/index.php?route='.rawurlencode(version_compare(VERSION,'4.0.0.0','>=')?'extension/furmedia_cache/module/furmedia_cache.esi':'extension/module/furmedia_cache/esi');
            $session=$this->get('session');if(method_exists($session,'getId') && strlen($session->getId())>=8 && strpos($output,$session->getId())!==false){return;}
            $replacement=(new Esi(DIR_CACHE.'furmedia_cache-esi',$this->settings['esi_modules']))->includeModule($route,$args,$output,$this->esiContext(),$this->store->generation(),$endpoint);
            if($replacement!==$output){$output=$replacement;$this->esiUsed=true;$this->get('response')->addHeader('X-LiteSpeed-Cache-Control: no-cache,esi=on');}
        }
        if ($this->shared && !$this->hit && in_array($route,array('common/header','common/footer'),true) && is_string($output)) { $this->fragments[$route]=$output; }
    }

    public function esiResponse($id,$signature) {
        $this->esiEndpoint=true;
        if(!$this->settings['litespeed_esi'] || !Litespeed::ready($this->settings,$this->get('request')->server) || getenv('SKYNOVA_LSCACHE_ESI')!=='1' || $this->get('request')->server['REQUEST_METHOD']!=='GET'){throw new \RuntimeException('ESI unavailable');}
        $row=(new Esi(DIR_CACHE.'furmedia_cache-esi',$this->settings['esi_modules']))->descriptor($id,$signature,$this->esiContext(),$this->store->generation());
        $headers=$this->headers();$body=call_user_func_array(array($this->get('load'),'controller'),array_merge(array($row['route']),$row['args']));
        $newHeaders=array_values(array_diff($this->headers(),$headers));
        if(!is_string($body) || strlen($body)>1048576){throw new \RuntimeException('Invalid ESI fragment');}
        foreach(Esi::headers($row,$body,$newHeaders) as $header){$this->get('response')->addHeader($header);}
        $this->get('response')->addHeader('Content-Type: text/html; charset=utf-8');$this->get('response')->addHeader('Cache-Control: private, no-store');$this->get('response')->addHeader('X-Content-Type-Options: nosniff');return $body;
    }

    private function taxState() {
        $tax=$this->get('tax');if(!is_object($tax)){return '';}
        $reflection=new \ReflectionObject($tax);if(!$reflection->hasProperty('tax_rates')){return '';}
        $property=$reflection->getProperty('tax_rates');$property->setAccessible(true);$rates=$property->getValue($tax);
        return is_array($rates)?hash('sha256',serialize($rates)):'';
    }
    public function widgetResponse() {
        if(!$this->settings['status'] || $this->settings['mode']==='observe' || !$this->settings['dynamic_widgets'] || $this->get('request')->server['REQUEST_METHOD']!=='GET'){throw new \RuntimeException('Widgets unavailable');}
        $header=$this->get('load')->controller('common/header');$cart=$this->get('load')->controller('common/cart');
        if(!is_string($header) || !is_string($cart) || strlen($header)+strlen($cart)>1048576){throw new \RuntimeException('Native widgets unavailable');}
        $language=$this->get('language');$this->get('load')->language('product/category');$session=$this->get('session');
        $compare=sprintf($language->get('text_compare'),isset($session->data['compare']) && is_array($session->data['compare'])?count($session->data['compare']):0);
        return json_encode(array('header'=>$header,'cart'=>$cart,'compare'=>$compare),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    }
    public function cronResponse($authorization) {
        $context=$this->context();$secret=Vault::value('SKYNOVA_CRON_SECRET',$context['store']);
        if(!$this->settings['status'] || !$context['https'] || !Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\'))) || !is_string($secret) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$secret) || !is_string($authorization) || !preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/D',$authorization,$match) || !hash_equals($secret,$match[1])){throw new \RuntimeException('Runner authorization failed');}
        $lock=$this->store->lease('cron:'.$context['store']);if(!$lock){throw new \RuntimeException('Runner busy');}
        try {
            $key='cron:last:'.$context['store'];if($this->store->get($key)!==null){return array('retry_after'=>60);}
            if(!$this->store->set($key,true,60)){throw new \RuntimeException('Cannot persist runner rate limit');}
            $origin=$this->get('config')->get('config_ssl')?:$this->get('config')->get('config_url');
            $queue=new WarmQueue(DIR_CACHE.'furmedia_cache-jobs-'.hash('sha256',$origin),$origin);
            $warm=$queue->run(array('paths'=>Settings::lines($this->settings['warm_urls']),'sitemaps'=>Settings::lines($this->settings['warm_sitemaps']),'variants'=>$this->settings['warm_variants'],'limit'=>$this->settings['warm_limit'],'interval'=>$this->settings['warm_interval']));
            $batch=(new Batch(DIR_CACHE.'furmedia_cache-batch-'.$context['store'],dirname(rtrim(DIR_APPLICATION,'/\\')),$origin,$this->settings))->step(10);
            $summary=array();foreach(array('status','processed','generated','skipped','errors','scanned') as $field){if(isset($batch[$field])){$summary[$field]=$batch[$field];}}
            (new History(DIR_CACHE.'furmedia_cache-history-'.$context['store']))->append('warm',$warm);
            $dbResult=array();if($context['store']===0&&$this->settings['db_schedules']!==''){$dbResult=(new Maintenance($this->get('db'),DB_PREFIX,DIR_CACHE.'furmedia_cache-db-schedules',DIR_CACHE.'furmedia_cache-db-archives',$this->settings['db_archive_mb']))->tick($this->settings['db_schedules'],$this->settings['db_schedule_limit']);}elseif($context['store']===0&&$this->settings['db_auto_analyze']){$dbResult=(new Dbtools($this->get('db'),DB_PREFIX,DIR_CACHE.'furmedia_cache-db-backup'))->scheduled(Settings::lines($this->settings['db_analyze_tables']),DIR_CACHE.'furmedia_cache-db-schedule');}return array('warm'=>$warm,'batch'=>$summary,'database'=>$dbResult,'expired_removed'=>$this->store->gc());
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    public function after($route) {
        try { $this->writePage($route); $this->deliver($route); }
        finally { if($route===$this->route && $this->settings['litespeed']){$current=$this->context();if($current!==$this->context){$this->get('response')->addHeader(Litespeed::purge());$this->get('response')->addHeader('X-LiteSpeed-Cache-Control: no-cache');}$this->litespeedState($current);}if ($route===$this->route && $this->sql) { $this->sql->disable(); } if ($route===$this->route && is_resource($this->lease)) { flock($this->lease,LOCK_UN);fclose($this->lease);$this->lease=null; } }
    }

    public function __destruct() { if (is_resource($this->lease)) { flock($this->lease,LOCK_UN);fclose($this->lease); } }

    private function writePage($route) {
        if (!$this->key || $this->hit || $route !== $this->route || $this->settings['mode'] === 'observe') { return; }
        $body = $this->get('response')->getOutput(); $headers = $this->headers();
        if (strlen($body)>$this->settings['max_entry_kb']*1024) { $this->diagnostic('BYPASS-size'); return; }
        $admissionHeaders = array();
        $sessionName = (string)$this->get('config')->get('session_name');
        if (!$sessionName && version_compare(VERSION,'3.0.0.0','<')) { $sessionName = 'OCSESSID'; }
        foreach ($headers as $header) {
            // Native startup renews this same session on every request. It is never persisted/replayed.
            if (in_array($header,$this->startupHeaders,true)) {
                if ($sessionName && preg_match('/^Set-Cookie:\s*(?:' . preg_quote($sessionName,'/') . '|language|currency)=/i',$header)) { continue; }
                if ($header === 'Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0') { continue; }
            }
            $admissionHeaders[] = $header;
        }
        $status = http_response_code(); if ($status === false) { $status = 200; }
        foreach($admissionHeaders as $header){if(preg_match('~^HTTP/\S+\s+(\d+)~i',$header,$match)){$status=(int)$match[1];}}
        if($this->resource){
            $safe=Resources::headers($admissionHeaders,$status);
            if(!$safe || $this->context()!==$this->context){$this->diagnostic('BYPASS-resource-response');return;}
            if($this->store->set($this->key,array('body'=>$body,'headers'=>$safe),$this->settings['dynamic_ttl'],array('catalog','resource','route:'.$route),$this->generation)){$this->store->count('write');}
            $this->get('response')->addHeader('Cache-Control: private, no-store');$this->get('response')->addHeader('Vary: Cookie, Accept, Accept-Encoding');return;
        }
        $session=$this->get('session');$nativeCustomerToken=!$this->shared && !empty($this->context['customer']) && isset($session->data['customer_token']) && is_string($session->data['customer_token'])?$session->data['customer_token']:null;
        if (!(new Policy())->responseAllowed($body,$admissionHeaders,$status,(bool)$this->settings['cache_404'],$nativeCustomerToken)) { $this->diagnostic('BYPASS-response'); return; }
        if($status===404 && $this->shared){$this->diagnostic('BYPASS-shared-404');return;}
        $session=$this->get('session');
        if($this->shared && method_exists($session,'getId') && strlen($session->getId())>=8 && strpos($body,$session->getId())!==false){$this->diagnostic('BYPASS-session-in-body');return;}
        $shared=$this->shared ? SharedPage::pack($body,$this->fragments,$this->get('document')) : null;
        if ($this->shared && !$shared) { $this->diagnostic('BYPASS-fragments');return; }
        $body=$this->optimizeBody($body);
        $this->get('response')->setOutput($body);
        if ($this->settings['gzip'] && Delivery::gzipAllowed(isset($this->get('request')->server['HTTP_ACCEPT_ENCODING'])?$this->get('request')->server['HTTP_ACCEPT_ENCODING']:'')) { $this->get('response')->setCompression(6); }
        if ($this->context() !== $this->context) { $this->diagnostic('BYPASS-state-change'); return; }
        $safe = array();
        foreach ($headers as $header) { if (preg_match('/^(Content-Type|Content-Language|Link):/i',$header)) { $safe[] = $header; } }
        $tags = array('catalog','route:' . $route);
        if (isset($this->request['query']['path'])) { foreach(explode('_',$this->request['query']['path']) as $id){$tags[]='category:'.(int)$id;} }
        if (isset($this->request['query']['product_id'])) { $tags[] = 'product:' . (int)$this->request['query']['product_id']; }
        $entry=array('body'=>$shared?'':$body,'headers'=>$safe,'tags'=>$tags,'status'=>$status,'esi'=>$this->esiUsed,'expires_at'=>time()+($status===404?$this->settings['ttl_404']:PageRules::ttl($this->settings,$route)));
        $this->pageExpires=$entry['expires_at'];
        if(!$this->shared){$tags[]='session:'.$this->context['session_id'];}
        if ($shared) { $entry['shared']=$shared; }
        if ($this->store->set($this->key,$entry,$status===404?$this->settings['ttl_404']:PageRules::ttl($this->settings,$this->route),$tags,$this->generation)) { $this->store->count('write'); }
        $this->get('response')->addHeader('Cache-Control: private, no-store');
        $this->get('response')->addHeader('Vary: Cookie, Accept, Accept-Encoding');
        if($status===200){$this->litespeedPage($tags,PageRules::ttl($this->settings,$this->route));}
    }

    private function litespeedState(array $context) {
        if($this->settings['mode']==='observe' || empty($context['https'])){return;}
        $fingerprint=hash('sha256',serialize(array($context,$this->store->generation())));
        $incoming=isset($this->get('request')->cookie['skynova_state'])?$this->get('request')->cookie['skynova_state']:'';
        if($incoming!==$fingerprint){
            $path=parse_url($context['origin'],PHP_URL_PATH);$path=is_string($path) && preg_match('~^/[a-zA-Z0-9_/-]*$~D',$path)?$path:'/';
            $this->get('response')->addHeader('Set-Cookie: skynova_state='.$fingerprint.'; Path='.$path.'; Secure; HttpOnly; SameSite=Lax');
        }
    }
    private function litespeedPage(array $tags,$ttl) {
        if(!Litespeed::ready($this->settings,$this->get('request')->server) || $this->shared || !empty($this->context['customer']) || !empty($this->context['cart']) || empty($this->context['https'])){return;}
        $fingerprint=hash('sha256',serialize(array($this->context,$this->store->generation())));
        $incoming=isset($this->get('request')->cookie['skynova_state'])?$this->get('request')->cookie['skynova_state']:'';
        if(!is_string($incoming) || !hash_equals($fingerprint,$incoming)){return;}
        $name=$this->get('config')->get('session_name')?:'OCSESSID';
        foreach(Litespeed::headers($ttl,$tags,$name,$this->esiUsed) as $header){$this->get('response')->addHeader($header);}
    }
    private function esiContext() {
        $context=$this->context();$safe=empty($context['customer']) && empty($context['cart']);$name=$this->get('config')->get('session_name')?:'OCSESSID';
        foreach($this->get('request')->cookie as $key=>$value){if(!in_array($key,array($name,'language','currency','skynova_state'),true)){$safe=false;}}
        foreach($this->get('session')->data as $key=>$value){if(in_array($key,array('wishlist','compare'),true) && empty($value)){continue;}if(!in_array($key,array('language','currency'),true) || !is_scalar($value)){$safe=false;}}
        $context['public_esi_safe']=$safe;return $context;
    }
    private function litespeedStable() {
        if(!$this->context || !Litespeed::ready($this->settings,$this->get('request')->server)){return false;}
        $name=$this->get('config')->get('session_name')?:'OCSESSID';$cookies=$this->get('request')->cookie;
        $fingerprint=hash('sha256',serialize(array($this->context(),$this->store->generation())));
        return isset($cookies[$name],$cookies['skynova_state']) && is_string($cookies['skynova_state']) && hash_equals($fingerprint,$cookies['skynova_state']);
    }

    private function optimizeBody($body) {
        $base = $this->context['https'] ? $this->get('config')->get('config_ssl') : $this->get('config')->get('config_url');
        if (!$base) { $base = defined('HTTPS_SERVER') && $this->context['https'] ? HTTPS_SERVER : HTTP_SERVER; }
        $assets = new Assets(dirname(rtrim(DIR_APPLICATION,'/\\')), DIR_IMAGE . 'cache/furmedia_cache', $base, $this->settings);
        $options = $this->settings;
        // Journal owns resource bundling/lazy loading; native mode prevents two optimizers rewriting the same assets.
        if ($options['journal'] && ($this->get('config')->get('config_theme') === 'journal3' || $this->registry->has('journal3') || $this->registry->has('journal2'))) {
            $options['lazy_media']=0;$options['lazy_blocks']='';$options['resource_attributes']='';$options['delay_rules']='';$options['replacements']='';$options['external_assets']='';$options['custom_css']='';$options['custom_js']='';$options['extract_js']=0;$options['script_position']='native';
            foreach (array('css_merge','js_merge','css_minify','js_minify','defer_js','delay_js','responsive_images','lazy_images','lazy_iframes') as $name) { $options[$name] = 0; }
            $assets = new Assets(dirname(rtrim(DIR_APPLICATION,'/\\')), DIR_IMAGE . 'cache/furmedia_cache', $base, $options);
        }
        $body=Advanced::transform($body,$options,$assets,$this->route);$body=(new Optimizer($options,$assets))->transform($body,$this->context['accept']);
        if($this->settings['dynamic_widgets'] && !($options['journal'] && ($this->registry->has('journal3') || $this->registry->has('journal2') || $this->get('config')->get('config_theme')==='journal3'))){
            $path=parse_url($base,PHP_URL_PATH);$endpoint=rtrim($path?:'/','/').'/index.php?route='.rawurlencode(version_compare(VERSION,'4.0.0.0','>=')?'extension/furmedia_cache/module/furmedia_cache.widgets':'extension/module/furmedia_cache/widgets');
            $script=Widgets::script($endpoint,$this->settings['widgets_interval']);$body=preg_replace_callback('~</body\s*>~i',function($m)use($script){return $script.$m[0];},$body,1);
        }
         $hints=Delivery::hints($this->settings);$body=preg_replace_callback('~</head\s*>~i',function($m)use($hints){return $hints.$m[0];},$body,1);
        return $body;
    }


    public function dataContext() {
        if (!$this->started || !$this->request || $this->settings['mode']==='observe') { return false; }
        $context=$this->context();
        return (new Policy())->reason($this->request,$context,$this->settings) ? false : $context;
    }
    private function componentSnapshot() {
        return serialize(array($this->context(),$this->headers(),$this->get('document'),$this->get('response')));
    }
    public function componentBefore($route,$args) {
         $rule=ModuleRules::resolve($this->settings['module_rules'],$route,$args,$this->settings['component_ttl']);
        if (!$this->settings['component_cache'] || !$this->dataContext() || $route===$this->route || ($rule?!$rule['enabled']:!in_array($route,Settings::lines($this->settings['component_allow']),true))) { return false; }
        if (!preg_match('~^(?:extension/(?:[a-z0-9_]+/)?module|information)/[a-z0-9_/]+$~D',$route) || preg_match('/cart|account|checkout|payment|journal|captcha|consent/i',$route)) { return false; }
        $key='component:'.hash('sha256',serialize(array($route,$args,$this->request,$this->context())));
        $entry=$this->store->get($key);
        if (is_array($entry) && isset($entry['body'])) { $this->componentOutput=$entry['body'];$this->store->count('component_hit');return true; }
        $this->components[$route][]=array($key,$this->store->generation(),$this->componentSnapshot(),$rule?$rule:array('ttl'=>$this->settings['component_ttl'],'tag'=>'module:'.$route));return false;
    }
    public function componentOutput() { $body=$this->componentOutput;$this->componentOutput=null;return $body; }
    public function componentAfter($route,$output) {
        if (empty($this->components[$route])) { return; }$state=array_pop($this->components[$route]);
        if (!is_string($output) || $state[2]!==$this->componentSnapshot() || !$this->dataContext() || preg_match('/<form\b|\b(?:nonce|csrf|token)\b|(?:session|user_token)=/i',$output)) { return; }
        $session=$this->get('session');if(method_exists($session,'getId') && strlen($session->getId())>=8 && strpos($output,$session->getId())!==false){return;}
        $this->store->set($state[0],array('body'=>$output),$state[3]['ttl'],array('catalog','component','route:'.$route,'module:'.$route,$state[3]['tag'],'session:'.$this->context['session_id']),$state[1]);
    }

    private function loginWarm() {
        if(!$this->settings['warm_login']||$this->settings['mode']==='observe'){return;}$session=$this->get('session');$logged=$this->get('customer')&&$this->get('customer')->isLogged();$id=$logged&&method_exists($this->get('customer'),'getId')?(int)$this->get('customer')->getId():0;
        if($id&&!isset($session->data['skynova_warmed_customer'])){$origin=$this->get('config')->get('config_ssl')?:$this->get('config')->get('config_url');$queue=new WarmQueue(DIR_CACHE.'furmedia_cache-jobs-'.hash('sha256',$origin),$origin);$queue->run(array('paths'=>Settings::lines($this->settings['warm_urls']),'limit'=>0,'sitemaps'=>Settings::lines($this->settings['warm_sitemaps']),'interval'=>$this->settings['warm_interval'],'variants'=>$this->settings['warm_variants']));$session->data['skynova_warmed_customer']=$id;}
        if(!$id){unset($session->data['skynova_warmed_customer']);}
    }
    public function panelPurge($token) {
        $context=$this->context();if(!$this->settings['status']||!$this->settings['cache_panel']||$this->settings['mode']==='observe'||!FrontPanel::verify(new Vault(DIR_CACHE.'furmedia_cache-vault'),$token,$context['session_id'],$this->store->generation())){throw new \RuntimeException('Invalid panel token');}
        return array('removed'=>$this->store->purge('session:'.$context['session_id']));
    }
    private function compressionHeadersSafe(array $headers){foreach($headers as $header){if(in_array($header,$this->startupHeaders,true)){continue;}if(preg_match('/^(Set-Cookie|Location|Content-Disposition|Content-Encoding):/i',$header)||preg_match('/^Cache-Control:.*(?:no-store|no-cache)/i',$header)||preg_match('~^HTTP/\S+\s+(?!200\b)~i',$header)){return false;}}return true;}
    private function deliver($route) {
        $cachedRoute=version_compare(VERSION,'4.0.0.0','>=')?'extension/furmedia_cache/module/furmedia_cache.cached':'extension/module/furmedia_cache/cached';
        if($this->delivered||!$this->started||($route!==$this->route&&$route!==$cachedRoute)||$this->settings['mode']==='observe'){return;}$this->delivered=true;$response=$this->get('response');$body=$response->getOutput();if(!is_string($body)||$body===''){return;}$headers=$this->headers();$type='text/html';$encoded=false;foreach($headers as $header){if(preg_match('/^Content-Type:\s*([^;]+)/i',$header,$m)){$type=strtolower($m[1]);}if(preg_match('/^(Content-Encoding|Content-Length|Location|Content-Disposition):/i',$header)){$encoded=true;}}if($encoded){return;}
        if($type==='text/html'&&$this->request['method']==='GET'&&in_array($this->route,Settings::lines($this->settings['routes']),true)){
            if($this->settings['view_stats']&&empty($this->context['customer'])&&!preg_match('/bot|crawler|spider|skynova|furmedia/i',isset($this->get('request')->server['HTTP_USER_AGENT'])?$this->get('request')->server['HTTP_USER_AGENT']:'')){try{Telemetry::hit(DIR_CACHE.'furmedia_cache-views',(int)$this->context['store'],$this->route,$this->settings['timezone']);}catch(\Exception $e){}}
            if($this->settings['pwa_assets']){$scope=rtrim(parse_url($this->get('config')->get('config_url'),PHP_URL_PATH)?:'/','/').'/';$endpoint=$scope.'index.php?route='.rawurlencode(version_compare(VERSION,'4.0.0.0','>=')?'extension/furmedia_cache/module/furmedia_cache.worker':'extension/module/furmedia_cache/worker');$script=Pwa::registration($endpoint,$scope);$body=preg_replace_callback('~</body\s*>~i',function($m)use($script){return $script.$m[0];},$body,1);}
        }
        if($this->settings['cache_panel']&&$type==='text/html'&&$this->pageExpires>0){$ctx=$this->context();$origin=$this->get('config')->get('config_url');$path=parse_url($origin,PHP_URL_PATH);$endpoint=rtrim($path?:'/','/').'/index.php?route='.(version_compare(VERSION,'4.0.0.0','>=')?'extension/furmedia_cache/module/furmedia_cache.panel':'extension/module/furmedia_cache/panel');$token=FrontPanel::token(new Vault(DIR_CACHE.'furmedia_cache-vault'),$ctx['session_id'],$this->store->generation(),time()+600);$panel=FrontPanel::html($endpoint,$token,max(0,$this->pageExpires-time()),$this->settings);$body=preg_replace_callback('~</body\s*>~i',function($m)use($panel){return $panel.$m[0];},$body,1);$response->addHeader('Cache-Control: private, no-store');$response->addHeader('X-LiteSpeed-Cache-Control: no-cache');}
        $xml=in_array($type,array('text/xml','application/xml'),true)&&$this->settings['compress_xml'];$xhtml=$type==='application/xhtml+xml'&&$this->settings['compress_xhtml'];$rss=in_array($type,array('application/rss+xml','application/atom+xml'),true)&&$this->settings['compress_rss'];
        $gzip=$this->settings['gzip']&&in_array($type,array('text/html','text/css','text/javascript','application/javascript'),true);
        if(($xml||$xhtml||$rss||$gzip)&&empty($this->context['customer'])&&empty($this->context['cart'])&&!$this->settings['cache_panel']&&$this->request['method']==='GET'&&strlen($body)<=$this->settings['max_entry_kb']*1024){$compressed=Delivery::compressed($body,isset($this->get('request')->server['HTTP_ACCEPT_ENCODING'])?$this->get('request')->server['HTTP_ACCEPT_ENCODING']:'',$this->store,PageRules::ttl($this->settings,$this->route),$this->settings['reserve_compressed'] && ($this->pageExpires>0 || ($this->resource && $this->context()===$this->context && $this->compressionHeadersSafe($headers))) && !preg_match('/<form\b|\b(?:token|nonce|csrf|password)\b/i',$body));if(is_string($compressed)){$body=$compressed;$response->setCompression(0);$response->addHeader('Content-Encoding: gzip');$response->addHeader('Vary: Cookie, Accept, Accept-Encoding');}}
        $response->setOutput($body);
    }

    public function invalidate($route) {
        // All catalog listings can contain related products/prices; global purge is deliberately conservative.
        if (preg_match('~(?:/|\.)(?:add|edit|delete|approve|update|clear|save)[A-Z_a-z]*$~',$route)) { $this->store->purge();if($this->settings['litespeed']){$this->get('response')->addHeader(Litespeed::purge());} }
    }

    public function modelBefore($route, $args) {
        if (!$this->settings['status'] || !$this->settings['model_cache'] || $this->settings['mode'] !== 'session' || version_compare(VERSION,'4.0.0.0','>=')) { return null; }
        // Explicitly bounded read-only methods, no generic SQL interception.
        $safe = array('catalog/category/getCategory','catalog/category/getCategories','catalog/information/getInformation','catalog/information/getInformations','catalog/manufacturer/getManufacturer','catalog/manufacturer/getManufacturers');
        if (!in_array($route,$safe,true) || !in_array($route,Settings::lines($this->settings['model_allow']),true)) { return null; }
        $key = 'model:' . hash('sha256',serialize(array($route,$args,$this->context())));
        $entry = $this->store->get($key);
        $this->models[$route] = array($key,$this->store->generation(),$entry !== null);
        return $entry; // Native OC2/3 loaders recompute false/empty results; never misrepresent a hit.
    }

    public function modelAfter($route, $output) {
        if (!isset($this->models[$route])) { return; }
        $state = $this->models[$route]; unset($this->models[$route]);
        if (!$state[2] && (is_array($output) || is_scalar($output))) { $this->store->set($state[0],$output,$this->settings['model_ttl'],array('catalog'),$state[1]); }
    }
}
