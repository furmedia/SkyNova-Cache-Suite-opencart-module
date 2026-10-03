<?php
trait FurMediaAdminActions {
    private function fmSettings() {
        $this->load->model('setting/setting');
        $row = $this->model_setting_setting->getSetting('module_furmedia_cache',(int)(isset($this->request->get['store_id'])?$this->request->get['store_id']:0));
        $saved = isset($row['module_furmedia_cache_settings']) ? $row['module_furmedia_cache_settings'] : array();
        $s = \FurMedia\Cache\Settings::normalize(is_array($saved) ? $saved : array());
        $s['status'] = isset($row['module_furmedia_cache_status']) ? (int)$row['module_furmedia_cache_status'] : 0;
        return $s;
    }
    public function index() {
        if (!$this->user->hasPermission('access',self::FM_ROUTE)) { $this->response->addHeader('HTTP/1.1 403 Forbidden'); $this->response->setOutput('Permission denied'); return; }
        $this->load->model('setting/store');
        $stores=array(array('store_id'=>0,'name'=>'Magazin principal','url'=>defined('HTTPS_CATALOG')?HTTPS_CATALOG:HTTP_CATALOG));
        foreach($this->model_setting_store->getStores() as $row){$stores[]=$row;}
        $storeId=isset($this->request->get['store_id'])?(int)$this->request->get['store_id']:0;$selectedStore=null;
        foreach($stores as $row){if((int)$row['store_id']===$storeId){$selectedStore=$row;}}
        if(!$selectedStore){$this->response->addHeader('HTTP/1.1 400 Bad Request');$this->response->setOutput('Unknown store');return;}
        $storeUrl=!empty($selectedStore['ssl'])?$selectedStore['ssl']:$selectedStore['url'];
        $this->document->setTitle('SkyNova Cache Suite');
        if (empty($this->session->data['fm_cache_nonce'])) { $this->session->data['fm_cache_nonce'] = bin2hex(\FurMedia\Cache\Entropy::bytes(24)); }
        $s = $this->fmSettings();if($s['sql_profile']&&!($this->db instanceof \FurMedia\Cache\Sqlprofile)){$this->registry->set('db',new \FurMedia\Cache\Sqlprofile($this->db,DIR_CACHE.'furmedia_cache-sqlprofile-'.$storeId,$s['sql_slow_ms'],'admin'));} $message = ''; $error = ''; $indexes=array();
        try {
            $store = new \FurMedia\Cache\CacheStore(DIR_CACHE . 'furmedia_cache',$s);
            if ($this->request->server['REQUEST_METHOD'] === 'POST') {
                $post = $this->request->post;
                if (!$this->user->hasPermission('modify',self::FM_ROUTE) || !isset($post['fm_nonce']) || !is_string($post['fm_nonce']) || !hash_equals($this->session->data['fm_cache_nonce'],$post['fm_nonce'])) { throw new \RuntimeException('Nu ai permisiune sau sesiunea formularului a expirat.'); }
                $op = isset($post['operation']) ? $post['operation'] : '';
                if ($op === 'save' || $op === 'import') {
                    $input = $op === 'import' ? json_decode(html_entity_decode(isset($post['import_json'])?$post['import_json']:'',ENT_QUOTES,'UTF-8'),true) : (isset($post['settings'])?$post['settings']:array());
                    if (!is_array($input)) { throw new \InvalidArgumentException('Configurație JSON invalidă.'); }
                    if ($op === 'save') { foreach ($input as $k=>$v) { if (is_string($v)) { $input[$k] = html_entity_decode($v,ENT_QUOTES,'UTF-8'); } } }
                    $s = \FurMedia\Cache\Settings::normalize($input);
                    if ($op === 'import') { $s['mode'] = 'observe'; $s['status'] = 0; }
                    if ($s['status'] && $s['mode']!=='observe' && !\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))) { throw new \RuntimeException('Mută DIR_STORAGE în afara rădăcinii web înainte să activezi cache-ul de sesiune.'); }
                    $this->model_setting_setting->editSetting('module_furmedia_cache',array('module_furmedia_cache_status'=>$s['status'],'module_furmedia_cache_settings'=>$s),$storeId);
                    if(is_dir(DIR_CACHE.'furmedia_cache-search')){\FurMedia\Cache\SearchIndex::invalidate(DIR_CACHE.'furmedia_cache-search');}$store->purge(); $this->response->addHeader(\FurMedia\Cache\Litespeed::purge()); $message = 'Configurația a fost salvată. Cache-ul privat a fost invalidat.';
                 } elseif (\FurMedia\Cache\SuiteAdmin::accepts($op)) {
                    $dbResult=\FurMedia\Cache\SuiteAdmin::run($op,$post,$this->db,DB_PREFIX,$s,(bool)$this->config->get('config_maintenance'),$storeId);
                    if(in_array($op,array('db_archive_commit','db_archive_undo','db_archive_convert'),true)){$store->purge();} $message='Operațiunea a fost executată; verifică rezultatul și continuă loturile dacă este necesar.';
                 } elseif ($op==='external_import') {
                    $assetUrl=html_entity_decode(isset($post['asset_url'])?$post['asset_url']:'',ENT_QUOTES,'UTF-8');$result=\FurMedia\Cache\ExternalAssets::import($assetUrl,isset($post['asset_hash'])?$post['asset_hash']:'',isset($post['asset_type'])?$post['asset_type']:'',DIR_IMAGE.'cache/furmedia_cache',null,$s);
                    $maps=\FurMedia\Cache\Advanced::rows($s['external_assets']);$maps[$assetUrl]='image/cache/furmedia_cache/'.$result['file'];$s['external_assets']=json_encode($maps);$s=\FurMedia\Cache\Settings::normalize($s);$this->model_setting_setting->editSetting('module_furmedia_cache',array('module_furmedia_cache_status'=>$s['status'],'module_furmedia_cache_settings'=>$s),$storeId);$store->purge();$message='Resursă verificată, importată și mapată.';
                } elseif (in_array($op,array('db_inspect','db_analyze','db_optimize','db_convert','db_explain','db_preview','db_retention'),true)) {
                    if(!\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){throw new \RuntimeException('DB backups require private storage');}
                    $dbTools=new \FurMedia\Cache\Dbtools($this->db,DB_PREFIX,DIR_CACHE.'furmedia_cache-db-backup');$dbTables=$dbTools->tables();$ids=isset($post['db_tables'])?$post['db_tables']:array();if(!is_array($ids)){throw new \InvalidArgumentException('Invalid table selection');}
                    if(in_array($op,array('db_analyze','db_optimize','db_convert'),true)&&!$ids){throw new \InvalidArgumentException('Select at least one table');}
                    if(($op==='db_convert'||$op==='db_retention')&&empty($post['db_confirm'])){throw new \RuntimeException('Confirm the selected database change');}
                    if($op==='db_analyze'||$op==='db_optimize'){$dbResult=$dbTools->maintain($op==='db_analyze'?'ANALYZE':'OPTIMIZE',$ids);}
                    elseif($op==='db_convert'){$dbResult=$dbTools->convert($ids);$store->purge();}
                    elseif($op==='db_explain'){$dbResult=$dbTools->explain(html_entity_decode(isset($post['explain_sql'])?$post['explain_sql']:'',ENT_QUOTES,'UTF-8'));}
                    elseif($op==='db_preview'||$op==='db_retention'){$kind=isset($post['retention_kind'])?$post['retention_kind']:'';$days=isset($post['retention_days'])?$post['retention_days']:90;$selector=hash('sha256',serialize(array($storeId,$kind,(int)$days)));if($op==='db_retention'&&(!isset($this->session->data['skynova_db_preview'])||$this->session->data['skynova_db_preview']!==$selector)){throw new \RuntimeException('Preview the same retention selection before deleting');}$dbResult=$dbTools->retention($kind,$days,$op==='db_retention');if($op==='db_preview'){$this->session->data['skynova_db_preview']=$selector;}else{unset($this->session->data['skynova_db_preview']);$store->purge();}}
                    if(isset($dbResult)){(new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-history-'.$storeId))->append('dbmaintenance',array('operation'=>$op));}$message='Operațiune DB finalizată. Verifică rezultatul.';
                } elseif ($op==='browser_apply') {
                    $backup=\FurMedia\Cache\Delivery::applyApache(dirname(rtrim(DIR_APPLICATION,'/\\')),DIR_STORAGE.'skynova-maintenance',$s);$message='Profil Apache salvat în .htaccess; backup privat: '.$backup.'. Pentru Nginx folosește exportul și integrarea în server.';
                } elseif ($op==='browser_profile') {
                    $this->response->addHeader('Content-Type: text/plain; charset=utf-8');$this->response->addHeader('Content-Disposition: attachment; filename="skynova-browser-cache.txt"');$this->response->setOutput(\FurMedia\Cache\Delivery::profile($s));return;
                } elseif ($op==='module_save') {
                    $rows=\FurMedia\Cache\ModuleRules::parse($s['module_rules']);$controls=isset($post['module_controls'])?$post['module_controls']:array();if(!is_array($controls)){throw new \InvalidArgumentException('Invalid module controls');}
                    foreach($controls as $route=>$row){if(!isset($rows[$route])||!is_array($row)){throw new \InvalidArgumentException('Unknown module control');}$rows[$route]['enabled']=isset($row['enabled'])?(int)$row['enabled']:0;$rows[$route]['ttl']=isset($row['ttl'])?(int)$row['ttl']:$s['component_ttl'];}
                    $s['module_rules']=json_encode($rows,JSON_PRETTY_PRINT);$s=\FurMedia\Cache\Settings::normalize($s);$this->model_setting_setting->editSetting('module_furmedia_cache',array('module_furmedia_cache_status'=>$s['status'],'module_furmedia_cache_settings'=>$s),$storeId);$store->purge();$message='Regulile modulelor au fost salvate; setările instanțelor din JSON sunt păstrate.';
                } elseif ($op==='module_presets') {
                    $s['module_rules']=json_encode(array_merge(\FurMedia\Cache\ModuleRules::presets(version_compare(VERSION,'4.0.0.0','>=')),\FurMedia\Cache\ModuleRules::parse($s['module_rules'])),JSON_PRETTY_PRINT);$message='Șabloane încărcate, dezactivate. Configurează TTL și activează doar după verificare; apoi salvează.';
                } elseif ($op==='purge_module') {
                    $tag=isset($post['module_tag'])?$post['module_tag']:'';if(!is_string($tag)||!preg_match('~^module:(?:extension/(?:[a-z0-9_]+/)?module|information)/[a-z0-9_/]+(?::(?:module|layout):[1-9][0-9]*)?$~D',$tag)){throw new \InvalidArgumentException('Invalid module tag');}$message='Intrări eliminate: '.(int)$store->purge($tag);
                } elseif ($op==='remove_entry') {
                    $message='Intrări eliminate: '.(int)$store->removeId(isset($post['entry_id'])?$post['entry_id']:'');
                } elseif (in_array($op,array('archive_images','archive_logs','archive_ocmod','archive_native','archive_all'),true)) {
                    if(!\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){throw new \RuntimeException('Private archive storage required');}
                    $roots=array('images'=>DIR_IMAGE.'cache','logs'=>DIR_LOGS,'native'=>DIR_CACHE,'ocmod'=>defined('DIR_MODIFICATION')?DIR_MODIFICATION:DIR_STORAGE.'modification');
                    $kinds=$op==='archive_all'?array('images','logs','ocmod','native'):array(substr($op,8));$total=0;
                    foreach($kinds as $kind){$selected=$roots;if($kind==='native'){$selected['native']=DIR_CACHE;}$result=\FurMedia\Cache\Management::archive($kind,$selected,DIR_STORAGE.'skynova-maintenance');$total+=$result['moved'];}
                    $store->purge();$message='Fișiere arhivate recuperabil: '.$total.'. Maximum 500 pe categorie/pas. Repetă pentru continuare; după OCMOD folosește Refresh nativ.';
                } elseif ($op==='litespeed_profile') {
                    $this->response->addHeader('Content-Type: text/plain; charset=utf-8');$this->response->addHeader('Content-Disposition: attachment; filename="skynova-litespeed-profile.txt"');
                    $this->response->setOutput(\FurMedia\Cache\Litespeed::profile($this->config->get('session_name')?:'OCSESSID'));return;
                } elseif (in_array($op,array('warm_run','warm_pause','warm_resume'),true)) {
                    $queue=new \FurMedia\Cache\WarmQueue(DIR_CACHE.'furmedia_cache-jobs-'.hash('sha256',$storeUrl),$storeUrl);
                    if($op==='warm_pause'){$queue->pause(true);$message='Preîncălzirea a fost oprită.';}elseif($op==='warm_resume'){$queue->pause(false);$message='Preîncălzirea poate continua.';}
                    else{$result=$queue->run(array('paths'=>\FurMedia\Cache\Settings::lines($s['warm_urls']),'sitemaps'=>\FurMedia\Cache\Settings::lines($s['warm_sitemaps']),'variants'=>$s['warm_variants'],'limit'=>$s['warm_limit'],'interval'=>$s['warm_interval']));$message='Preîncălzire: '.(int)$result['fetched'].' cereri reușite, '.(int)$result['failed'].' erori.';(new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-history-'.$storeId))->append('warm',$result);}
                } elseif ($op==='integrations_save') {
                    if(!\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){throw new \RuntimeException('Service configuration requires storage outside the web root');}
                    $values=isset($post['services'])?$post['services']:array();$remove=isset($post['remove_services'])?$post['remove_services']:array();
                    if(!is_array($values) || !is_array($remove)){throw new \InvalidArgumentException('Invalid service configuration');}
                    foreach($values as $field=>$value){if(is_string($value)){$values[$field]=html_entity_decode($value,ENT_QUOTES,'UTF-8');}}
                    foreach($values as $field=>$value){if($storeId && preg_match('/^SKYNOVA_(?:CACHE_|REDIS_)/',$field) && $value!==''){throw new \InvalidArgumentException('Configure the shared cache connection from the main store');}}
                    (new \FurMedia\Cache\Vault(DIR_CACHE.'furmedia_cache-vault'))->save($values,$storeId,$remove);
                    $message='Configurarea serviciilor a fost salvată în seiful privat criptat. Valorile din mediul serverului au prioritate.';
                } elseif ($op==='s3_upload') {
                    $result=(new \FurMedia\Cache\Sthree())->upload(DIR_IMAGE.'cache/furmedia_cache',1,null,DIR_CACHE.'furmedia_cache-s3-'.$storeId,$storeId);
                    $message='S3: fișiere încărcate în acest pas: '.$result['uploaded'].'. Repetă pentru continuare.';
                } elseif ($op==='scripts_inventory') {
                    $path=isset($post['script_url'])?$post['script_url']:'/';$url=(new \FurMedia\Cache\WarmQueue(DIR_CACHE.'furmedia_cache-script-inspection',$storeUrl))->url($path);
                    if(!$url){throw new \InvalidArgumentException('Inspect only a public URL of this store');}
                    $html=(new \FurMedia\Cache\HttpClient())->get($url,2097152,10);
                    $scriptInventory=\FurMedia\Cache\Scripts::inventory($html['body']);$message='Inventar scripturi inline: '.count($scriptInventory).'. Hash-ul aprobă exact codul măsurat, fără deducerea automată a dependențelor.';
                } elseif (in_array($op,array('batch_images','batch_minify','batch_run','batch_cancel'),true)) {
                    if(!\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){throw new \RuntimeException('Batch storage must be outside the web root');}
                    $batch=new \FurMedia\Cache\Batch(DIR_CACHE.'furmedia_cache-batch-'.$storeId,dirname(rtrim(DIR_APPLICATION,'/\\')),$storeUrl,$s);
                    if($op==='batch_run'){$result=$batch->step(10);}elseif($op==='batch_cancel'){$result=$batch->cancel();}else{$result=$batch->start($op==='batch_images'?'images':'minify');}
                    $message='Procesare în lot: '.$result['status'];
                } elseif ($op === 'indexes_scan' || $op === 'indexes_apply') {
                    $diagnostic=new \FurMedia\Cache\Dbindexes($this->db,DB_PREFIX);
                    if($op==='indexes_apply'){
                        $ids=isset($post['index_ids'])?$post['index_ids']:array();if(!is_array($ids)){throw new \InvalidArgumentException('Selecție invalidă.');}
                        $added=$diagnostic->apply($ids);$store->purge();
                        (new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-index-history'))->append('indexes',array('added'=>$added));
                        $message='Indexuri adăugate: '.count($added);
                    }
                    $indexes=$diagnostic->inspect();
                } elseif ($op === 'pagespeed') {
                    $url = $storeUrl;
                    $report = (new \FurMedia\Cache\PageSpeed())->analyze($url,isset($post['strategy'])?$post['strategy']:'mobile',$storeId);
                    $store->set('pagespeed:last:'.$storeId,$report,86400,array('report'));
                    (new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-history-'.$storeId))->append('pagespeed',$report);
                    $message = 'Raport PageSpeed primit: ' . $report['score'] . '/100 (' . $report['strategy'] . ').';
                } elseif ($op === 'purge_tag') {
                    $tag=isset($post['purge_tag'])?$post['purge_tag']:'';
                    if(!is_string($tag) || !preg_match('~^(?:product:[1-9][0-9]*|category:[1-9][0-9]*|route:[a-z0-9_/]+)$~D',$tag)){throw new \InvalidArgumentException('Tag invalid: product:40, category:20 sau route:common/home');}
                    $message='Intrări eliminate: '.(int)$store->purge($tag);$this->response->addHeader(\FurMedia\Cache\Litespeed::purge($tag));
                } elseif ($op === 'cloudflare') {
                    $urls=\FurMedia\Cache\Settings::lines(html_entity_decode(isset($post['purge_urls'])?$post['purge_urls']:'',ENT_QUOTES,'UTF-8'));
                    (new \FurMedia\Cache\Cloudflare())->purge($storeUrl,$urls,$storeId);
                    $message='Cloudflare: URL-uri trimise pentru invalidare.';
                } elseif ($op === 'purge') { $this->response->addHeader(\FurMedia\Cache\Litespeed::purge());$message = 'Intrări eliminate: ' . (int)$store->purge(); }
                elseif ($op === 'gc') { $message = 'Intrări expirate eliminate: ' . (int)$store->gc(); }
                elseif ($op === 'export' || $op === 'runner') {
                    $export = $s;
                    if ($op === 'runner') { $export=array('cache_directory'=>DIR_CACHE.'furmedia_cache','origin'=>$storeUrl,'paths'=>\FurMedia\Cache\Settings::lines($s['warm_urls']),'limit'=>$s['warm_limit'],'sitemaps'=>\FurMedia\Cache\Settings::lines($s['warm_sitemaps']),'interval'=>$s['warm_interval'],'variants'=>$s['warm_variants'],'batch_directory'=>DIR_CACHE.'furmedia_cache-batch-'.$storeId,'store_root'=>dirname(rtrim(DIR_APPLICATION,'/\\')),'settings'=>$s); }
                    $this->response->addHeader('Content-Type: application/json');
                    $this->response->addHeader('Content-Disposition: attachment; filename="furmedia-cache-' . ($op==='runner'?'runner':'settings') . '.json"');
                    $this->response->setOutput(json_encode($export,JSON_PRETTY_PRINT)); return;
                } else { throw new \InvalidArgumentException('Operațiune necunoscută.'); }
            }
            $stats = $store->stats();
        } catch (\Exception $e) { $error = $e->getMessage(); $stats = array(); }
        $hits = isset($stats['hit'])?$stats['hit']:0; $miss = isset($stats['miss'])?$stats['miss']:0;
        $tokenName = version_compare(VERSION,'3.0.0.0','>=') ? 'user_token' : 'token';
        // Selected store URL resolved above.
        $batchState=array('status'=>'unavailable');
        $warmState=array();try{$warmState=(new \FurMedia\Cache\WarmQueue(DIR_CACHE.'furmedia_cache-jobs-'.hash('sha256',$storeUrl),$storeUrl))->status();}catch(\Exception $e){}
        try {if(\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){$batchState=(new \FurMedia\Cache\Batch(DIR_CACHE.'furmedia_cache-batch-'.$storeId,dirname(rtrim(DIR_APPLICATION,'/\\')),$storeUrl,$s))->status();}}catch(\Exception $e){}
        $dbArchives=array();$dbSchedule=array();if($storeId===0&&\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){try{if(is_dir(DIR_CACHE.'furmedia_cache-db-archives')){$dbArchives=(new \FurMedia\Cache\Dbarchive($this->db,DB_PREFIX,DIR_CACHE.'furmedia_cache-db-archives',$s['db_archive_mb']))->listing();}if(is_dir(DIR_CACHE.'furmedia_cache-db-schedules')){$dbSchedule=(new \FurMedia\Cache\Maintenance($this->db,DB_PREFIX,DIR_CACHE.'furmedia_cache-db-schedules',DIR_CACHE.'furmedia_cache-db-archives',$s['db_archive_mb']))->status();}}catch(\Exception $e){$error='Nu pot citi starea arhivelor DB.';}}
        $data = array('search_state'=>($storeId===0&&is_dir(DIR_CACHE.'furmedia_cache-search'))?(new \FurMedia\Cache\SearchIndex($this->db,DB_PREFIX,DIR_CACHE.'furmedia_cache-search',DIR_CACHE.'furmedia_cache-db-archives',$s['db_archive_mb']))->status():array(),'views'=>is_dir(DIR_CACHE.'furmedia_cache-views')?\FurMedia\Cache\Telemetry::rows(DIR_CACHE.'furmedia_cache-views',$storeId):array(),'db_archives'=>$dbArchives,'db_schedule'=>$dbSchedule,'db_tables'=>isset($dbTables)?$dbTables:array(),'db_result'=>isset($dbResult)?$dbResult:array(),'sql_profile'=>(new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-sqlprofile-'.$storeId))->rows('sqlprofile'),'warm'=>$warmState,'integrations'=>\FurMedia\Cache\Vault::status($storeId),'script_inventory'=>isset($scriptInventory)?$scriptInventory:array(),'batch'=>$batchState,'indexes'=>$indexes,'settings'=>$s,'message'=>$message,'error'=>$error,'nonce'=>$this->session->data['fm_cache_nonce'],
            'action'=>$this->url->link(self::FM_ROUTE,$tokenName . '=' . $this->session->data[$tokenName].'&store_id='.$storeId,true),
            'inventory_page'=>isset($this->request->get['cache_page'])?max(0,min(100,(int)$this->request->get['cache_page'])):0,'inventory'=>isset($store)?$store->inventory(200,isset($this->request->get['cache_page'])?max(0,min(100,(int)$this->request->get['cache_page']))*200:0):array(),'stores'=>$stores,'store_id'=>$storeId,'diagnostic_log'=>(new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-diagnostic-'.$storeId))->rows('diagnostic'),'daily'=>isset($stats['daily'])?$stats['daily']:array(),'history'=>(new \FurMedia\Cache\History(DIR_CACHE.'furmedia_cache-history-'.$storeId))->rows('pagespeed'),
            'cron_url'=>rtrim($storeUrl,'/').'/index.php?route='.rawurlencode(self::FM_ROUTE.(version_compare(VERSION,'4.0.0.0','>=')?'.cron':'/cron')),
            'pagespeed'=>'https://pagespeed.web.dev/analysis?url=' . rawurlencode($storeUrl),
            'report'=>isset($store) ? $store->get('pagespeed:last:'.$storeId) : null,
            'cards'=>array('Cache hits'=>(string)$hits,'Rată HIT'=>($hits+$miss ? round(100*$hits/($hits+$miss),1):0).'%', 'Intrări'=>(string)(isset($stats['entries'])?$stats['entries']:0),'Cache privat'=>round((isset($stats['bytes'])?$stats['bytes']:0)/1048576,2).' MB'),
            'health'=>array('LiteSpeed'=>\FurMedia\Cache\Litespeed::diagnostic($s,$this->request->server),'HIT SQL'=>(string)(isset($stats['sql_hit'])?$stats['sql_hit']:0),'HIT componente'=>(string)(isset($stats['component_hit'])?$stats['component_hit']:0),'Backend'=>isset($store)?$store->backend():'Indisponibil','OpenCart'=>VERSION,'PHP'=>PHP_VERSION,'Journal'=>defined('JOURNAL3_VERSION')?JOURNAL3_VERSION:($this->config->get('config_theme')?:'Verifică tema magazinului'), 'GD / WebP'=>(extension_loaded('gd')?'GD disponibil':'GD absent') . ' / ' . (function_exists('imagewebp')?'WebP disponibil':'WebP absent'), 'AVIF'=>function_exists('imageavif')?'Disponibil':'Indisponibil — se păstrează originalul', 'Cache privat'=>is_writable(DIR_CACHE)?'Director accesibil':'Director inaccesibil','Spațiu liber filesystem'=>isset($stats['free_bytes'])?round($stats['free_bytes']/1048576).' MB (cota hosting poate fi mai mică)':'Necunoscut','Arhitectură'=>'Cache cu politici explicite; evenimente native; fără modificări core'));
        $header=$this->load->controller('common/header'); $left=$this->load->controller('common/column_left'); $footer=$this->load->controller('common/footer');
        $this->response->setOutput($header . $left . \FurMedia\Cache\Admin::render($data) . $footer);
    }
    public function install() {
        if (!$this->user->hasPermission('modify',self::FM_ROUTE) && !$this->user->hasPermission('modify','marketplace/extension') && !$this->user->hasPermission('modify','extension/extension') && !$this->user->hasPermission('modify','extension/module')) { return; }
        $this->fmEvents(false); $this->fmEvents(true);
        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(),'access',self::FM_ROUTE);
        $this->model_user_user_group->addPermission($this->user->getGroupId(),'modify',self::FM_ROUTE);
    }
    public function uninstall() {
        if (!$this->user->hasPermission('modify',self::FM_ROUTE) && !$this->user->hasPermission('modify','marketplace/extension') && !$this->user->hasPermission('modify','extension/extension')) { return; }
        $this->fmEvents(false);
        $this->load->model('setting/setting'); $this->model_setting_setting->deleteSetting('module_furmedia_cache');
        $this->load->model('setting/store');foreach($this->model_setting_store->getStores() as $row){$this->model_setting_setting->deleteSetting('module_furmedia_cache',(int)$row['store_id']);}
        try { (new \FurMedia\Cache\FileStore(DIR_CACHE . 'furmedia_cache'))->purge();$this->response->addHeader(\FurMedia\Cache\Litespeed::purge()); } catch (\Exception $e) { }
    }
    public function profile(&$route,&$args) {
        if($this->registry->has('skynova_admin_sql_profile')){return;}$this->registry->set('skynova_admin_sql_profile',new \stdClass());
        try{$this->load->model('setting/setting');$saved=$this->model_setting_setting->getSetting('module_furmedia_cache',0);$settings=\FurMedia\Cache\Settings::normalize(isset($saved['module_furmedia_cache_settings'])?$saved['module_furmedia_cache_settings']:array());if($settings['search_acceleration']&&\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){$root=DIR_CACHE.'furmedia_cache-search';$this->registry->set('db',new \FurMedia\Cache\Searchquery($this->db,new \FurMedia\Cache\SearchIndex($this->db,DB_PREFIX,$root,DIR_CACHE.'furmedia_cache-db-archives',$settings['db_archive_mb']),DB_PREFIX,$root));}if($settings['sql_profile']&&\FurMedia\Cache\Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){$this->registry->set('db',new \FurMedia\Cache\Sqlprofile($this->db,DIR_CACHE.'furmedia_cache-sqlprofile-0',$settings['sql_slow_ms'],'admin'));}}catch(\Exception $e){}
    }
    public function invalidate(&$route, &$args, &$output = null) {
        try {
            if (preg_match('~(?:/|\.)(?:add|edit|delete|approve|update|clear|save)[A-Za-z_]*$~',$route)) { if(is_dir(DIR_CACHE.'furmedia_cache-search')){\FurMedia\Cache\SearchIndex::invalidate(DIR_CACHE.'furmedia_cache-search');}(new \FurMedia\Cache\FileStore(DIR_CACHE . 'furmedia_cache'))->purge(); }
        } catch (\Exception $e) { $this->log->write('FurMedia Cache: invalidation failed'); }
    }
    private function fmEvents($install) {
        $legacy = version_compare(VERSION,'3.0.0.0','<'); $v4 = version_compare(VERSION,'4.0.0.0','>=');
        $model = $legacy ? 'extension/event' : 'setting/event';
        $this->load->model($model); $property='model_' . str_replace('/','_',$model); $events=$this->{$property};
        if (!$install) { if ($legacy) { $events->deleteEvent('furmedia_cache'); } else { $events->deleteEventByCode('furmedia_cache'); } return; }
        $separator = $v4 ? '.' : '/';
        $list = array('admin/controller/*/before'=>self::FM_ROUTE.$separator.'profile','catalog/controller/*/before'=>self::FM_ROUTE . $separator . 'before', 'catalog/controller/*/after'=>self::FM_ROUTE . $separator . 'after', 'admin/model/catalog/*/after'=>self::FM_ROUTE . $separator . 'invalidate', 'admin/model/setting/*/after'=>self::FM_ROUTE . $separator . 'invalidate', 'admin/model/design/*/after'=>self::FM_ROUTE . $separator . 'invalidate', 'admin/model/journal3/*/after'=>self::FM_ROUTE . $separator . 'invalidate', 'catalog/model/checkout/order/*/after'=>self::FM_ROUTE . $separator . 'invalidate', 'catalog/model/checkout/order.*/after'=>self::FM_ROUTE . $separator . 'invalidate');
        if (!$v4) { $list['catalog/model/catalog/*/before']=self::FM_ROUTE . '/modelBefore'; $list['catalog/model/catalog/*/after']=self::FM_ROUTE . '/modelAfter'; }
        foreach ($list as $trigger=>$action) {
            if ($v4) { $events->addEvent(array('code'=>'furmedia_cache','description'=>'SkyNova Cache Suite','trigger'=>$trigger,'action'=>$action,'status'=>1,'sort_order'=>10000)); }
            else { $events->addEvent('furmedia_cache',$trigger,$action,1,10000); }
        }
    }
}
