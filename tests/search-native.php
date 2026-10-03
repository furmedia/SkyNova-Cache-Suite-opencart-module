<?php
if(PHP_SAPI!=='cli'){exit(1);}require __DIR__.'/../src/core/bootstrap.php';
$native=new mysqli('127.0.0.1','root','','furmedia_stage',33319);$dir=$native->query('SELECT @@datadir AS d')->fetch_assoc()['d'];$expected=realpath(__DIR__.'/../work/local-stage/mysql-data');
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$expected))){throw new Exception('Wrong isolated MySQL');}
class SearchTestDb {private $db;public function __construct($db){$this->db=$db;}public function escape($v){return $this->db->real_escape_string($v);}public function query($sql){$q=$this->db->query($sql);$r=new stdClass();$r->rows=array();if($q instanceof mysqli_result){while($row=$q->fetch_assoc()){$r->rows[]=$row;}}$r->row=$r->rows?$r->rows[0]:array();return $r;}}
$db=new SearchTestDb($native);$prefix='skynova_search_test_';$table=$prefix.'product_description';$checks=0;$id=null;$archiveId=null;
function searchAssert($ok,$name){global $checks;if(!$ok){throw new Exception($name);}$checks++;}
searchAssert($native->query("SHOW TABLES LIKE '".$table."'")->num_rows===0,'Fixture absent');$root=__DIR__.'/../work/local-stage/search-test-'.bin2hex(FurMedia\Cache\Entropy::bytes(6));
try{
 $db->query('CREATE TABLE '.$table.' (product_id INT,language_id INT,name VARCHAR(255),PRIMARY KEY(product_id,language_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci ENGINE=InnoDB');
 $names=array('Cristal Amethyst','Cristal ametist','Amber','Ámber','Șirag cristal','Straße cristal','Other item','A% literal');foreach($names as $i=>$name){$db->query("INSERT INTO ".$table." VALUES (".($i+1).",1,'".$db->escape($name)."')");}
 $index=new FurMedia\Cache\SearchIndex($db,$prefix,$root,$root.'/archives');$s=$index->start();$id=$s['id'];$archiveId=$s['archive'];for($i=0;$i<10&&$index->status()['job']['phase']!=='ready';$i++){$index->step(3);}
 searchAssert($index->status()['job']['phase']==='ready','Resumable search build ready');
 foreach(array('cristal','CRISTAL','amber','ámber','Șirag','Straße','not found') as $word){$sql="SELECT pd.product_id FROM ".$table." pd WHERE pd.name LIKE '%".$db->escape($word)."%' ORDER BY pd.product_id";$rewritten=$index->rewrite($sql);searchAssert($rewritten!==$sql,'Candidate rewrite '.$word);searchAssert($db->query($sql)->rows===$db->query($rewritten)->rows,'Native result parity '.$word);}
 foreach(array('a','ab','a% literal','a_b') as $word){$sql="SELECT pd.product_id FROM ".$table." pd WHERE pd.name LIKE '%".$db->escape($word)."%'";searchAssert($index->rewrite($sql)===$sql,'Unsupported pattern native fallback');}
 $sql="SELECT 'pd.name LIKE ''%cristal%''' AS literal FROM ".$table.' pd';searchAssert($index->rewrite($sql)===$sql,'Quoted SQL-looking text untouched');
 $proxy=new FurMedia\Cache\Searchquery($db,$index,$prefix,$root);$proxy->query("UPDATE `".$table."` SET name='New Crystal' WHERE product_id=1");searchAssert(!$index->status()['active'],'Native write invalidates index before mutation');
 file_put_contents(__DIR__.'/../docs/validation/search-native.json',json_encode(array('scope'=>'own isolated loopback MySQL','assertions'=>$checks,'passed'=>true),JSON_PRETTY_PRINT));
 echo 'PASS '.$checks." search native assertions\n";
}finally{$db->query('DROP TABLE IF EXISTS '.$table);if($id){$db->query('DROP TABLE IF EXISTS skynova_ngram_'.$id);}if($archiveId){$db->query('DROP TABLE IF EXISTS skynova_bak_'.$archiveId);}}
