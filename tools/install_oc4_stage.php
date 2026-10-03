<?php
if(PHP_SAPI!=='cli'){exit(1);}
$rootDir=realpath(__DIR__.'/..');$stage=$rootDir.'/work/oc4-stage/shop';$private=json_decode(file_get_contents($rootDir.'/work/local-stage/private.json'),true);
$root=new mysqli('127.0.0.1','root','','',33319);
$dir=$root->query('SELECT @@datadir AS d')->fetch_assoc()['d'];
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$rootDir.'/work/local-stage/mysql-data'))){throw new Exception('Wrong MySQL instance');}
$root->query('CREATE DATABASE IF NOT EXISTS skynova_oc4_stage CHARACTER SET utf8mb4');$root->query("GRANT ALL ON skynova_oc4_stage.* TO 'fm_cache'@'localhost'");$root->close();
define('DIR_APPLICATION',$stage.'/install/');
require $stage.'/system/engine/registry.php';require $stage.'/system/engine/model.php';require $stage.'/system/library/db.php';require $stage.'/system/library/db/mysqli.php';require $stage.'/system/helper/general.php';require $stage.'/system/helper/db_schema.php';require $stage.'/install/model/install/install.php';
class SchemaLoader {public function helper($name){if($name!=='db_schema'){throw new Exception('Unexpected helper');}}}
$registry=new \Opencart\System\Engine\Registry();$registry->set('load',new SchemaLoader());
$installer=new \Opencart\Install\Model\Install\Install($registry);
$installer->database(array('db_driver'=>'mysqli','db_hostname'=>'127.0.0.1','db_username'=>'fm_cache','db_password'=>$private['db_password'],'db_database'=>'skynova_oc4_stage','db_port'=>33319,'db_prefix'=>'oc_','username'=>$private['admin_user'],'password'=>$private['admin_password'],'email'=>'fixture@example.invalid'));
echo "Native OC4 database installer completed on isolated test DB\n";
