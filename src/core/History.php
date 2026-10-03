<?php
namespace FurMedia\Cache;
/** Small, independent, bounded report history; no visitor identifiers or URLs. */
class History {
    private $store;
    public function __construct($root) { $this->store=new FileStore($root,array('max_entry_kb'=>256,'max_entries'=>100,'max_mb'=>8)); }
    public function append($kind,array $report) {
        if (!in_array($kind,array('pagespeed','warm','indexes','diagnostic','sqlprofile','dbmaintenance','maintenance','queryplan'),true)) { throw new \InvalidArgumentException('Unknown report'); }
        $lock=$this->store->lease('history:'.$kind);
        if (!$lock) { return false; }
        try {
            $rows=$this->rows($kind);$rows[]=array('at'=>gmdate('c'),'report'=>$report);
            return $this->store->set($kind,array_slice($rows,-30),2592000);
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function rows($kind) { $rows=$this->store->get($kind);return is_array($rows)?$rows:array(); }
}
