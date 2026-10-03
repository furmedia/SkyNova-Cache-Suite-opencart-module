<?php
namespace FurMedia\Cache;
/** Opt-in exact SQL, one catalog table, private request context. Native DB remains the executor. */
class Sqlcache {
    private $native; private $store; private $settings; private $context; private $prefix; private $transaction=false; private $enabled=true; private $checking=false;
    public function __construct($native, FileStore $store, array $settings, $context, $prefix) {
        $this->native=$native;$this->store=$store;$this->settings=$settings;$this->context=$context;$this->prefix=$prefix;
    }
    public function disable() { $this->enabled=false; }
    public function __call($name,$args) { return call_user_func_array(array($this->native,$name),$args); }
    public function approved($sql) {
        if (!is_string($sql) || strlen($sql)>8192 || !in_array($sql,Settings::lines($this->settings['sql_allow']),true)) { return false; }
        if (!preg_match('/^SELECT\s/i',$sql) || preg_match('/[;#@()\\\\]|--|\/\*|\b(?:JOIN|UNION|INTO|OUTFILE|DUMPFILE|FOR|LOCK|SLEEP|BENCHMARK|RAND|NOW|CURRENT_TIMESTAMP|SQL_CALC_FOUND_ROWS)\b/i',$sql)) { return false; }
        if (preg_match_all('/\bFROM\s+`?([a-zA-Z0-9_]+)`?/i',$sql,$m)!==1) { return false; }
        $allowed=array('category','category_description','category_path','manufacturer','manufacturer_to_store','information','information_description','information_to_store');
        if (!in_array($m[1][0],array_map(function($t){return $this->prefix.$t;},$allowed),true)) { return false; }
        // No comma-separated tables: tail may only contain an alias and ordinary clauses.
        $tail=substr($sql,strpos($sql,$m[0][0])+strlen($m[0][0]));
        if (!preg_match('/^\s*(?:(?:AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*\s*)?(?:$|WHERE\b|ORDER\s+BY\b|LIMIT\b)/i',$tail)) { return false; }
        return true;
    }
    private function context() {
        $this->checking=true;try{return call_user_func($this->context);}finally{$this->checking=false;}
    }
    public function query($sql) {
        $read=is_string($sql) && preg_match('/^\s*(?:SELECT|SHOW|EXPLAIN|DESCRIBE)\b/i',$sql);
        if($this->checking && $read){return $this->native->query($sql);}
        if (!$read) {
            if (preg_match('/^\s*(?:BEGIN|START\s+TRANSACTION|SET\s+AUTOCOMMIT\s*=\s*0)\b/i',$sql)) { $this->transaction=true; }
            $result=$this->native->query($sql);$this->store->purge();
            if (preg_match('/^\s*(?:COMMIT|ROLLBACK|SET\s+AUTOCOMMIT\s*=\s*1)\b/i',$sql)) { $this->transaction=false; }
            return $result;
        }
        if (!$this->enabled || $this->transaction || !$this->approved($sql)) { return $this->native->query($sql); }
        $context=$this->context();
        if (!$context || !$this->approved($sql)) { return $this->native->query($sql); }
        $key='sql:'.hash('sha256',serialize(array($sql,$context)));$entry=$this->store->get($key);
        if (is_array($entry) && isset($entry['rows'],$entry['row'],$entry['num_rows'])) { $this->store->count('sql_hit');return (object)$entry; }
        $generation=$this->store->generation();$result=$this->native->query($sql);
        if (is_object($result) && isset($result->rows,$result->row,$result->num_rows) && $this->context()===$context) {
            $this->store->set($key,array('rows'=>$result->rows,'row'=>$result->row,'num_rows'=>$result->num_rows),$this->settings['sql_ttl'],array('catalog','sql'),$generation);
        }
        return $result;
    }
}
