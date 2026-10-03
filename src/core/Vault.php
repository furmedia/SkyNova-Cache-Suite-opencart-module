<?php
namespace FurMedia\Cache;
/** Authenticated encryption for service configuration outside the web root. Never exported. */
class Vault {
    private $root;
    public static function fields() {
        return array('SKYNOVA_CRON_SECRET'=>'Remote CRON · Bearer secret (32–128 URL-safe characters)','SKYNOVA_CF_ZONE'=>'Cloudflare · Zone ID','SKYNOVA_CF_TOKEN'=>'Cloudflare · API token Cache Purge','FURMEDIA_PAGESPEED_KEY'=>'Google · PageSpeed API key','SKYNOVA_S3_BUCKET'=>'Amazon S3 · Bucket','SKYNOVA_S3_REGION'=>'Amazon S3 · Region','SKYNOVA_S3_ACCESS_KEY'=>'Amazon S3 · Access key','SKYNOVA_S3_SECRET_KEY'=>'Amazon S3 · Secret key','SKYNOVA_S3_SESSION_TOKEN'=>'Amazon S3 · Session token (optional)','SKYNOVA_CACHE_HOST'=>'Redis / Memcached · Host (shared installation)','SKYNOVA_CACHE_PORT'=>'Redis / Memcached · Port (shared installation)','SKYNOVA_CACHE_USERNAME'=>'Memcached · Username (shared installation)','SKYNOVA_CACHE_PASSWORD'=>'Redis / Memcached · Password (shared installation)','SKYNOVA_REDIS_DB'=>'Redis · Database (shared installation)');
    }
    public function __construct($root) {
        if(!extension_loaded('openssl')){throw new \RuntimeException('OpenSSL required for encrypted service configuration');}
        if(is_link($root)){throw new \RuntimeException('Vault links are forbidden');}
        if(!is_dir($root) && !@mkdir($root,0700,true) && !is_dir($root)){throw new \RuntimeException('Private vault unavailable');}
        $this->root=realpath($root);if(!$this->root){throw new \RuntimeException('Private vault unavailable');}
    }
    private function key() {
        $path=$this->root.'/key';if(is_link($path)){throw new \RuntimeException('Invalid vault key');}
        if(!is_file($path)){
            $handle=@fopen($path,'x');if($handle){@chmod($path,0600);$key=Entropy::bytes(32);fwrite($handle,$key);fclose($handle);}
        }
        $key=@file_get_contents($path);if(!is_string($key) || strlen($key)!==32){throw new \RuntimeException('Vault key unavailable');}return $key;
    }
    public function sign($payload) {return hash_hmac('sha256',(string)$payload,$this->key());}
    public function read($storeId=0) {
        $path=$this->root.'/store-'.max(0,(int)$storeId).'.vault';if(!is_file($path)){return array();}
        if(is_link($path) || filesize($path)>65536){throw new \RuntimeException('Invalid vault file');}
        $raw=file_get_contents($path);if(strlen($raw)<49){throw new \RuntimeException('Invalid vault envelope');}
        $key=$this->key();$mac=substr($raw,0,32);$payload=substr($raw,32);
        if(!hash_equals($mac,hash_hmac('sha256',$payload,$key,true))){throw new \RuntimeException('Vault authentication failed');}
        $decoded=openssl_decrypt(substr($payload,16),'aes-256-cbc',$key,OPENSSL_RAW_DATA,substr($payload,0,16));$data=json_decode($decoded,true);
        if(!is_array($data)){throw new \RuntimeException('Invalid vault data');}return $data;
    }
    public function save(array $values,$storeId=0,array $remove=array()) {
        $handle=@fopen($this->root.'/.lock','c');if(!$handle || !flock($handle,LOCK_EX|LOCK_NB)){if($handle){fclose($handle);}throw new \RuntimeException('Vault busy');}
        try {
            $fields=self::fields();$data=$this->read($storeId);
            foreach($remove as $field){if(!isset($fields[$field])){throw new \InvalidArgumentException('Unknown service field');}unset($data[$field]);}
            foreach($values as $field=>$value){
                if(!isset($fields[$field]) || !is_string($value) || strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/',$value)){throw new \InvalidArgumentException('Invalid service field');}
                if($field==='SKYNOVA_CRON_SECRET' && $value!=='' && !preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$value)){throw new \InvalidArgumentException('CRON secret requires 32 to 128 URL-safe characters');}
                if($value!==''){$data[$field]=$value;}
            }
            if(strlen(json_encode($data))>32768){throw new \InvalidArgumentException('Service configuration too large');}
            $key=$this->key();$iv=Entropy::bytes(16);$encrypted=openssl_encrypt(json_encode($data),'aes-256-cbc',$key,OPENSSL_RAW_DATA,$iv);if($encrypted===false){throw new \RuntimeException('Vault encryption failed');}
            $payload=$iv.$encrypted;$raw=hash_hmac('sha256',$payload,$key,true).$payload;$temporary=tempnam($this->root,'private-');@chmod($temporary,0600);
            if(file_put_contents($temporary,$raw)!==strlen($raw) || !@rename($temporary,$this->root.'/store-'.max(0,(int)$storeId).'.vault')){@unlink($temporary);throw new \RuntimeException('Cannot save encrypted service configuration');}
            return true;
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    public static function value($name,$storeId=0) {
        if(!isset(self::fields()[$name])){return false;}
        $environment=getenv($name.($storeId?'_'.(int)$storeId:''));if($environment!==false && $environment!==''){return $environment;}
        if(!defined('DIR_CACHE') || !defined('DIR_APPLICATION') || !Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){return false;}
        try{$data=(new self(DIR_CACHE.'furmedia_cache-vault'))->read($storeId);return isset($data[$name])?$data[$name]:false;}catch(\Exception $e){return false;}
    }
    public static function status($storeId=0) {$rows=array();foreach(self::fields() as $name=>$label){$rows[$name]=array('label'=>$label,'configured'=>self::value($name,$storeId)!==false,'source'=>getenv($name.($storeId?'_'.(int)$storeId:''))?'environment':'private vault');}return $rows;}
}
