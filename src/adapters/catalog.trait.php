<?php
trait FurMediaCatalogActions {
    private function fmBridge() {
        if (!$this->registry->has('furmedia_cache_bridge')) { $this->registry->set('furmedia_cache_bridge',new \FurMedia\Cache\Bridge($this->registry)); }
        return $this->registry->get('furmedia_cache_bridge');
    }
    public function before(&$route, &$args) {
        if (!$this->config->get('module_furmedia_cache_status')) { return; }
        try {
            if($this->fmBridge()->skipDuplicateFilter($route,$args)){$route=self::FM_ROUTE.'/emptyFragment';return;}
            if ($this->fmBridge()->before($route)) {
                $route = self::FM_ROUTE . (version_compare(VERSION,'4.0.0.0','>=') ? '.cached' : '/cached');
                if (version_compare(VERSION,'4.0.0.0','>=') && version_compare(VERSION,'4.1.0.0','<')) { return new \Opencart\System\Engine\Action($route); }
                // OC2/3 router and OC4.1 framework construct the Action using the mutated route.
            }
        } catch (\Exception $e) { $this->log->write('FurMedia Cache: page lookup failed, native rendering preserved'); } catch (\Throwable $e) { $this->log->write('FurMedia Cache: page lookup error, native rendering preserved'); }
    }
    public function emptyFragment(){return '';}
    public function viewFragments(&$route,&$data,&$output=null){
        if(!$this->config->get('module_furmedia_cache_status')){return;}
        try{$this->fmBridge()->viewFragments($route,$data);}catch(\Exception $e){}catch(\Throwable $e){}
    }
    public function after(&$route, &$args, &$output = null) {
        if (!$this->config->get('module_furmedia_cache_status')) { return; }
        try { $this->fmBridge()->fragment($route,$output,version_compare(VERSION,'4.0.0.0','>=')?$args:array($args));$this->fmBridge()->after($route); } catch (\Exception $e) { $this->log->write('FurMedia Cache: page write failed, native output preserved'); } catch (\Throwable $e) { $this->log->write('FurMedia Cache: page write error, native output preserved'); }
    }
    public function worker(){
        $this->response->addHeader('Cache-Control: no-cache');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');$this->response->addHeader('Content-Type: application/javascript; charset=utf-8');$this->response->addHeader('X-Content-Type-Options: nosniff');
        if($this->request->server['REQUEST_METHOD']!=='GET'){$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');$this->response->setOutput('/* GET required */');return;}
        try{$result=$this->fmBridge()->pwaResponse();$this->response->addHeader('Service-Worker-Allowed: '.$result['scope']);$this->response->setOutput($result['body']);}catch(\Exception $e){$this->response->addHeader('HTTP/1.1 404 Not Found');$this->response->setOutput('/* Worker disabled */');}catch(\Throwable $e){$this->response->addHeader('HTTP/1.1 404 Not Found');$this->response->setOutput('/* Worker disabled */');}
    }
    public function cron() {
        $this->response->addHeader('Content-Type: application/json');$this->response->addHeader('Cache-Control: private, no-store');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');
        if($this->request->server['REQUEST_METHOD']!=='POST'){$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');$this->response->setOutput('{"error":"POST required"}');return;}
        try {$this->response->setOutput(json_encode($this->fmBridge()->cronResponse(isset($this->request->server['HTTP_AUTHORIZATION'])?$this->request->server['HTTP_AUTHORIZATION']:'')));}
        catch(\Exception $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->setOutput('{"error":"Runner unavailable; verify HTTPS, credentials and private storage"}');}
        catch(\Throwable $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->setOutput('{"error":"Runner unavailable"}');}
    }
    public function panel() {
        $this->response->addHeader('Content-Type: application/json');$this->response->addHeader('Cache-Control: private, no-store');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');
        if($this->request->server['REQUEST_METHOD']!=='POST'){$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');$this->response->setOutput('{"error":"POST required"}');return;}
        try{$this->response->setOutput(json_encode($this->fmBridge()->panelPurge(isset($this->request->post['panel_token'])?$this->request->post['panel_token']:'')));}catch(\Exception $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->setOutput('{"error":"Invalid session or expired token"}');}
    }
    public function widgets() {
        $this->response->addHeader('Content-Type: application/json');$this->response->addHeader('Cache-Control: private, no-store');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');$this->response->addHeader('Vary: Cookie');
        try {$this->response->setOutput($this->fmBridge()->widgetResponse());}
        catch(\Exception $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->setOutput('{"error":"Native widgets unavailable"}');}
        catch(\Throwable $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->setOutput('{"error":"Native widgets unavailable"}');}
    }
    public function esi() {
        $this->response->addHeader('Cache-Control: private, no-store');
        try {$this->response->setOutput($this->fmBridge()->esiResponse(isset($this->request->get['id'])?$this->request->get['id']:'',isset($this->request->get['signature'])?$this->request->get['signature']:''));}
        catch(\Exception $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');$this->response->setOutput('ESI fragment unavailable');}
        catch(\Throwable $e){$this->response->addHeader('HTTP/1.1 403 Forbidden');$this->response->addHeader('X-LiteSpeed-Cache-Control: no-cache');$this->response->setOutput('ESI fragment unavailable');}
    }
    public function cached() { /* Output already belongs to this request's private cache context. */ }
    public function invalidate(&$route, &$args, &$output = null) {
        try { $this->fmBridge()->invalidate($route); } catch (\Exception $e) { $this->log->write('FurMedia Cache: invalidation failed'); }
    }
    public function modelBefore(&$route, &$args, &$output = null) {
        if (!$this->config->get('module_furmedia_cache_status')) { return; }
        try { return $this->fmBridge()->modelBefore($route,$args); } catch (\Exception $e) { return null; }
    }
    public function modelAfter(&$route, &$args, &$output = null) {
        if (!$this->config->get('module_furmedia_cache_status')) { return; }
        try { $this->fmBridge()->modelAfter($route,$output); } catch (\Exception $e) { }
    }
}
