<?php
namespace FurMedia\Cache;
/** Original implementation of the documented LiteSpeed response protocol. */
class Litespeed {
    public static function device($userAgent) {
        if(preg_match('/bot|crawler|spider|slurp/i',$userAgent)){return 'bot';}
        $mobile=preg_match('/Mobile|Android|iPhone|iPad|Tablet/i',$userAgent)?'mobile':'desktop';
        $safari=stripos($userAgent,'Safari')!==false && !preg_match('/Chrome|Chromium|CriOS|Edg|OPR|Android/i',$userAgent);
        return $mobile.($safari?'-safari':'');
    }
    public static function available(array $server) {return !empty($server['LSCACHE_ON']) || (isset($server['SERVER_SOFTWARE']) && stripos($server['SERVER_SOFTWARE'],'LiteSpeed')!==false);}
    public static function ready(array $settings,array $server) {return !empty($settings['status']) && isset($settings['mode']) && $settings['mode']!=='observe' && !empty($settings['litespeed']) && self::available($server) && getenv('SKYNOVA_LSCACHE_PROFILE')==='private-v1';}
    public static function tag($tag) {return 'sn_'.substr(hash('sha256',(string)$tag),0,32);}
    public static function headers($ttl,array $tags,$sessionName,$esi=false) {
        if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$sessionName)){throw new \InvalidArgumentException('Invalid native session cookie name');}
        $encoded=array();foreach(array_slice($tags,0,100) as $tag){$encoded[]='public:'.self::tag($tag);}
        return array('X-LiteSpeed-Cache-Control: private,max-age='.max(10,min(86400,(int)$ttl)).($esi?',esi=on':''),'X-LiteSpeed-Tag: '.implode(',',$encoded),'X-LiteSpeed-Vary: cookie='.$sessionName.',cookie=skynova_state,cookie=language,cookie=currency');
    }
    public static function purge($tag=null) {return 'X-LiteSpeed-Purge: public,tag='.self::tag($tag===null?'catalog':$tag).';private,*';}
    public static function esi($url,$scope,$ttl,$tag,$fallback='') {
        if(!in_array($scope,array('public','private','no-cache'),true) || !is_string($url) || strlen($url)>2048 || substr($url,0,1)!=='/' || substr($url,0,2)==='//' || preg_match('/[\x00-\x20\x7f]/',$url)){throw new \InvalidArgumentException('Invalid ESI include');}
        return '<esi:include src="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" cache-control="'.$scope.',max-age='.max(0,min(3600,(int)$ttl)).'" cache-tag="'.self::tag($tag).'"/><esi:remove>'.$fallback.'</esi:remove>';
    }
    public static function diagnostic(array $settings,array $server) {
        if(empty($settings['litespeed'])){return 'Disabled';}if(!self::available($server)){return 'LiteSpeed server not detected; portable cache remains active';}
        return self::ready($settings,$server)?'private-v1 configured; real server HIT verification required':'Server detected; configure the private-v1 request profile before native caching';
    }
    public static function profile($sessionName) {
        if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$sessionName)){throw new \InvalidArgumentException('Invalid session cookie name');}
        return "# SkyNova private-v1 profile checklist; review with the hosting administrator.\n".
            "# Configure private cache lookup, and vary at request lookup on: ".$sessionName.", skynova_state, language, currency.\n".
            "# Vary by the complete User-Agent and Accept headers, or by audited matching device groups.\n".
            "# Never lookup cached pages for non-GET, Authorization, AJAX, Range, unknown cookies/query parameters,\n".
            "# or account/checkout/api/admin/Journal endpoints. Always execute mutation requests in PHP.\n".
            "# Enable private-cache autoflush for POST. Test logout, cart mutations, currency/language changes,\n".
            "# catalog edits and purge of public-tagged private objects before setting SKYNOVA_LSCACHE_PROFILE=private-v1.\n".
            "# For Enterprise ESI, additionally verify subrequest cookies and set SKYNOVA_LSCACHE_ESI=1.\n".
            "# Response headers alone do not implement these request lookup rules. Do not auto-install this checklist.\n".
            "# Protocol documentation: https://docs.litespeedtech.com/lscache/devguide/controls/\n";
    }
}
