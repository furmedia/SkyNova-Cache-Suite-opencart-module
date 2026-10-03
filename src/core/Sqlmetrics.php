<?php
namespace FurMedia\Cache;
/** Bounded digests: aggregates survive requests without persisting literal SQL. */
class Sqlmetrics {
    public static function append($root,array $samples){$store=new FileStore($root,array('max_entry_kb'=>1024));$lock=$store->lease('metrics');if(!$lock){return false;}
        try{$rows=$store->get('summary')?:array();foreach($samples as $sample){$id=$sample['area'].':'.$sample['digest'];if(!isset($rows[$id])){$rows[$id]=array('digest'=>$sample['digest'],'area'=>$sample['area'],'shape'=>substr($sample['shape'],0,2048),'count'=>0,'total_ms'=>0,'min_ms'=>$sample['ms'],'max_ms'=>0,'failed'=>0,'last'=>time());}
                $r=&$rows[$id];$r['count']++;$r['total_ms']+=$sample['ms'];$r['min_ms']=min($r['min_ms'],$sample['ms']);$r['max_ms']=max($r['max_ms'],$sample['ms']);$r['failed']+=empty($sample['ok'])?1:0;$r['last']=time();unset($r);
            }uasort($rows,function($a,$b){return $b['last']-$a['last'];});return $store->set('summary',array_slice($rows,0,256,true),2592000);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function rows($root){$rows=(new FileStore($root,array('max_entry_kb'=>1024)))->get('summary')?:array();foreach($rows as &$r){$r['avg_ms']=round($r['total_ms']/max(1,$r['count']),3);$r['total_ms']=round($r['total_ms'],3);}unset($r);uasort($rows,function($a,$b){return $a['total_ms']===$b['total_ms']?0:($a['total_ms']>$b['total_ms']?-1:1);});return array_values($rows);}
}
