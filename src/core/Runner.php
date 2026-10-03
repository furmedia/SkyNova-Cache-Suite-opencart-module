<?php
namespace FurMedia\Cache;
class Runner {
    public function run(array $config,$action) {
        if (empty($config['cache_directory'])) { throw new \InvalidArgumentException('Cache directory required'); }
        $store=new FileStore($config['cache_directory']);
        if ($action==='gc') { return array('expired_removed'=>$store->gc()); }
        if ($action==='purge') { return array('removed'=>$store->purge()); }
        if ($action==='batch') {
            if(empty($config['batch_directory']) || empty($config['store_root']) || empty($config['origin']) || !isset($config['settings']) || !is_array($config['settings'])){throw new \InvalidArgumentException('Export the complete runner configuration for batch jobs');}
            return (new Batch($config['batch_directory'],$config['store_root'],$config['origin'],Settings::normalize($config['settings'])))->step(isset($config['limit'])?$config['limit']:10);
        }
        if ($action!=='warm') { throw new \InvalidArgumentException('Unknown action'); }
        $queue=new WarmQueue($config['cache_directory'].'-jobs-'.hash('sha256',isset($config['origin'])?$config['origin']:''),isset($config['origin'])?$config['origin']:'');
        $result=$queue->run($config);
        (new History($config['cache_directory'].'-history'))->append('warm',$result);
        return $result;
    }
}
