<?php
namespace FurMedia\Cache;
/** Small adapter keeping disk generation fences authoritative. */
class MemoryClient {
 private $native;private $kind;
 public function __construct($kind,$native=null){$this->kind=$kind;$this->native=$native;if($kind==='apcu'&&(!function_exists('apcu_enabled')||!apcu_enabled())){throw new \RuntimeException('APCu unavailable');}if($kind==='memcache'&&!$native){if(!class_exists('Memcache')){throw new \RuntimeException('Memcache extension unavailable');}$this->native=new \Memcache();if(!$this->native->connect(Vault::value('SKYNOVA_CACHE_HOST')?:'127.0.0.1',(int)(Vault::value('SKYNOVA_CACHE_PORT')?:11211),.3)){throw new \RuntimeException('Memcache unavailable');}}}
 public function get($key){return $this->kind==='apcu'?apcu_fetch($key):$this->native->get($key);}
 public function set($key,$value,$ttl){return $this->kind==='apcu'?apcu_store($key,$value,$ttl):$this->native->set($key,$value,0,$ttl);}
}
