<?php
namespace FurMedia\Cache;
/** Exact native route policies. They can narrow admission but never override sensitive-route guards. */
class PageRules {
    public static function parse($json) {
        if(trim((string)$json)===''){return array();}
        $rows=json_decode($json,true);
        if(!is_array($rows) || count($rows)>100){throw new \InvalidArgumentException('Page rules require a JSON object with at most 100 routes');}
        $out=array();
        foreach($rows as $route=>$row){
            if(!is_string($route) || !preg_match('~^[a-z0-9_]+(?:/[a-z0-9_]+)*$~D',$route) || !is_array($row)){throw new \InvalidArgumentException('Invalid page rule route');}
            foreach($row as $key=>$value){if(!in_array($key,array('ttl','enabled','logged','cart'),true) || !is_scalar($value)){throw new \InvalidArgumentException('Invalid page rule option');}}
            foreach(array('enabled','logged','cart') as $key){if(isset($row[$key]) && !in_array($row[$key],array(true,false,0,1,'0','1'),true)){throw new \InvalidArgumentException('Page rule flags require true/false or 0/1');}}
            if(isset($row['ttl']) && !is_numeric($row['ttl'])){throw new \InvalidArgumentException('Page rule TTL requires a number');}
            $out[$route]=array('ttl'=>isset($row['ttl'])?max(10,min(86400,(int)$row['ttl'])):null,'enabled'=>!isset($row['enabled']) || (bool)$row['enabled'],'logged'=>!empty($row['logged']),'cart'=>!empty($row['cart']));
        }
        return $out;
    }
    public static function route(array $settings,$route) {
        $rules=self::parse(isset($settings['page_rules'])?$settings['page_rules']:'');
        return isset($rules[$route])?$rules[$route]:array('ttl'=>null,'enabled'=>true,'logged'=>false,'cart'=>false);
    }
    public static function ttl(array $settings,$route) { $rule=self::route($settings,$route);return $rule['ttl']===null?$settings['ttl']:$rule['ttl']; }
    public static function matches($uri,$patterns) {
        $parsed=parse_url($uri);$path=$parsed && isset($parsed['path'])?$parsed['path']:'/';
        foreach(Settings::lines($patterns) as $pattern){
            if(substr($pattern,-1)==='*'){if(strpos($path,substr($pattern,0,-1))===0){return true;}}
            elseif($path===$pattern){return true;}
        }
        return false;
    }
    public static function validatePaths($patterns) {
        foreach(Settings::lines($patterns) as $pattern){if(strlen($pattern)>512 || !preg_match('#^/(?:[a-zA-Z0-9_./%~-]*)(?:\*)?$#D',$pattern) || strpos(rawurldecode($pattern),'..')!==false){throw new \InvalidArgumentException('URL rules require exact paths or a final * prefix wildcard');}}
    }
}
