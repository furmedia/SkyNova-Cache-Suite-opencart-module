<?php
/** Creates only marker-guarded customers in the isolated loopback DB. CLI only. */
if(PHP_SAPI!=='cli'){exit(1);}
require __DIR__.'/../src/core/bootstrap.php';
$v4=in_array('--oc4',$argv,true);$root=realpath(__DIR__.'/../work/local-stage');$work=realpath(__DIR__.'/../work/'.($v4?'oc4-stage':'local-stage'));
$db=new mysqli('127.0.0.1','root','',$v4?'skynova_oc4_stage':'furmedia_stage',33319);
$dir=$db->query('SELECT @@datadir AS d')->fetch_assoc()['d'];
if(strtolower(rtrim(str_replace('\\','/',$dir),'/'))!==strtolower(str_replace('\\','/',$root.'/mysql-data'))){throw new Exception('Wrong MySQL instance');}
$file=$work.'/completion-customer-fixture.json';
if(in_array('--rename',$argv,true)){
    $rows=json_decode(file_get_contents($file),true);$row=$rows[0];$id=(int)$row['id'];$customer=$db->query('SELECT lastname,email FROM oc_customer WHERE customer_id='.$id)->fetch_assoc();
    if(!$customer || $customer['lastname']!=='SkyNovaLocalFixture' || $customer['email']!==$row['email']){throw new Exception('Customer fixture identity mismatch');}
    $db->query("UPDATE oc_customer SET firstname='SkyNovaFixtureAChanged' WHERE customer_id=".$id);echo "Updated own fixture profile\n";exit;
}
if(in_array('--remove',$argv,true)){
    if(!is_file($file)){exit;}$rows=json_decode(file_get_contents($file),true);
    foreach($rows as $row){
        $id=(int)$row['id'];$customer=$db->query('SELECT lastname,email FROM oc_customer WHERE customer_id='.$id)->fetch_assoc();
        if(!$customer || $customer['lastname']!=='SkyNovaLocalFixture' || $customer['email']!==$row['email']){throw new Exception('Customer fixture identity mismatch');}
        foreach(array('cart','customer_ip','customer_login','customer_activity') as $table){
            if(!$db->query("SHOW TABLES LIKE 'oc_".$table."'")->num_rows){continue;}
            if($db->query("SHOW COLUMNS FROM oc_".$table." LIKE 'customer_id'")->num_rows){$db->query('DELETE FROM oc_'.$table.' WHERE customer_id='.$id);}
        }
        $db->query('DELETE FROM oc_customer WHERE customer_id='.$id);
    }
    unlink($file);echo "Own local customer fixtures removed\n";exit;
}
if(is_file($file)){throw new Exception('Existing customer fixture');}
$rows=array();
foreach(array('A','B') as $suffix){
    $password=bin2hex(\FurMedia\Cache\Entropy::bytes(20));$email='skynova-cache-'.strtolower($suffix).'-'.bin2hex(\FurMedia\Cache\Entropy::bytes(5)).'@example.invalid';
    $values=array('customer_group_id'=>1,'store_id'=>0,'language_id'=>1,'firstname'=>'SkyNovaFixture'.$suffix,'lastname'=>'SkyNovaLocalFixture','email'=>$email,'telephone'=>'000','password'=>$v4?password_hash($password,PASSWORD_DEFAULT):md5($password),'status'=>1);
    $columns=$db->query('SHOW COLUMNS FROM oc_customer');$sql=array();
    while($column=$columns->fetch_assoc()){
        $name=$column['Field'];if(!preg_match('/^[a-z0-9_]+$/D',$name) || stripos($column['Extra'],'auto_increment')!==false){continue;}
        if(isset($values[$name])){$value=$values[$name];}
        elseif($column['Default']!==null || $column['Null']==='YES'){continue;}
        elseif(preg_match('/^date|timestamp/',$column['Type'])){$value=gmdate('Y-m-d H:i:s');}
        elseif(preg_match('/int|decimal|float|double/',$column['Type'])){$value=0;}else{$value='';}
        $sql[]='`'.$name."`='".$db->real_escape_string((string)$value)."'";
    }
    $db->query('INSERT INTO oc_customer SET '.implode(',',$sql));$rows[]=array('id'=>$db->insert_id,'email'=>$email,'password'=>$password);
    file_put_contents($file,json_encode($rows));
}
echo "Created two isolated native login customers; credentials remain in ignored runtime\n";
