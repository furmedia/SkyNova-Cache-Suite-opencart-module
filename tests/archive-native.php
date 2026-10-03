<?php
if(PHP_SAPI!=='cli'){exit(1);}require __DIR__.'/../src/core/bootstrap.php';
$native=new mysqli('127.0.0.1','root','','furmedia_stage',33319);
$dir=$native->query('SELECT @@datadir AS d')->fetch_assoc()['d'];$expected=realpath(__DIR__.'/../work/local-stage/mysql-data');
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$expected))){throw new Exception('Wrong isolated MySQL');}
class ArchiveTestDb {private $db;public function __construct($db){$this->db=$db;}public function escape($v){return $this->db->real_escape_string($v);}public function query($sql){$q=$this->db->query($sql);$r=new stdClass();$r->rows=array();if($q instanceof mysqli_result){while($row=$q->fetch_assoc()){$r->rows[]=$row;}}$r->row=$r->rows? $r->rows[0]:array();return $r;}}
$db=new ArchiveTestDb($native);$prefix='skynova_archive_test_';$table=$prefix.'product';$checks=0;$id=null;
function archiveAssert($ok,$name){global $checks;if(!$ok){throw new Exception($name);}$checks++;}
archiveAssert($native->query("SHOW TABLES LIKE '".$table."'")->num_rows===0,'Fixture must be absent');
$root=__DIR__.'/../work/local-stage/archive-test-'.bin2hex(FurMedia\Cache\Entropy::bytes(6));
try{
 $db->query('CREATE TABLE '.$table.' (product_id INT PRIMARY KEY, model MEDIUMBLOB, status INT NULL) ENGINE=InnoDB');
 $payload=str_repeat('binary'.chr(0).chr(255),250);$hex=bin2hex($payload);
 for($start=1;$start<=12001;$start+=500){$values=array();for($i=$start;$i<min($start+500,12002);$i++){$values[]='('.$i.",X'".$hex."',".($i%2?'NULL':'1').')';}$db->query('INSERT INTO '.$table.' VALUES '.implode(',',$values));}
 $a=new FurMedia\Cache\Dbarchive($db,$prefix,$root,128);$s=$a->start($table);$id=$s['id'];$a->step($id);
 archiveAssert($a->pause($id)['phase']==='paused','Persistent pause');$a->pause($id,true);
 $db->query('UPDATE '.$table.' SET model=0x6166746572 WHERE product_id=1');
 for($i=0;$i<100&&$a->state($id)['phase']!=='ready';$i++){$a->step($id,250);}
 $s=$a->state($id);archiveAssert($s['phase']==='ready'&&$s['total']===12001,'More than 10000 rows exported');archiveAssert($s['bytes']>16777216,'More than 16 MB exported');
 $a->restoreStart($id);for($i=0;$i<150&&$a->state($id)['phase']!=='restore_ready';$i++){$a->step($id);}
 archiveAssert($a->state($id)['phase']==='restore_ready','All restore chunks verified');
 $blocked=false;try{$a->commit($id,false);}catch(Exception $e){$blocked=true;}archiveAssert($blocked,'Maintenance gate');
 $a->commit($id,true);$row=$db->query('SELECT model,status FROM '.$table.' WHERE product_id=1')->row;archiveAssert($row['model']===$payload&&$row['status']===null,'Binary and NULL restored from frozen snapshot');
 archiveAssert((int)$db->query('SELECT COUNT(*) AS n FROM '.$table)->row['n']===12001,'Restored row count');
 $a->commit($id,true,true);archiveAssert($db->query('SELECT model FROM '.$table.' WHERE product_id=1')->row['model']==='after','Previous table restored by undo');
 $file=$root.'/'.$id.'/state.json';$raw=file_get_contents($file);file_put_contents($file,str_replace('undone','bogus!',$raw));$blocked=false;try{$a->state($id);}catch(Exception $e){$blocked=true;}archiveAssert($blocked,'Metadata tampering refused');file_put_contents($file,$raw);
 file_put_contents(__DIR__.'/../docs/validation/archive-native.json',json_encode(array('scope'=>'own isolated loopback MySQL','assertions'=>$checks,'passed'=>true),JSON_PRETTY_PRINT));
 echo 'PASS '.$checks." archive native assertions\n";
}finally{
 $db->query('DROP TABLE IF EXISTS '.$table);
 if($id){foreach(array('bak','restore','meta','prev','replaced') as $kind){$db->query('DROP TABLE IF EXISTS skynova_'.$kind.'_'.$id);}}
}
