<?php
namespace FurMedia\Cache;

/** Private, bounded cache. A generation fence prevents in-flight requests resurrecting purged entries. */
class FileStore {
    private $root;
    private $options;
    private $clock;

    public function __construct($root, array $options = array(), $clock = null) {
        $this->root = rtrim($root, '/\\');
        $this->options = array_merge(Settings::defaults(), $options);
        $this->clock = $clock ?: function () { return time(); };
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) { throw new \RuntimeException('Cache directory unavailable.'); }
        if (is_link($this->root)) { throw new \RuntimeException('Symlink cache directory refused.'); }
        if (!is_file($this->root . '/.htaccess')) { @file_put_contents($this->root . '/.htaccess', "Require all denied\nDeny from all\n"); }
        if (!is_file($this->root . '/index.html')) { @file_put_contents($this->root . '/index.html', ''); }
    }

    private function locked($callback) {
        $lock = @fopen($this->root . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { if ($lock) { fclose($lock); } return false; }
        try { $result = call_user_func($callback); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        return $result;
    }

    private function readJson($file) {
        if (!is_file($file) || is_link($file)) { return null; }
        $raw = @file_get_contents($file);
        return $raw === false ? null : json_decode($raw, true);
    }

    private function atomic($file, $data) {
        $json = json_encode($data);
        if ($json === false) { return false; }
        $temp = @tempnam($this->root, '.tmp-');
        if ($temp === false) { return false; }
        @chmod($temp, 0600);
        $ok = file_put_contents($temp, $json, LOCK_EX) === strlen($json) && @rename($temp, $file);
        if (is_file($temp)) { @unlink($temp); }
        return $ok;
    }

    public function generation() {
        $data = $this->readJson($this->root . '/generation.json');
        return is_array($data) && isset($data['id']) ? $data['id'] : 'initial';
    }

    /** Bounded striped locks; busy requests render natively instead of competing to populate cache. */
    public function lease($key) {
        $stripe=hexdec(substr(hash('sha256',$key),0,2)) % 64;
        $lock=@fopen($this->root.'/.lease-'.$stripe,'c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) { fclose($lock); } return false; }
        return $lock;
    }

    private function validEntry($entry) {
        return is_array($entry) && isset($entry['expires'],$entry['generation'],$entry['tags'])
            && is_int($entry['expires']) && is_string($entry['generation']) && is_array($entry['tags']) && array_key_exists('data',$entry);
    }

    public function get($key) {
        $entry = $this->readJson($this->root . '/' . hash('sha256', $key) . '.cache');
        if (!$this->validEntry($entry) || $entry['expires'] <= call_user_func($this->clock) || $entry['generation'] !== $this->generation()) { return null; }
        return $entry['data'];
    }

    public function set($key, $data, $ttl, array $tags = array(), $generation = null) {
        $generation = $generation === null ? $this->generation() : $generation;
        $entry = array('expires'=>call_user_func($this->clock) + max(1,(int)$ttl), 'generation'=>$generation, 'tags'=>$tags, 'data'=>$data);
        $encoded = json_encode($entry);
        if ($encoded === false || strlen($encoded) > $this->options['max_entry_kb'] * 1024) { return false; }
        return $this->locked(function () use ($key, $entry, $encoded, $generation) {
            if ($generation !== $this->generation()) { return false; }
            $free = @disk_free_space($this->root);
            if ($free !== false && $free < $this->options['reserve_mb'] * 1048576 + strlen($encoded)) { return false; }
            $files = glob($this->root . '/*.cache') ?: array();
            $bytes = 0;
            foreach ($files as $file) { $bytes += (int)@filesize($file); }
            $limit = $this->options['max_mb'] * 1048576;
            $count = count($files);
            if ($bytes + strlen($encoded) > $limit || $count >= $this->options['max_entries']) {
                usort($files, function ($a, $b) { return filemtime($a) - filemtime($b); });
                foreach ($files as $file) {
                    $size = (int)@filesize($file);
                    if (!is_link($file) && @unlink($file)) { $bytes -= $size; $count--; }
                    if ($bytes + strlen($encoded) <= $limit * .9 && $count < $this->options['max_entries']) { break; }
                }
            }
            return $this->atomic($this->root . '/' . hash('sha256', $key) . '.cache', $entry);
        });
    }

    public function purge($tag = null) {
        return $this->locked(function () use ($tag) {
            // Rotate generation even for selective purge; this fences all in-flight writes.
            $old = $this->generation();
            $new = bin2hex(Entropy::bytes(16));
            if (!$this->atomic($this->root . '/generation.json', array('id'=>$new))) { return false; }
            $count = 0;
            foreach (glob($this->root . '/*.cache') ?: array() as $file) {
                $entry = $this->readJson($file);
                if ($tag !== null && $this->validEntry($entry) && $entry['generation'] === $old && !in_array($tag, $entry['tags'], true)) {
                    $entry['generation'] = $new;
                    $this->atomic($file, $entry);
                } elseif (!is_link($file) && @unlink($file)) { $count++; }
            }
            return $count;
        });
    }

    public function gc() {
        return $this->locked(function () {
            $count = 0;
            foreach (glob($this->root . '/*.cache') ?: array() as $file) {
                $entry = $this->readJson($file);
                if (!$this->validEntry($entry) || $entry['expires'] <= call_user_func($this->clock) || $entry['generation'] !== $this->generation()) {
                    if (!is_link($file) && @unlink($file)) { $count++; }
                }
            }
            return $count;
        });
    }

    public function count($kind) {
        if (!in_array($kind, array('hit','miss','bypass','write','error','sql_hit','component_hit'), true)) { return; }
        $this->locked(function () use ($kind) {
            $stats = $this->readJson($this->root . '/stats.json') ?: array();
            $stats[$kind] = isset($stats[$kind]) ? $stats[$kind] + 1 : 1;
            $day=gmdate('Y-m-d');
            if (!isset($stats['daily']) || !is_array($stats['daily'])) { $stats['daily']=array(); }
            if (!isset($stats['daily'][$day])) { $stats['daily'][$day]=array(); }
            $stats['daily'][$day][$kind]=isset($stats['daily'][$day][$kind]) ? $stats['daily'][$day][$kind]+1 : 1;
            $stats['daily']=array_slice($stats['daily'],-30,null,true);
            $this->atomic($this->root . '/stats.json', $stats);
        });
    }

    public function inventory($limit=200,$offset=0) {
        $rows=array();$seen=0;$offset=max(0,min(20000,(int)$offset));foreach(glob($this->root.'/*.cache')?:array() as $file){if(count($rows)>=max(1,min(200,(int)$limit))){break;}$entry=$this->readJson($file);if(!$this->validEntry($entry)){continue;}if($seen++<$offset){continue;}$tags=array();foreach($entry['tags'] as $tag){if(is_string($tag)&&preg_match('~^(catalog|component|resource|compression|route:[a-z0-9_/]+|module:[a-z0-9_/:]+)$~D',$tag)){$tags[]=$tag;}}$rows[]=array('id'=>basename($file,'.cache'),'bytes'=>(int)filesize($file),'expires'=>$entry['expires'],'active'=>$entry['generation']===$this->generation()&&$entry['expires']>call_user_func($this->clock),'tags'=>$tags);}return $rows;
    }
    public function removeId($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)){throw new \InvalidArgumentException('Invalid cache entry identifier');}
        return $this->locked(function()use($id){$old=$this->generation();$new=bin2hex(Entropy::bytes(16));if(!$this->atomic($this->root.'/generation.json',array('id'=>$new))){return false;}$count=0;foreach(glob($this->root.'/*.cache')?:array() as $file){$entry=$this->readJson($file);if(basename($file,'.cache')!==$id&&$this->validEntry($entry)&&$entry['generation']===$old){$entry['generation']=$new;$this->atomic($file,$entry);}elseif(!is_link($file)&&unlink($file)){$count++;}}return $count;});
    }

    public function stats() {
        $stats = $this->readJson($this->root . '/stats.json') ?: array();
        $files = glob($this->root . '/*.cache') ?: array();
        $stats['entries'] = count($files); $stats['bytes'] = 0;
        foreach ($files as $file) { $stats['bytes'] += (int)@filesize($file); }
        $stats['free_bytes'] = @disk_free_space($this->root);
        return $stats;
    }
}
