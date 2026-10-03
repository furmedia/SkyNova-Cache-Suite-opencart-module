<?php
/** Copy to private DIR_STORAGE/skynova-cache-drivers.php only after configuring a PSR-6 pool. */
class SkyNovaPsr6Driver {
    private $pool;
    public function __construct($pool){$this->pool=$pool;}
    public function get($key){$item=$this->pool->getItem(hash('sha256',$key));return $item->isHit()?$item->get():false;}
    public function set($key,$value,$ttl){$item=$this->pool->getItem(hash('sha256',$key));$item->set($value);$item->expiresAfter($ttl);return $this->pool->save($item);}
}
\FurMedia\Cache\Drivers::register('psr6',function(){if(empty($GLOBALS['skynova_psr6_pool'])){throw new RuntimeException('Configure a trusted PSR-6 pool before selecting the driver');}return new SkyNovaPsr6Driver($GLOBALS['skynova_psr6_pool']);},'PSR-6 pool');
