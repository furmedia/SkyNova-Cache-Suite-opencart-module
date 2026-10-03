<?php
namespace FurMedia\Cache;
/** Native CRON scheduler: local time slots, bounded work, persistent pause/retry/history. */
class Maintenance {
    private $db;private $prefix;private $root;private $store;private $archive;
    public function __construct($db,$prefix,$root,$archiveRoot,$maxMb=2048){$this->db=$db;$this->prefix=$prefix;$this->root=$root;$this->store=new FileStore($root,array('max_entry_kb'=>1024));$this->archive=new Dbarchive($db,$prefix,$archiveRoot,$maxMb);}
    public static function parse($text) {
        if(trim($text)===''){return array();}$rows=json_decode($text,true);if(!is_array($rows)||count($rows)>20){throw new \InvalidArgumentException('At most 20 maintenance schedules');}$ids=array();
        foreach($rows as $r){if(!is_array($r)||!isset($r['id'],$r['action'],$r['tables'],$r['at'],$r['timezone'],$r['days'])||!is_string($r['id'])||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$r['id'])||isset($ids[$r['id']])||!in_array($r['action'],array('ANALYZE','OPTIMIZE','backup'),true)||!is_array($r['tables'])||!$r['tables']||count($r['tables'])>5||!is_string($r['at'])||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$r['at'])||!is_string($r['timezone'])||!in_array($r['timezone'],\DateTimeZone::listIdentifiers(),true)||!is_array($r['days'])||!$r['days']||count($r['days'])>7){throw new \InvalidArgumentException('Invalid maintenance schedule');}
            foreach($r['tables'] as $table){if(!is_string($table)||!preg_match('/^[a-zA-Z0-9_]+$/D',$table)){throw new \InvalidArgumentException('Invalid scheduled table');}}
            foreach($r['days'] as $day){if(!is_int($day)||$day<1||$day>7){throw new \InvalidArgumentException('Weekdays must be integers 1–7');}}
            if(isset($r['enabled'])&&!in_array($r['enabled'],array(0,1,false,true),true)){throw new \InvalidArgumentException('Invalid schedule flag');}$ids[$r['id']]=true;
        }return $rows;
    }
    public function status(){return array('paused'=>(bool)$this->store->get('paused'),'jobs'=>$this->store->get('jobs')?:array(),'history'=>(new History($this->root.'/history'))->rows('maintenance'));}
    public function control($pause,$retry=false){$lock=$this->store->lease('maintenance');if(!$lock){throw new \RuntimeException('Maintenance busy');}try{if(!$this->store->set('paused',(bool)$pause,31536000)){throw new \RuntimeException('Cannot persist maintenance pause');}if($retry){$jobs=$this->store->get('jobs')?:array();foreach($jobs as &$job){if($job['status']==='failed'){$job['status']='run';$job['attempts']=0;$job['retry_at']=0;}}unset($job);if(!$this->store->set('jobs',$jobs,31536000)){throw new \RuntimeException('Cannot persist maintenance progress');}}return $this->status();}finally{flock($lock,LOCK_UN);fclose($lock);}}
    public static function slot(array $r,$now){$date=new \DateTime('@'.(int)$now,new \DateTimeZone('UTC'));$date->setTimezone(new \DateTimeZone($r['timezone']));if((isset($r['enabled'])&&!$r['enabled'])||!in_array((int)$date->format('N'),$r['days'],true)||$date->format('H:i')<$r['at']){return null;}return $date->format('Y-m-d');}
    public function tick($text,$limit=1,$now=null) {
        $rules=self::parse($text);$now=$now===null?time():(int)$now;$limit=max(1,min(5,(int)$limit));$lock=$this->store->lease('maintenance');if(!$lock){return array('busy'=>true);}
        try{if($this->store->get('paused')){return array('paused'=>true);}$jobs=$this->store->get('jobs')?:array();$result=array();
            foreach($rules as $r){if(count($result)>=$limit){break;}$slot=self::slot($r,$now);if(!$slot){continue;}$fingerprint=hash('sha256',json_encode($r));$id=$r['id'];
                if(!isset($jobs[$id])||($jobs[$id]['slot']!==$slot&&$jobs[$id]['status']!=='run')||$jobs[$id]['fingerprint']!==$fingerprint){$jobs[$id]=array('slot'=>$slot,'fingerprint'=>$fingerprint,'status'=>'run','cursor'=>0,'attempts'=>0,'retry_at'=>0,'archive'=>null);}
                $job=&$jobs[$id];if($job['status']!=='run'||$job['retry_at']>$now){unset($job);continue;}
                try{$table=$r['tables'][$job['cursor']];if($r['action']==='backup'){
                        if(!$job['archive']){$job['archive']=$this->archive->start($table)['id'];if(!$this->store->set('jobs',$jobs,31536000)){throw new \RuntimeException('Cannot persist maintenance progress');}}
                        $step=$this->archive->step($job['archive']);$result[$id]=$step;if($step['phase']==='ready'){$job['cursor']++;$job['archive']=null;}
                    }else{$result[$id]=(new Dbtools($this->db,$this->prefix,$this->root.'/legacy'))->maintain($r['action'],array($table));$job['cursor']++;}
                    $job['attempts']=0;$job['retry_at']=0;if($job['cursor']===count($r['tables'])){$job['status']='done';$job['slot']=$slot;}
                    (new History($this->root.'/history'))->append('maintenance',array('id'=>$id,'slot'=>$slot,'action'=>$r['action'],'table'=>$table,'status'=>$job['status']));
                }catch(\Exception $e){$job['attempts']++;$job['retry_at']=$now+3600;if($job['attempts']>=3){$job['status']='failed';}$result[$id]=array('status'=>$job['status'],'attempts'=>$job['attempts']);(new History($this->root.'/history'))->append('maintenance',array('id'=>$id,'status'=>$job['status'],'attempts'=>$job['attempts']));}
                unset($job);if(!$this->store->set('jobs',$jobs,31536000)){throw new \RuntimeException('Cannot persist maintenance progress');}
            }return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
