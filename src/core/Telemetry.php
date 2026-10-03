<?php
namespace FurMedia\Cache;
/** Aggregate guest page views only. No IP, URL/query, cookies or visitor identifiers. */
class Telemetry {
    public static function hit($root,$storeId,$route,$timezone='UTC',$now=null){if(!preg_match('~^[a-z0-9_/]+$~D',$route)){return;}$store=new FileStore($root,array('max_entry_kb'=>1024));$lock=$store->lease('views');if(!$lock){return;}
        try{$date=new \DateTime('@'.($now===null?time():(int)$now),new \DateTimeZone('UTC'));$date->setTimezone(new \DateTimeZone($timezone));$day=$date->format('Y-m-d');$rows=$store->get('views')?:array();$cutoff=clone $date;$cutoff->modify('-30 days');foreach($rows as $oldKey=>$old){if($old['day']<$cutoff->format('Y-m-d')){unset($rows[$oldKey]);}}$key=(int)$storeId.':'.$day;if(!isset($rows[$key])){$rows[$key]=array('day'=>$day,'store'=>(int)$storeId,'total'=>0,'routes'=>array());}$r=&$rows[$key];$r['total']++;if(isset($r['routes'][$route])||count($r['routes'])<100){$r['routes'][$route]=isset($r['routes'][$route])?$r['routes'][$route]+1:1;}unset($r);ksort($rows);$store->set('views',array_slice($rows,-310,true),2678400);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function rows($root,$storeId){$rows=(new FileStore($root,array('max_entry_kb'=>1024)))->get('views')?:array();return array_values(array_filter($rows,function($row)use($storeId){return $row['store']===(int)$storeId;}));}
}
