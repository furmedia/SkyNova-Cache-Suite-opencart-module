<?php
namespace FurMedia\Cache;

class Settings {
    public static function defaults() {
        return array(
            'db_archive_mb'=>2048, 'db_schedules'=>'', 'db_schedule_limit'=>1, 'search_acceleration'=>0, 'view_stats'=>0, 'pwa_assets'=>0, 'pwa_limit'=>100, 'pwa_cache_names'=>'', 'conditions'=>'', 'sql_profile'=>0, 'sql_slow_ms'=>100, 'lazy_media'=>0, 'lazy_blocks'=>'', 'custom_css'=>'', 'custom_js'=>'', 'custom_position'=>'bottom', 'replacements'=>'', 'resource_attributes'=>'', 'delay_rules'=>'', 'external_assets'=>'', 'db_auto_analyze'=>0, 'db_analyze_tables'=>'', 'module_rules'=>'', 'cache_panel'=>0, 'panel_position'=>'right', 'panel_color'=>'#152238', 'panel_text'=>'#ffffff', 'panel_font'=>13, 'timezone'=>'UTC', 'browser_images'=>31536000, 'browser_css'=>2592000, 'browser_js'=>2592000, 'browser_video'=>2592000, 'browser_pdf'=>86400, 'browser_fonts'=>31536000, 'prefetch_urls'=>'', 'preload_urls'=>'', 'compress_xml'=>0, 'compress_xhtml'=>0, 'compress_rss'=>0, 'reserve_compressed'=>0, 'warm_login'=>0, 'dynamic_widgets'=>0, 'widgets_interval'=>60, 'device_vary'=>0, 'litespeed'=>0, 'litespeed_esi'=>0, 'esi_modules'=>'', 'cache_404'=>0, 'ttl_404'=>60, 'status' => 0, 'mode' => 'observe', 'shared_routes'=>'', 'page_rules'=>'', 'include_urls'=>'', 'exclude_urls'=>'', 'logged_exclude_urls'=>'', 'backend'=>'file', 'ttl' => 300, 'max_mb' => 64,
            'reserve_mb' => 128, 'max_entry_kb' => 1024, 'max_entries' => 2000, 'journal' => 1, 'journal_shared'=>0, 'journal_filter_ids'=>'', 'journal_private_routes'=>'',
            'html_minify' => 0, 'lazy_images' => 0, 'lazy_iframes' => 0,
            'image_dimensions' => 0, 'webp' => 0, 'avif' => 0, 'quality' => 82,
            'css_minify' => 0, 'js_minify' => 0, 'extract_js'=>0, 'script_position'=>'native', 'script_allow'=>'', 'defer_js' => 0, 'delay_js'=>0, 'delay_allow'=>'',
            'responsive_images'=>0, 'image_sizes'=>'(max-width: 768px) 100vw, 50vw',
            'gzip' => 0, 'cdn' => '', 'critical_css' => '',
            'routes' => "common/home\nproduct/product\nproduct/category\nproduct/manufacturer/info\nproduct/special\ninformation/information",
            'exclude_paths' => "checkout\naccount\napi/\njournal3/\njournal2/",
            'exclude_assets' => "journal\njquery\nbootstrap\ncheckout\ncaptcha\nconsent\npayment",
            'defer_allow' => '', 'preload_image' => '', 'preconnect' => '',
            'dynamic_cache'=>0, 'dynamic_urls'=>'', 'dynamic_ttl'=>300, 'component_cache'=>0, 'component_allow'=>'', 'component_ttl'=>60, 'sql_cache'=>0, 'sql_allow'=>'', 'sql_ttl'=>30, 'hide_category_count'=>0, 'css_merge'=>0, 'js_merge'=>0, 'merge_allow'=>'',
            'model_cache' => 0, 'model_allow' => '', 'model_ttl' => 60,
            'debug_details'=>0, 'debug_session_hash'=>'', 'debug' => 1, 'warm_urls' => '', 'warm_sitemaps'=>'', 'warm_variants'=>'', 'warm_interval'=>3600, 'warm_limit' => 10
        );
    }

