<?php
if(PHP_SAPI!=='cli'){exit(1);}
$base=realpath(__DIR__.'/../work/local-stage');
$private=json_decode(file_get_contents($base.'/private.json'),true);
$root=new mysqli('127.0.0.1','root','','',33319);
$dir=$root->query('SELECT @@datadir AS d')->fetch_assoc()['d'];
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$base.'/mysql-data'))){throw new Exception('Wrong MySQL instance');}
$root->query('CREATE DATABASE IF NOT EXISTS furmedia_stage CHARACTER SET utf8mb4');
$secret=$root->real_escape_string($private['db_password']);
$root->query("CREATE USER IF NOT EXISTS 'fm_cache'@'localhost' IDENTIFIED BY '".$secret."'");
$root->query("GRANT ALL ON furmedia_stage.* TO 'fm_cache'@'localhost'");
$root->close();
define('DIR_APPLICATION',$base.'/shop/install/');
require $base.'/shop/system/engine/model.php';require $base.'/shop/system/engine/registry.php';require $base.'/shop/system/library/db.php';require $base.'/shop/system/library/db/mysqli.php';require $base.'/shop/system/helper/general.php';require $base.'/shop/install/model/install/install.php';
$model=new ModelInstallInstall(new Registry());
$model->database(array('db_driver'=>'mysqli','db_hostname'=>'127.0.0.1','db_username'=>'fm_cache','db_password'=>$private['db_password'],'db_database'=>'furmedia_stage','db_port'=>33319,'db_prefix'=>'oc_','username'=>$private['admin_user'],'password'=>$private['admin_password'],'email'=>'test@example.invalid'));
$db=new mysqli('127.0.0.1','fm_cache',$private['db_password'],'furmedia_stage',33319);
$db->query("UPDATE oc_setting SET value='http://127.0.0.1:8796/' WHERE `key` IN ('config_url','config_ssl')");
$db->query("UPDATE oc_setting SET value='0' WHERE `key` IN ('config_mail_alert','config_review_mail','config_affiliate_auto','config_customer_online')");
echo "Installed sample-only OC3 database on guarded private MySQL instance.\n";
