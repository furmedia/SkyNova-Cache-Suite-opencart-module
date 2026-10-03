<?php
namespace FurMedia\Cache;

/** Memory acceleration with authoritative disk generations and recoverable disk fallback. */
class CacheStore extends FileStore {
    private $client;
    private $backend;
    private $prefix;
    private $maximum;
    public function __construct($root, array $options = array(), $client = null) {
        parent::__construct($root,$options);
        $options=array_merge(Settings::defaults(),$options);
        $this->backend=$options['backend'];
        $this->maximum=$options['max_entry_kb']*1024;
        $this->prefix='skynova:'.hash('sha256',realpath($root)).':';
        $this->client=$client;
        if ($client || $this->backend==='file') { return; }
        try {
            $host=Vault::value('SKYNOVA_CACHE_HOST') ?: '127.0.0.1';
            if(in_array($this->backend,array('apcu','memcache'),true)){$this->client=new MemoryClient($this->backend);}
            elseif ($this->backend==='redis' && class_exists('Redis')) {
                $r=new \Redis();
                if (!$r->connect($host,(int)(Vault::value('SKYNOVA_CACHE_PORT') ?: 6379),0.3)) { return; }
                $r->setOption(\Redis::OPT_READ_TIMEOUT,0.3);
                $password=Vault::value('SKYNOVA_CACHE_PASSWORD');
                if ($password && !$r->auth($password)) { $r->close();return; }
                if (!$r->select((int)(Vault::value('SKYNOVA_REDIS_DB') ?: 0))) { $r->close();return; }
                $this->client=$r;
            } elseif ($this->backend==='memcached' && class_exists('Memcached')) {
                $m=new \Memcached();
                $m->setOption(\Memcached::OPT_CONNECT_TIMEOUT,300);
                $m->setOption(\Memcached::OPT_RECV_TIMEOUT,300000);
                $m->setOption(\Memcached::OPT_SEND_TIMEOUT,300000);
                $m->setOption(\Memcached::OPT_BINARY_PROTOCOL,true);
                if (Vault::value('SKYNOVA_CACHE_USERNAME')) { $m->setSaslAuthData(Vault::value('SKYNOVA_CACHE_USERNAME'),Vault::value('SKYNOVA_CACHE_PASSWORD')); }
                $m->addServer($host,(int)(Vault::value('SKYNOVA_CACHE_PORT') ?: 11211));
                $this->client=$m;
            } elseif (array_key_exists($this->backend,Drivers::choices())) { $this->client=Drivers::create($this->backend); }
        } catch (\Exception $e) { $this->client=null; }
    }
    private function remoteKey($key,$generation) { return $this->prefix.hash('sha256',$generation.':'.$key); }
    public function get($key) {
        $generation=$this->generation();
        if ($this->client) {
            try {
                $raw=$this->client->get($this->remoteKey($key,$generation));
                $entry=is_string($raw) && strlen($raw)<=$this->maximum+1024 ? json_decode($raw,true) : null;
                if (is_array($entry) && isset($entry['expires']) && $entry['expires']>time() && array_key_exists('data',$entry) && $generation===$this->generation()) { return $entry['data']; }
            } catch (\Exception $e) { $this->client=null; }
        }
        return parent::get($key);
    }
    public function set($key,$data,$ttl,array $tags=array(),$generation=null) {
        $generation=$generation===null ? $this->generation() : $generation;
        $ok=parent::set($key,$data,$ttl,$tags,$generation);
        if ($ok && $this->client) {
            try {
                $raw=json_encode(array('expires'=>time()+max(1,(int)$ttl),'data'=>$data));
                if ($this->backend==='redis') { $this->client->setex($this->remoteKey($key,$generation),max(1,(int)$ttl),$raw); }
                else { $this->client->set($this->remoteKey($key,$generation),$raw,max(1,(int)$ttl)); }
            } catch (\Exception $e) { $this->client=null; }
        }
        return $ok;
    }
    public function backend() { return $this->client ? $this->backend.' + disk fallback' : 'file'; }
}