    public static function normalize($input) {
        $out = self::defaults();
        foreach ($out as $key => $default) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) { continue; }
            if (is_int($default)) { $out[$key] = (int)$input[$key]; }
            else { $out[$key] = trim((string)$input[$key]); }
        }
        foreach (array('search_acceleration','view_stats','pwa_assets','sql_profile','lazy_media','db_auto_analyze','cache_panel','compress_xml','compress_xhtml','compress_rss','reserve_compressed','warm_login','debug_details','dynamic_widgets','device_vary','litespeed','litespeed_esi','cache_404','dynamic_cache','extract_js','component_cache','sql_cache','hide_category_count','css_merge','js_merge','status','journal','journal_shared','html_minify','lazy_images','lazy_iframes','image_dimensions','webp','avif','css_minify','js_minify','defer_js','delay_js','responsive_images','gzip','model_cache','debug') as $key) {
            $out[$key] = (int)(bool)$out[$key];
        }
        foreach (array('widgets_interval'=>array(10,300),'ttl_404'=>array(10,3600),'dynamic_ttl'=>array(10,86400),'component_ttl'=>array(5,3600),'sql_ttl'=>array(5,300),'ttl'=>array(10,86400),'model_ttl'=>array(5,3600),'max_mb'=>array(8,4096),'max_entries'=>array(100,20000),'reserve_mb'=>array(32,4096),'max_entry_kb'=>array(64,4096),'quality'=>array(0,100),'warm_limit'=>array(1,50)) as $key=>$range) {
            $out[$key] = max($range[0], min($range[1], $out[$key]));
        }
        if (!in_array($out['mode'], array('observe','session','shared'), true)) { $out['mode'] = 'observe'; }
        if(count(self::lines($out['journal_filter_ids']))>20){throw new \InvalidArgumentException('Approve at most twenty Journal filter instances');}
        foreach(self::lines($out['journal_filter_ids']) as $id){if(!preg_match('/^[1-9][0-9]{0,5}$/D',$id)){throw new \InvalidArgumentException('Journal filter IDs must be positive numeric IDs');}}
        foreach(self::lines($out['journal_private_routes']) as $route){if(!in_array($route,array('common/home','product/category','product/product','product/manufacturer','product/manufacturer/info','information/information'),true)){throw new \InvalidArgumentException('Private Journal fragments require an approved native catalog route');}}
        Drivers::load(); if (!array_key_exists($out['backend'],Drivers::choices())) { $out['backend']='file'; }
        if(!in_array($out['script_position'],array('native','top','bottom'),true)){$out['script_position']='native';}
        $out['warm_interval']=max(60,min(86400,$out['warm_interval']));
        foreach ($out as $key=>$value) { if (is_string($value)) { $out[$key] = (string)substr($value, 0, $key==='critical_css'?262144:16384); } }
        if ($out['cdn'] && !preg_match('~^https://[a-z0-9.-]+(?::443)?(?:/[a-z0-9/_-]*)?$~iD', $out['cdn'])) { throw new \InvalidArgumentException('CDN must be a HTTPS URL without query or credentials.'); }
        if (stripos($out['critical_css'], '</') !== false || preg_match('/[<>]/', $out['critical_css'])) { throw new \InvalidArgumentException('Critical CSS must contain CSS only.'); }
        if($out['debug_session_hash']!=='' && !preg_match('/^[a-f0-9]{64}$/iD',$out['debug_session_hash'])){throw new \InvalidArgumentException('Diagnostic session selector requires a SHA256 digest');}
        $out['debug_session_hash']=strtolower($out['debug_session_hash']);
        if(count(self::lines($out['db_analyze_tables']))>5){throw new \InvalidArgumentException('Schedule at most five tables');}foreach(self::lines($out['db_analyze_tables']) as $table){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table)){throw new \InvalidArgumentException('Invalid scheduled table');}}Conditions::parse($out['conditions']);
        Maintenance::parse($out['db_schedules']); Pwa::names($out['pwa_cache_names']);
        $out['db_archive_mb']=max(64,min(32768,$out['db_archive_mb'])); $out['db_schedule_limit']=max(1,min(5,$out['db_schedule_limit'])); $out['pwa_limit']=max(20,min(500,$out['pwa_limit']));
        Advanced::validate($out);
        $out['sql_slow_ms']=max(0,min(5000,$out['sql_slow_ms']));
        ModuleRules::parse($out['module_rules']);
        if(!in_array($out['panel_position'],array('left','right'),true)){$out['panel_position']='right';}
        foreach(array('panel_color','panel_text') as $color){if(!preg_match('/^#[a-f0-9]{6}$/iD',$out[$color])){throw new \InvalidArgumentException('Panel colors require #RRGGBB');}}
        $out['panel_font']=max(10,min(24,$out['panel_font']));
        if(!in_array($out['timezone'],\DateTimeZone::listIdentifiers(),true)&&$out['timezone']!=='UTC'){throw new \InvalidArgumentException('Invalid display timezone');}
        foreach(array('images','css','js','video','pdf','fonts') as $kind){$out['browser_'.$kind]=max(0,min(31536000,$out['browser_'.$kind]));}
        Delivery::urls($out['prefetch_urls']);
        foreach(Settings::lines($out['preload_urls']) as $line){$parts=explode('|',$line,2);Delivery::urls($parts[0]);if(count($parts)!==2||!in_array($parts[1],array('font','style','script','image','fetch'),true)){throw new \InvalidArgumentException('Preload format URL|font/style/script/image/fetch');}}
        Resources::validate($out['dynamic_urls']);
        Esi::parse($out['esi_modules']);
        PageRules::parse($out['page_rules']);
        WarmVariants::parse($out['warm_variants']);
        foreach(array('include_urls','exclude_urls','logged_exclude_urls') as $field){PageRules::validatePaths($out[$field]);}
        return $out;
    }

    public static function lines($text) {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$text)), 'strlen'));
    }
}
