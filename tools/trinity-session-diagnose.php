<?php
if(PHP_SAPI!=='cli'){exit(1);}
require '/home/trinityconcept/domains/trinityconcept.ro/public_html/config.php';
$jar=__DIR__.'/diagnose-'.getmypid().'.cookies';$c=curl_init('https://trinityconcept.ro/index.php?route=common/home');curl_setopt_array($c,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_COOKIEJAR=>$jar,CURLOPT_USERAGENT=>'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36'));curl_exec($c);curl_close($c);unset($c);
$id=null;foreach(file($jar) as $line){$parts=explode("\t",trim($line));if(count($parts)===7 && $parts[5]==='OCSESSID'){$id=$parts[6];}}
if(!$id){exit('No probe session');}$db=new mysqli(DB_HOSTNAME,DB_USERNAME,DB_PASSWORD,DB_DATABASE,DB_PORT);$statement=$db->prepare('SELECT data FROM `'.DB_PREFIX.'session` WHERE session_id=?');$statement->bind_param('s',$id);$statement->execute();$statement->bind_result($raw);$statement->fetch();$state=json_decode($raw,true);$statement->close();
$result=$db->query("SELECT value FROM `".DB_PREFIX."setting` WHERE `key`='config_theme' AND store_id=0");$row=$result->fetch_assoc();echo json_encode(array('theme'=>$row['value'],'session_fields'=>array_keys((array)$state)));unlink($jar);
