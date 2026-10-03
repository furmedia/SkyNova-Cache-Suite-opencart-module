<?php
namespace FurMedia\Cache;

/** Resumable, bounded preparation of generated assets. Originals are never modified. */
class Batch {
    private $store;
    private $root;
    private $assets;
    private $settings;
    public function __construct($privateRoot,$root,$base,array $settings) {
        $this->root=realpath($root);
        if(!$this->root){throw new \InvalidArgumentException('Store root unavailable');}
        $this->settings=$settings;
        $this->assets=new Assets($this->root,$this->root.'/image/cache/furmedia_cache',$base,$settings);
        $this->store=new FileStore($privateRoot,array('max_entry_kb'=>4096,'max_entries'=>32,'max_mb'=>16));
    }
    public function status() { $state=$this->store->get('state');return is_array($state)?$state:array('status'=>'idle'); }
    public function start($kind) {
        if(!in_array($kind,array('images','minify'),true)){throw new \InvalidArgumentException('Invalid batch kind');}
        $lock=$this->store->lease('batch');if(!$lock){throw new \RuntimeException('Batch busy');}
        try {
            $state=$this->status();if(isset($state['status']) && in_array($state['status'],array('scan','run'),true)){throw new \RuntimeException('Finish or cancel the current batch first');}
            $state=array('status'=>'scan','kind'=>$kind,'started_at'=>gmdate('c'),'directories'=>$kind==='images'?array('image'):array('catalog','extension'),'directory_offset'=>0,'files'=>array(),'cursor'=>0,'scanned'=>0,'processed'=>0,'generated'=>0,'skipped'=>0,'errors'=>0,'settings_hash'=>hash('sha256',serialize($this->settings)));
            $this->save($state);return $state;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function cancel() {
        $lock=$this->store->lease('batch');if(!$lock){throw new \RuntimeException('Batch busy');}
        try {$state=$this->status();$state['status']='cancelled';unset($state['directories'],$state['files']);$this->save($state);return $state;}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private function save(array $state) {if(!$this->store->set('state',$state,2592000)){throw new \RuntimeException('Could not persist batch progress');}}
    private function file($relative) {
        if(!is_string($relative) || strpos($relative,'..')!==false || strpos($relative,"\0")!==false || strpos($relative,'\\')!==false || !preg_match('~^(image|catalog|extension)(/|$)~',$relative)){return false;}
        $candidate=$this->root.'/'.$relative;
        if(is_link($candidate)){return false;}
        $real=realpath($candidate);$root=str_replace('\\','/',$this->root).'/';
        return $real && strpos(str_replace('\\','/',$real),$root)===0?$real:false;
    }
    public function step($limit=10) {
        $limit=max(1,min(50,(int)$limit));$lock=$this->store->lease('batch');if(!$lock){return $this->status();}
        try {
            $state=$this->status();if(!isset($state['status']) || !in_array($state['status'],array('scan','run'),true)){return $state;}
            if($state['settings_hash']!==hash('sha256',serialize($this->settings))){throw new \RuntimeException('Settings changed; cancel and restart the batch');}
            $deadline=microtime(true)+5;$seen=0;
            while($state['status']==='scan' && $state['directories'] && $seen<500 && microtime(true)<$deadline){
                $relative=$state['directories'][0];$dir=$this->file($relative);
                if(!$dir || !is_dir($dir)){array_shift($state['directories']);$state['directory_offset']=0;continue;}
                $iterator=new \DirectoryIterator($dir);$index=0;$complete=true;
                foreach($iterator as $entry){
                    if($entry->isDot()){continue;}
                    if($index++<$state['directory_offset']){continue;}
                    if($seen>=500 || microtime(true)>=$deadline){$complete=false;break;}
                    $state['directory_offset']=$index;$seen++;$state['scanned']++;
                    $name=$entry->getFilename();$path=$relative.'/'.$name;
                    if($entry->isLink() || $name[0]==='.' || strpos($name,'\\')!==false){continue;}
                    if($entry->isDir()){
                        if(in_array(strtolower($name),array('cache','node_modules','vendor','furmedia_cache'),true)){continue;}
                        $state['directories'][]=$path;
                    }elseif($entry->isFile()){
                        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                        if(($state['kind']==='images' && in_array($ext,array('jpg','jpeg','png'),true)) || ($state['kind']==='minify' && in_array($ext,array('css','js'),true))){$state['files'][]=$path;}
                    }
                    if(count($state['directories'])>10000){$state['status']='limit';$state['message']='Maximum 10000 pending directories; use separate runs or a dedicated worker for deeper stores.';break;}
                    if(count($state['files'])>=1000){$state['status']='run';$complete=false;break;}
                }
                if($complete && $state['status']==='scan'){array_shift($state['directories']);$state['directory_offset']=0;}
            }
            if($state['status']==='scan' && !$state['directories']){$state['status']='run';}
            $processed=0;
            while($state['status']==='run' && $state['cursor']<count($state['files']) && $processed<$limit && microtime(true)<$deadline){
                $relative=$state['files'][$state['cursor']];$output=$relative;
                try {
                    if($state['kind']==='minify'){$output=$this->assets->minify($relative,strtolower(pathinfo($relative,PATHINFO_EXTENSION)));}
                    else {
                        $output=$this->assets->image($relative,'image/avif,image/webp');
                        if($this->settings['webp']){$webp=$this->assets->image($relative,'image/webp');if($webp!==$relative){$output=$webp;}}
                        if($this->settings['responsive_images']){$this->assets->variants($relative,'image/avif,image/webp');if($this->settings['webp']){$this->assets->variants($relative,'image/webp');}}
                    }
                    if($output!==$relative){$state['generated']++;}else{$state['skipped']++;}
                }catch(\Exception $e){$state['errors']++;}
                $state['cursor']++;$state['processed']++;$processed++;
            }
            if($state['status']==='run' && $state['cursor']===count($state['files'])){
                if($state['directories']){$state['status']='scan';$state['files']=array();$state['cursor']=0;}
                else{$state['status']='done';$state['finished_at']=gmdate('c');unset($state['files'],$state['directories']);}
            }
            $this->save($state);return $state;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
