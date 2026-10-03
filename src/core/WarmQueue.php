<?php
namespace FurMedia\Cache;

/** Persistent bounded sitemap queue. Only same-origin public HTTPS URLs are admitted. */
class WarmQueue {
    private $store;
    private $fetch;
    private $clock;
    private $origin;
    private $variants=array();
    public function __construct($directory,$origin,$fetch=null,$clock=null) {
        $this->origin=rtrim($origin,'/');
        if (!preg_match('~^https://[a-z0-9.-]+(?:/[a-z0-9/_-]*)?$~iD',$this->origin)) { throw new \InvalidArgumentException('Public HTTPS origin required'); }
        $this->store=new FileStore($directory,array('max_entry_kb'=>4096,'max_mb'=>16));
        $this->fetch=$fetch ?: function($url,$headers=array()) { return (new HttpClient())->request('GET',$url,'',$headers,2097152,10); };
        $this->clock=$clock ?: function() { return time(); };
    }
    public function url($input) {
        if (!is_string($input) || strlen($input)>2048 || preg_match('~[\\\\\x00-\x20\x7f]~',$input)) { return false; }
        $p=parse_url($input);$base=parse_url($this->origin);
        if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['port'])) { return false; }
        if (isset($p['host'])) {
            if (!isset($p['scheme']) || $p['scheme']!=='https' || strtolower($p['host'])!==strtolower($base['host'])) { return false; }
            $url=$input;
        } else {
            if (isset($p['scheme']) || substr($input,0,1)!=='/' || substr($input,0,2)==='//') { return false; }
            $url=$this->origin.$input;
        }
        $path=parse_url($url,PHP_URL_PATH);
        $scope=isset($base['path'])?rtrim($base['path'],'/'):'';
        if ($scope && $path!==$scope && strpos($path,$scope.'/')!==0) { return false; }
        $decoded=rawurldecode(rawurldecode($url));
        if (preg_match('~(?:\.\.|checkout|account|admin|api[/=&]|token|logout|cart|[\\\\\x00-\x20\x7f])~i',$decoded)) { return false; }
        return $url;
    }
    private function add(&$state,$url,$type,$now) {
        $url=$this->url($url);
        if (!$url || count($state['jobs'])>=5000) { return; }
        foreach($type==='page'?$this->variants:array(array('headers'=>array())) as $variant){
            if(count($state['jobs'])>=5000){break;}
            $key=hash('sha256',$type.':'.$url.serialize($variant));
            if (!isset($state['jobs'][$key])) { $state['jobs'][$key]=array('url'=>$url,'type'=>$type,'headers'=>$variant['headers'],'attempts'=>0,'due'=>$now,'done'=>false); }
        }
    }
    public function pause($paused) {return $this->store->set('paused',(bool)$paused,31536000);}
    public function status() {
        $state=$this->store->get('queue');$total=0;$pending=0;$failed=0;
        foreach(is_array($state) && isset($state['jobs'])?$state['jobs']:array() as $job){$total++;if(!$job['done']){$pending++;}if($job['attempts']){$failed++;}}
        return array('paused'=>$this->store->get('paused')===true,'total'=>$total,'pending'=>$pending,'retry_jobs'=>$failed);
    }
    public function sitemap($xml) {
        if (!is_string($xml) || strlen($xml)>2097152 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) { throw new \RuntimeException('Unsafe sitemap'); }
        if (!function_exists('simplexml_load_string')) { throw new \RuntimeException('SimpleXML required'); }
        $previous=libxml_use_internal_errors(true);
        try { $doc=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        if (!$doc || !in_array($doc->getName(),array('urlset','sitemapindex'),true)) { throw new \RuntimeException('Invalid sitemap'); }
        $type=$doc->getName()==='sitemapindex'?'sitemap':'page';$rows=array();
        foreach ($doc->xpath('//*[local-name()="loc"]') as $loc) { $url=$this->url((string)$loc);if ($url) { $rows[]=array($url,$type); } if(count($rows)>=5000){break;} }
        return $rows;
    }
    public function run(array $config) {
        if($this->store->get('paused')===true){return array('paused'=>true,'fetched'=>0,'failed'=>0);}
        $lock=$this->store->lease('queue');
        if (!$lock) { return array('busy'=>true,'fetched'=>0,'failed'=>0); }
        try {
            $this->variants=WarmVariants::parse(isset($config['variants'])?$config['variants']:'');
            $now=call_user_func($this->clock);$state=$this->store->get('queue');
            $signature=hash('sha256',serialize(array($this->origin,isset($config['paths'])?$config['paths']:array(),isset($config['sitemaps'])?$config['sitemaps']:array(),$this->variants)));
            if (!is_array($state) || !isset($state['signature']) || $state['signature']!==$signature) { $state=array('signature'=>$signature,'jobs'=>array(),'sitemaps'=>0); }
            foreach (array_slice(isset($config['paths'])?(array)$config['paths']:array('/'),0,5000) as $path) { $this->add($state,$path,'page',$now); }
            foreach (array_slice(isset($config['sitemaps'])?(array)$config['sitemaps']:array(),0,20) as $url) { $this->add($state,$url,'sitemap',$now); }
            $limit=max(0,min(50,isset($config['limit'])?(int)$config['limit']:10));$interval=max(60,min(86400,isset($config['interval'])?(int)$config['interval']:3600));
            $ok=0;$failed=0;$start=microtime(true);
            foreach (array_keys($state['jobs']) as $key) {
                if($this->store->get('paused')===true){break;}
                if ($ok+$failed >= $limit || microtime(true)-$start>20) { break; }
                $job=$state['jobs'][$key];
                if ($job['due']>$now) { continue; }
                if ($job['done']) { $job['attempts']=0;$job['done']=false; }
                try {
                    $result=call_user_func($this->fetch,$job['url'],isset($job['headers'])?$job['headers']:array());
                    if ($job['type']==='sitemap') {
                        if (++$state['sitemaps']>20) { $job['done']=true;$job['due']=$now+$interval;$state['jobs'][$key]=$job;continue; }
                        foreach ($this->sitemap($result['body']) as $row) { $this->add($state,$row[0],$row[1],$now); }
                    }
                    $job['done']=true;$job['due']=$now+$interval;$job['attempts']=0;$ok++;
                } catch (\Exception $e) {
                    $failed++;$job['attempts']++;
                    $job['done']=$job['attempts']>=3;
                    $job['due']=$now+($job['done']?$interval:30*pow(2,$job['attempts']-1));
                }
                $state['jobs'][$key]=$job;
                if (!$this->store->set('queue',$state,2592000)) { throw new \RuntimeException('Cannot persist warm queue'); }
                usleep(100000);
            }
            $state['sitemaps']=0;
            if (!$this->store->set('queue',$state,2592000)) { throw new \RuntimeException('Cannot persist warm queue'); }
            $pending=0;foreach($state['jobs'] as $job){if(!$job['done']){$pending++;}}
            return array('fetched'=>$ok,'failed'=>$failed,'remaining'=>$pending,'total'=>count($state['jobs']),'interval'=>$interval);
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
