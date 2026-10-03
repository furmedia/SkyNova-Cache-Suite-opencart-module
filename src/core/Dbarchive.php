<?php
namespace FurMedia\Cache;

/** Private authenticated table archives. Frozen DB snapshots make export resumable. */
class Dbarchive {
    private $db; private $prefix; private $root; private $vault; private $quota;
    public function __construct($db,$prefix,$root,$maxMb=2048) {
        if(!is_string($prefix)||!preg_match('/^[a-zA-Z0-9_]*$/D',$prefix)||is_link($root)){throw new \InvalidArgumentException('Invalid archive location');}
        if(!is_dir($root)&&!mkdir($root,0700,true)){throw new \RuntimeException('Private archive unavailable');}
        $this->db=$db;$this->prefix=$prefix;$this->root=realpath($root);$this->vault=new Vault($this->root.'/vault');$this->quota=max(64,min(32768,(int)$maxMb))*1048576;
    }
    private function dir($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new \InvalidArgumentException('Invalid archive ID');}
        $dir=$this->root.'/'.$id;if(is_link($dir)){throw new \RuntimeException('Archive links refused');}return $dir;
    }
    private function atomic($file,$raw) {
        if(is_link($file)){throw new \RuntimeException('Archive links refused');}
        $tmp=tempnam(dirname($file),'private-');@chmod($tmp,0600);
        if(file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)||!rename($tmp,$file)){@unlink($tmp);throw new \RuntimeException('Cannot persist archive');}
    }
    private function save(array $s) {
        $raw=json_encode($s);if($raw===false||strlen($raw)>16777216){throw new \RuntimeException('Archive metadata exceeds bound');}
        $this->atomic($this->dir($s['id']).'/state.json',json_encode(array('payload'=>$raw,'signature'=>$this->vault->sign($raw))));
    }
    public function state($id) {
        $file=$this->dir($id).'/state.json';if(is_link($file)||!is_file($file)||filesize($file)>33554432){throw new \RuntimeException('Archive unavailable');}
        $envelope=json_decode(file_get_contents($file),true);
        if(!is_array($envelope)||!isset($envelope['payload'],$envelope['signature'])||!is_string($envelope['payload'])||!is_string($envelope['signature'])||!hash_equals($this->vault->sign($envelope['payload']),$envelope['signature'])){throw new \RuntimeException('Archive authentication failed');}
        $s=json_decode($envelope['payload'],true);if(!is_array($s)||$s['id']!==$id||$s['prefix']!==$this->prefix){throw new \RuntimeException('Archive scope mismatch');}return $s;
    }
    public function listing() {
        $out=array();foreach(glob($this->root.'/*/state.json')?:array() as $file){if(count($out)>=100){break;}try{$s=$this->state(basename(dirname($file)));$out[]=$this->summary($s);}catch(\Exception $e){}}
        usort($out,function($a,$b){return strcmp($b['started'],$a['started']);});return $out;
    }
    public function summary(array $s) {
        $out=array();foreach(array('id','source','phase','started','total','exported','bytes','restore_part','verify_part','previous','replaced') as $k){if(isset($s[$k])){$out[$k]=$s[$k];}}$out['parts']=count($s['parts']);return $out;
    }
    private function lock($id) {$h=fopen($this->dir($id).'/.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h){fclose($h);}throw new \RuntimeException('Archive job busy');}return $h;}
    private function q($id) {if(!preg_match('/^[a-zA-Z0-9_]+$/D',$id)){throw new \RuntimeException('Invalid SQL identifier');}return '`'.$id.'`';}
    private function columns($table) {
        $out=array();$keys=array();foreach($this->db->query('SHOW COLUMNS FROM '.$this->q($table))->rows as $row){$this->q($row['Field']);if(stripos($row['Extra'],'GENERATED')===false){$out[]=$row['Field'];}}
        foreach($this->db->query('SHOW INDEX FROM '.$this->q($table))->rows as $row){if($row['Key_name']==='PRIMARY'){$keys[(int)$row['Seq_in_index']]=$row['Column_name'];}}
        ksort($keys);if(!$keys||!$out||count($out)>512){throw new \RuntimeException('Resumable archive requires a primary key and at most 512 columns');}return array($out,array_values($keys));
    }
    private function definition($table) {$row=$this->db->query('SHOW CREATE TABLE '.$this->q($table))->row;return isset($row['Create Table'])?$row['Create Table']:'';}
    private function exists($table) {return !empty($this->db->query("SHOW TABLES LIKE '".$this->db->escape(str_replace(array('_','%'),array('\\_','\\%'),$table))."'")->rows);}
    private function checksum($table) {$r=$this->db->query('CHECKSUM TABLE '.$this->q($table).' EXTENDED')->row;return isset($r['Checksum'])?(string)$r['Checksum']:null;}
    public function start($table) {
        $tables=(new Dbtools($this->db,$this->prefix,$this->root.'/legacy'))->tables();if(!is_string($table)||!isset($tables[$table])||!in_array($tables[$table]['engine'],array('InnoDB','MyISAM'),true)){throw new \InvalidArgumentException('Select a native InnoDB/MyISAM table');}
        list($cols,$keys)=$this->columns($table);$id=bin2hex(Entropy::bytes(16));$dir=$this->dir($id);mkdir($dir,0700);mkdir($dir.'/parts',0700);
        $s=array('id'=>$id,'prefix'=>$this->prefix,'source'=>$table,'snapshot'=>'skynova_bak_'.$id,'stage'=>'skynova_restore_'.$id,'marker'=>'skynova_meta_'.$id,'previous'=>'skynova_prev_'.$id,'phase'=>'snapshot','started'=>gmdate('c'),'definition'=>$this->definition($table),'columns'=>$cols,'keys'=>$keys,'engine'=>$tables[$table]['engine'],'total'=>0,'exported'=>0,'bytes'=>0,'parts'=>array(),'last'=>array());
        $this->save($s);return $this->summary($s);
    }
    private function room($bytes) {
        $used=0;foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS)) as $file){if(!$file->isLink()&&$file->isFile()){$used+=$file->getSize();}}
        $free=disk_free_space($this->root);if($used+$bytes>$this->quota||($free!==false&&$free<33554432+$bytes)){throw new \RuntimeException('Archive quota or disk reserve reached; increase quota or free private storage');}
    }
    private function readRows($table,array $s,array $last,$limit) {
        $where='';if($last){$names=array();$values=array();foreach($s['keys'] as $key){$names[]=$this->q($key);$values[]="'".$this->db->escape($last[$key])."'";}$where=' WHERE ('.implode(',',$names).') > ('.implode(',',$values).')';}
        $cols=array_map(array($this,'q'),$s['columns']);$keys=array_map(array($this,'q'),$s['keys']);return $this->db->query('SELECT '.implode(',',$cols).' FROM '.$this->q($table).$where.' ORDER BY '.implode(',',$keys).' LIMIT '.(int)$limit)->rows;
    }
    private function encode(array $rows,array $cols) {$out=array();foreach($rows as $row){$values=array();foreach($cols as $col){$values[]=$row[$col]===null?null:base64_encode((string)$row[$col]);}$out[]=$values;}return json_encode($out);}
    private function last(array $rows,array $keys) {$last=array();if($rows){$row=end($rows);foreach($keys as $key){$last[$key]=(string)$row[$key];}}return $last;}
    public function step($id,$limit=250) {
        $lock=$this->lock($id);try{
            $s=$this->state($id);$limit=max(1,min(1000,(int)$limit));
            if($s['phase']==='snapshot'){
                if($this->exists($s['snapshot'])){$this->db->query('DROP TABLE '.$this->q($s['snapshot']));}
                if(preg_replace('/AUTO_INCREMENT=[0-9]+/','',$s['definition'])!==preg_replace('/AUTO_INCREMENT=[0-9]+/','',$this->definition($s['source']))){throw new \RuntimeException('Source schema changed; start a fresh archive');}$this->room(1048576);$this->db->query('CREATE TABLE '.$this->q($s['snapshot']).' LIKE '.$this->q($s['source']));
                $cols=implode(',',array_map(array($this,'q'),$s['columns']));
                $this->db->query('LOCK TABLES '.$this->q($s['source']).' READ, '.$this->q($s['snapshot']).' WRITE');
                try{$s['checksum']=$this->checksum($s['source']);$this->db->query('INSERT INTO '.$this->q($s['snapshot']).' ('.$cols.') SELECT '.$cols.' FROM '.$this->q($s['source']));}finally{$this->db->query('UNLOCK TABLES');}
                $s['total']=(int)$this->db->query('SELECT COUNT(*) AS total FROM '.$this->q($s['snapshot']))->row['total'];$s['phase']='export';$this->save($s);
            } elseif($s['phase']==='export'){
                $rows=$this->readRows($s['snapshot'],$s,$s['last'],$limit);$raw=$this->encode($rows,$s['columns']);if($raw===false||strlen($raw)>8388608){throw new \RuntimeException('Chunk exceeds 8 MB; reduce rows per step');}
                if($rows){$this->room(strlen($raw));$part=count($s['parts']);$this->atomic($this->dir($id).'/parts/'.$part.'.json',$raw);$s['parts'][]=array('hash'=>hash('sha256',$raw),'rows'=>count($rows));$s['last']=$this->last($rows,$s['keys']);$s['exported']+=count($rows);$s['bytes']+=strlen($raw);}
                if($s['exported']===$s['total']){$s['phase']='ready';}elseif(!$rows){throw new \RuntimeException('Snapshot row count mismatch');}$this->save($s);
                if($s['phase']==='ready'){$this->db->query('DROP TABLE '.$this->q($s['snapshot']));}
            } elseif($s['phase']==='restore_setup'){$this->restoreSetup($s);}
            elseif($s['phase']==='restore'){$this->restoreChunk($s);}
            elseif($s['phase']==='verify'){$this->verifyChunk($s);}
            return $this->summary($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function pause($id,$resume=false) {$lock=$this->lock($id);try{$s=$this->state($id);if($resume&&$s['phase']==='paused'){$s['phase']=$s['resume'];unset($s['resume']);}elseif(!$resume&&in_array($s['phase'],array('snapshot','export','restore_setup','restore','verify'),true)){$s['resume']=$s['phase'];$s['phase']='paused';}$this->save($s);return $this->summary($s);}finally{flock($lock,LOCK_UN);fclose($lock);}}
    public function discardSnapshot($id){$lock=$this->lock($id);try{$s=$this->state($id);if($s['phase']!=='export'||$s['parts']){throw new \RuntimeException('Only unused frozen snapshots may be discarded');}if($this->exists($s['snapshot'])){$this->db->query('DROP TABLE '.$this->q($s['snapshot']));}$s['phase']='cancelled';$this->save($s);}finally{flock($lock,LOCK_UN);fclose($lock);}}
    private function restoreSafe(array $s) {
        if(stripos($s['definition'],'FOREIGN KEY')!==false||stripos($this->definition($s['source']),'FOREIGN KEY')!==false||$this->db->query("SHOW TRIGGERS WHERE `Table` = '".$this->db->escape($s['source'])."'")->rows){throw new \RuntimeException('Table swap with foreign keys/triggers requires external DBA restore');}
        $refs=$this->db->query("SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = '".$this->db->escape($s['source'])."'")->rows;if($refs){throw new \RuntimeException('Referenced table requires external DBA restore');}
    }
    public function restoreStart($id) {
        $lock=$this->lock($id);try{$s=$this->state($id);if($s['phase']!=='ready'){throw new \RuntimeException('Finish backup before restoring');}$this->restoreSafe($s);
            $s['phase']='restore_setup';$this->save($s);return $this->summary($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function restoreSetup(array &$s) {
            foreach(array('stage','marker') as $kind){if($this->exists($s[$kind])){$this->db->query('DROP TABLE '.$this->q($s[$kind]));}}
            $pattern='~^CREATE TABLE '.preg_quote($this->q($s['source']),'~').'~';$ddl=preg_replace($pattern,'CREATE TABLE '.$this->q($s['stage']),$s['definition'],1,$replaced);if($replaced!==1){throw new \RuntimeException('Unsupported archived definition');}
            $this->db->query($ddl);$this->db->query('ALTER TABLE '.$this->q($s['stage']).' ENGINE=InnoDB');
            $this->db->query('CREATE TABLE '.$this->q($s['marker']).' (part_id INT PRIMARY KEY, digest CHAR(64) NOT NULL) ENGINE=InnoDB');
            $s['restore_part']=0;$s['verify_part']=0;$s['verify_last']=array();$s['phase']='restore';$this->save($s);
    }
    private function part(array $s,$n) {$file=$this->dir($s['id']).'/parts/'.$n.'.json';if(is_link($file)||!is_file($file)||filesize($file)>8388608){throw new \RuntimeException('Backup chunk unavailable');}$raw=file_get_contents($file);if(!hash_equals($s['parts'][$n]['hash'],hash('sha256',$raw))){throw new \RuntimeException('Backup chunk hash mismatch');}return $raw;}
    private function restoreChunk(array &$s) {
        $n=$s['restore_part'];if($n<count($s['parts'])){
            $rows=json_decode($this->part($s,$n),true);if(!is_array($rows)||count($rows)!==$s['parts'][$n]['rows']){throw new \RuntimeException('Invalid backup chunk');}
            $this->db->query('START TRANSACTION');try{
                $done=$this->db->query('SELECT digest FROM '.$this->q($s['marker']).' WHERE part_id = '.(int)$n)->row;
                if(!$done){$values=array();foreach($rows as $row){if(count($row)!==count($s['columns'])){throw new \RuntimeException('Backup columns mismatch');}$cells=array();foreach($row as $cell){$value=$cell===null?null:base64_decode($cell,true);if($cell!==null&&$value===false){throw new \RuntimeException('Backup cell invalid');}$cells[]=$value===null?'NULL':"CAST(X'".bin2hex($value)."' AS BINARY)";}$values[]='('.implode(',',$cells).')';}
                    $this->db->query('INSERT INTO '.$this->q($s['stage']).' ('.implode(',',array_map(array($this,'q'),$s['columns'])).') VALUES '.implode(',',$values));
                    $this->db->query('INSERT INTO '.$this->q($s['marker'])." VALUES (".(int)$n.",'".$s['parts'][$n]['hash']."')");
                }elseif($done['digest']!==$s['parts'][$n]['hash']){throw new \RuntimeException('Restore checkpoint mismatch');}$this->db->query('COMMIT');
            }catch(\Exception $e){$this->db->query('ROLLBACK');throw $e;}$s['restore_part']++;$this->save($s);
        }
        if($s['restore_part']===count($s['parts'])){
            if((int)$this->db->query('SELECT COUNT(*) AS total FROM '.$this->q($s['stage']))->row['total']!==$s['total']){throw new \RuntimeException('Restored row count mismatch');}
            if($s['engine']==='MyISAM'){$this->db->query('ALTER TABLE '.$this->q($s['stage']).' ENGINE=MyISAM');}$s['phase']='verify';$this->save($s);
        }
    }
    private function verifyChunk(array &$s) {
        $n=$s['verify_part'];if($n<count($s['parts'])){$this->part($s,$n);$rows=$this->readRows($s['stage'],$s,$s['verify_last'],$s['parts'][$n]['rows']);$raw=$this->encode($rows,$s['columns']);if(!hash_equals($s['parts'][$n]['hash'],hash('sha256',$raw))){throw new \RuntimeException('Restored data verification failed');}$s['verify_last']=$this->last($rows,$s['keys']);$s['verify_part']++;}
        if($s['verify_part']===count($s['parts'])){$s['phase']='restore_ready';}$this->save($s);
    }
    public function commit($id,$maintenance=false,$undo=false) {
        if(!$maintenance){throw new \RuntimeException('Enable store maintenance before table replacement');}$lock=$this->lock($id);
        try{$s=$this->state($id);$this->restoreSafe($s);
            if($undo){if(!in_array($s['phase'],array('restored','undo_pending'),true)){throw new \RuntimeException('No committed restore to undo');}$s['replaced']='skynova_replaced_'.$id;$s['phase']='undo_pending';$this->save($s);if(!$this->exists($s['replaced'])){$this->db->query('RENAME TABLE '.$this->q($s['source']).' TO '.$this->q($s['replaced']).', '.$this->q($s['previous']).' TO '.$this->q($s['source']));}if($this->exists($s['previous'])||!$this->exists($s['source'])){throw new \RuntimeException('Undo incomplete');}$s['phase']='undone';}
            else{if(!in_array($s['phase'],array('restore_ready','swap_pending'),true)){throw new \RuntimeException('Verify restore chunks before replacing table');}$s['phase']='swap_pending';$this->save($s);
                if(!$this->exists($s['previous'])){$this->db->query('RENAME TABLE '.$this->q($s['source']).' TO '.$this->q($s['previous']).', '.$this->q($s['stage']).' TO '.$this->q($s['source']));}
                if($this->exists($s['stage'])||!$this->exists($s['source'])){throw new \RuntimeException('Table replacement incomplete');}$s['phase']='restored';
                if($this->exists($s['marker'])){$this->db->query('DROP TABLE '.$this->q($s['marker']));}
            }$this->save($s);return $this->summary($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function convert($id) {
        $lock=$this->lock($id);try{$s=$this->state($id);if($s['phase']!=='ready'||$s['engine']!=='MyISAM'){throw new \RuntimeException('Complete a MyISAM archive first');}
            $this->db->query('LOCK TABLES '.$this->q($s['source']).' WRITE');try{if($s['checksum']===null||$this->checksum($s['source'])!==$s['checksum']){throw new \RuntimeException('Source changed since backup; create a fresh archive before conversion');}$this->db->query('ALTER TABLE '.$this->q($s['source']).' ENGINE=InnoDB');}finally{$this->db->query('UNLOCK TABLES');}$s['converted']=gmdate('c');$this->save($s);return $this->summary($s);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
