<?php
/** Only the existing isolated loopback MySQL fixture. Never application credentials. */
if(PHP_SAPI!=='cli'){exit(1);}require __DIR__.'/../src/core/bootstrap.php';
$native=new mysqli('127.0.0.1','root','','furmedia_stage',33319);
$dir=$native->query('SELECT @@datadir AS d')->fetch_assoc()['d'];$expected=realpath(__DIR__.'/../work/local-stage/mysql-data');
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$expected))){throw new Exception('Wrong isolated MySQL instance');}
class AdvancedNativeDb {private $db;public function __construct($db){$this->db=$db;}public function escape($v){return $this->db->real_escape_string($v);}public function query($sql){$q=$this->db->query($sql);if(!$q){throw new Exception('Fixture SQL failed');}$r=new stdClass();$r->rows=array();if($q instanceof mysqli_result){while($row=$q->fetch_assoc()){$r->rows[]=$row;}}$r->row=isset($r->rows[0])?$r->rows[0]:array();return $r;}}
$db=new AdvancedNativeDb($native);$prefix='skynova_test_';$tables=array('product','cart','customer_activity','customer_search','session');$checks=array();
function advancedCheck($ok,$name){global $checks;if(!$ok){throw new Exception($name);}$checks[]=$name;}
foreach($tables as $name){advancedCheck($native->query("SHOW TABLES LIKE '".$prefix.$name."'")->num_rows===0,'fixture table absent '.$name);}
try{
 $native->query('CREATE TABLE skynova_test_product (product_id INT PRIMARY KEY,status INT) ENGINE=MyISAM');$native->query('INSERT INTO skynova_test_product VALUES (1,1),(2,0)');
 foreach(array('cart'=>array('cart_id','date_added','customer_id INT'),'customer_activity'=>array('activity_id','date_added','marker VARCHAR(30)'),'customer_search'=>array('customer_search_id','date_added','marker VARCHAR(30)'),'session'=>array('session_id','expire','marker VARCHAR(30)')) as $name=>$columns){$native->query('CREATE TABLE '.$prefix.$name.' (`'.$columns[0].'` INT PRIMARY KEY,`'.$columns[1].'` DATETIME,'.$columns[2].') ENGINE=InnoDB');}
 $native->query('INSERT INTO skynova_test_cart VALUES (1,DATE_SUB(NOW(),INTERVAL 120 DAY),0),(2,DATE_SUB(NOW(),INTERVAL 120 DAY),12),(3,NOW(),0)');
 foreach(array('customer_activity'=>'date_added','customer_search'=>'date_added','session'=>'expire') as $name=>$column){$native->query('INSERT INTO '.$prefix.$name." VALUES (1,DATE_SUB(NOW(),INTERVAL 120 DAY),'old'),(2,NOW(),'active')");}
 $root=__DIR__.'/../work/local-stage/advanced-db-backups';$tools=new FurMedia\Cache\Dbtools($db,$prefix,$root);
 advancedCheck(count($tools->tables())===5,'table inventory scoped to prefix');
 advancedCheck(count($tools->explain('SELECT product_id FROM skynova_test_product WHERE status=1'))===1,'real read-only EXPLAIN');
 $result=$tools->maintain('ANALYZE',array($prefix.'product'));advancedCheck(isset($result[$prefix.'product']),'real ANALYZE selected table');
 $result=$tools->maintain('OPTIMIZE',array($prefix.'product'));advancedCheck(isset($result[$prefix.'product']),'real OPTIMIZE selected table');
 $scheduleRoot=__DIR__.'/../work/local-stage/advanced-db-schedule-'.bin2hex(FurMedia\Cache\Entropy::bytes(6));
 $result=$tools->scheduled(array($prefix.'product'),$scheduleRoot);advancedCheck(isset($result[$prefix.'product']),'scheduled ANALYZE runs once');
 $result=$tools->scheduled(array($prefix.'product'),$scheduleRoot);advancedCheck(!empty($result['skipped']),'scheduled ANALYZE daily limit');
 (new FurMedia\Cache\FileStore(__DIR__.'/../work/local-stage/advanced-page-cache'))->purge();
 $result=$tools->scheduled(array($prefix.'product'),$scheduleRoot);advancedCheck(!empty($result['skipped']),'page purge preserves DB daily schedule');
 $result=$tools->convert(array($prefix.'product'));$backup=json_decode(file_get_contents($root.'/'.$result[$prefix.'product']['backup']),true);
 advancedCheck(count($backup['rows'])===2,'conversion backup contains original rows');advancedCheck($tools->tables()[$prefix.'product']['engine']==='InnoDB','real MyISAM conversion');
 foreach(array('cart','activity','search','session') as $kind){$preview=$tools->retention($kind,90);advancedCheck($preview['eligible']===1&&!$preview['applied'],'retention preview '.$kind);$apply=$tools->retention($kind,90,true);advancedCheck($apply['applied']&&is_file($root.'/'.$apply['backup']),'backup precedes retention '.$kind);}
 advancedCheck($native->query('SELECT * FROM skynova_test_cart')->num_rows===2,'retention keeps logged-in and recent carts');
 advancedCheck($native->query('SELECT * FROM skynova_test_session')->num_rows===1,'retention keeps active sessions');
 $profile=new FurMedia\Cache\Sqlprofile($db,__DIR__.'/../work/local-stage/advanced-profile',0);$profile->query("SELECT * FROM skynova_test_product WHERE status=1 AND 'fixture-private-literal'='fixture-private-literal'");
 $history=(new FurMedia\Cache\History(__DIR__.'/../work/local-stage/advanced-profile'))->rows('sqlprofile');advancedCheck(strpos(json_encode($history),'fixture-private-literal')===false,'real SQL profile anonymizes literal');
 file_put_contents(__DIR__.'/../docs/validation/advanced-db.json',json_encode(array('scope'=>'own loopback MySQL 8.4.3 fixture','checks'=>$checks,'count'=>count($checks)),JSON_PRETTY_PRINT));echo 'PASS '.count($checks)." native DB assertions\n";
}finally{foreach($tables as $name){$native->query('DROP TABLE IF EXISTS '.$prefix.$name);}}
