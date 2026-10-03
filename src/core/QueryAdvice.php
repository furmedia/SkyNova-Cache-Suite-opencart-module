<?php
namespace FurMedia\Cache;
class QueryAdvice {
    private $db;private $prefix;private $root;
    public function __construct($db,$prefix,$root){$this->db=$db;$this->prefix=$prefix;$this->root=$root;}
    public function recommend(array $metrics){$schema=(new Dbindexes($this->db,$this->prefix))->inspect();$out=array();foreach($schema as $id=>$index){if($index['covered']||$index['conflict']){continue;}$count=0;$ms=0;$digests=array();foreach($metrics as $sample){if(!empty($sample['failed'])||!preg_match('/\b'.preg_quote($index['table'],'/').'\b/i',$sample['shape'])){continue;}$matched=true;foreach($index['columns'] as $column){if(!preg_match('/\b'.preg_quote($column,'/').'\b/i',$sample['shape'])){$matched=false;}}if($matched){$count+=$sample['count'];$ms+=$sample['total_ms'];$digests[]=$sample['digest'];}}if($count){$out[$id]=array('index'=>$index,'observed_queries'=>$count,'total_ms'=>round($ms,3),'digests'=>array_slice(array_unique($digests),0,10),'reason'=>'Candidate columns observed in measured queries; confirm EXPLAIN before applying');}}
        uasort($out,function($a,$b){return $a['total_ms']===$b['total_ms']?0:($a['total_ms']>$b['total_ms']?-1:1);});return $out;
    }
    public function measure($sql,$benchmark=false){
        $plan=(new Dbtools($this->db,$this->prefix,$this->root.'/legacy'))->explain($sql);$result=array('plan'=>$plan,'measured_at'=>gmdate('c'));
        if($benchmark){if(!preg_match('/\bLIMIT\s+([1-9][0-9]*)\s*$/i',$sql,$m)||(int)$m[1]>100){throw new \InvalidArgumentException('Benchmark requires a final LIMIT 1–100');}$times=array();for($i=0;$i<3;$i++){$start=microtime(true);$this->db->query($sql);$times[]=(microtime(true)-$start)*1000;}$result['avg_ms']=round(array_sum($times)/3,3);$result['samples_ms']=array_map(function($v){return round($v,3);},$times);}
        $key=(new Vault($this->root.'/vault'))->sign($sql);$store=new FileStore($this->root.'/plans',array('max_entry_kb'=>1024));$previous=$store->get($key);$store->set($key,$result,2592000,array('plan'));
        (new History($this->root.'/history'))->append('queryplan',array('digest'=>hash('sha256',Sqlprofile::shape($sql)),'result'=>$result));$result['previous']=$previous;return $result;
    }
}
