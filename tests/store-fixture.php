<?php
if(PHP_SAPI!=='cli'){exit(1);}
$base=realpath(__DIR__.'/../work/local-stage');$db=new mysqli('127.0.0.1','root','','furmedia_stage',33319);
$dir=$db->query('SELECT @@datadir AS d')->fetch_assoc()['d'];
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$base.'/mysql-data'))){throw new Exception('Wrong test instance');}
// Direct fixture DB setup must mirror the native store model's cache invalidation.
foreach(glob($base.'/storage/cache/cache.store.*')?:array() as $cacheFile){if(is_file($cacheFile)&&!is_link($cacheFile)){unlink($cacheFile);}}
$file=$base.'/multistore-fixture.json';
if(isset($argv[1]) && $argv[1]==='remove'){
    $id=(int)json_decode(file_get_contents($file),true)['store_id'];
    $row=$db->query('SELECT name FROM oc_store WHERE store_id='.$id)->fetch_assoc();
    if(!$row || $row['name']!=='SkyNova isolated fixture'){throw new Exception('Fixture identity mismatch');}
    $db->query("DELETE FROM oc_setting WHERE store_id=".$id." AND code='module_furmedia_cache'");
    $db->query('DELETE FROM oc_store WHERE store_id='.$id);
    echo "Isolated fixture store removed\n";exit;
}
$db->query("INSERT INTO oc_store SET name='SkyNova isolated fixture',url='http://127.0.0.1:8796/fixture-store/',`ssl`='http://127.0.0.1:8796/fixture-store/'");
file_put_contents($file,json_encode(array('store_id'=>$db->insert_id)));
echo "Isolated multistore fixture created\n";
