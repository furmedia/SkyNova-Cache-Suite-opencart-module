<?php
namespace FurMedia\Cache;
/** Trusted hosting integrations register factories; admin never supplies executable class names. */
class Drivers {
    private static $custom=array();private static $loaded=false;
    public static function register($id,$factory,$label){if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_]{2,31}$/D',$id)||isset(self::choices()[$id])||!is_callable($factory)||!is_string($label)||strlen($label)>80){throw new \InvalidArgumentException('Invalid custom cache driver');}self::$custom[$id]=array('factory'=>$factory,'label'=>$label);}
    public static function choices(){ $out=array('file'=>'Disc','redis'=>'Redis + fallback disc','memcached'=>'Memcached + fallback disc','apcu'=>'APCu + fallback disc','memcache'=>'Memcache + fallback disc');foreach(self::$custom as $id=>$row){$out[$id]=$row['label'].' + fallback disc';}return $out;}
    public static function create($id){if(!isset(self::$custom[$id])){throw new \RuntimeException('Custom driver unavailable');}$client=call_user_func(self::$custom[$id]['factory']);if(!is_object($client)||!is_callable(array($client,'get'))||!is_callable(array($client,'set'))){throw new \RuntimeException('Driver requires get(key) and set(key, value, ttl)');}return $client;}
    public static function load(){if(self::$loaded){return;}self::$loaded=true;if(!defined('DIR_STORAGE')||!defined('DIR_CACHE')||!defined('DIR_APPLICATION')||!Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){return;}$file=DIR_STORAGE.'skynova-cache-drivers.php';if(is_file($file)&&!is_link($file)){require_once $file;}}
}
