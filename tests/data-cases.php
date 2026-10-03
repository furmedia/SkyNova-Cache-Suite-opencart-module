<?php
use FurMedia\Cache\Settings;
use FurMedia\Cache\Assets;
use FurMedia\Cache\Optimizer;
// Included in core.php: feature behavior rather than implementation snapshots.
class DataNativeDb {
    public $reads=0; public $value='first'; public $indexes=array();
    public function escape($x){return addslashes($x);}
    public function query($sql){
        if($sql==='SHOW TABLES'){return (object)array('rows'=>array(array('Tables'=>'oc_category')));}
        if(strpos($sql,'SHOW COLUMNS')===0){return (object)array('rows'=>array(array('Field'=>'parent_id'),array('Field'=>'sort_order')));}
        if(strpos($sql,'SHOW INDEX')===0){return (object)array('rows'=>$this->indexes);}
        if(strpos($sql,'ALTER TABLE')===0){preg_match('/ADD INDEX `([^`]+)`/',$sql,$m);$this->indexes[]=array('Key_name'=>$m[1],'Seq_in_index'=>1,'Column_name'=>'parent_id','Sub_part'=>null);$this->indexes[]=array('Key_name'=>$m[1],'Seq_in_index'=>2,'Column_name'=>'sort_order','Sub_part'=>null);return true;}
        if(preg_match('/^SELECT/',$sql)){$this->reads++;return (object)array('rows'=>array(array('name'=>$this->value)),'row'=>array('name'=>$this->value),'num_rows'=>1);}
        $this->value='changed';return true;
    }
}
$sql='SELECT * FROM oc_category WHERE category_id = 20';$ds=Settings::normalize(array('sql_cache'=>1,'sql_allow'=>$sql));$db=new DataNativeDb();$scope='A';$enabled=true;
$cache=new \FurMedia\Cache\Sqlcache($db,$store,$ds,function()use(&$scope,&$enabled){return $enabled?array('session'=>$scope):false;},'oc_');
check($cache->approved($sql),'exact SELECT approved');$cache->query($sql);$cache->query($sql);check($db->reads===1,'SQL repeated read cached');
$scope='B';$cache->query($sql);check($db->reads===2,'SQL session isolation');$scope='A';$enabled=false;$cache->query($sql);check($db->reads===3,'SQL private state bypass');$enabled=true;
$cache->query('UPDATE oc_category SET status=1');check($cache->query($sql)->row['name']==='changed' && $db->reads===4,'SQL mutation invalidates stale result');
$cache->query('START TRANSACTION');$cache->query($sql);$cache->query($sql);check($db->reads===6,'SQL transaction bypass');$cache->query('COMMIT');$cache->query($sql);$cache->query($sql);check($db->reads===7,'SQL commit resumes cache');
check($cache->escape("a'b")==="a\\'b",'native DB API forwarded');
foreach(array('SELECT * FROM oc_customer','SELECT * FROM oc_category; SELECT 1','SELECT SLEEP(1) FROM oc_category','SELECT * FROM oc_category FOR UPDATE','SELECT * FROM oc_category, oc_customer','SELECT * FROM oc_category UNION SELECT * FROM oc_customer','SELECT * FROM oc_category -- comment') as $unsafe){$o=$ds;$o['sql_allow']=$unsafe;$c=new \FurMedia\Cache\Sqlcache($db,$store,$o,function(){return array('guest'=>1);},'oc_');check(!$c->approved($unsafe),'SQL unsafe rejected');}
$recursive=null;$guardDb=new DataNativeDb();$recursive=new \FurMedia\Cache\Sqlcache($guardDb,$store,$ds,function()use(&$recursive,$sql){$recursive->query($sql);return array('scope'=>'recursive');},'oc_');check($recursive->query($sql)->num_rows===1,'context DB query does not recurse');
$idx=new \FurMedia\Cache\Dbindexes($db,'oc_');check(!$idx->inspect()['category_parent']['covered'],'missing composite detected');check(count($idx->apply(array('category_parent')))===1,'selected index added');check($idx->inspect()['category_parent']['covered'],'leftmost composite recognized');check($idx->apply(array('category_parent'))===array(),'index apply idempotent');
$db->indexes=array(array('Key_name'=>'reverse','Seq_in_index'=>1,'Column_name'=>'sort_order','Sub_part'=>null),array('Key_name'=>'reverse','Seq_in_index'=>2,'Column_name'=>'parent_id','Sub_part'=>null));check(!$idx->inspect()['category_parent']['covered'],'reverse index does not cover candidate');
$failed=false;try{$idx->apply(array('injected`sql'));}catch(Exception $e){$failed=true;}check($failed,'unknown index ID rejected');
file_put_contents($tmp.'/shop/catalog/view/one.js','window.bundleOrder=[1];');file_put_contents($tmp.'/shop/catalog/view/two.js','window.bundleOrder.push(2);');
file_put_contents($tmp.'/shop/catalog/view/one.css','.one { color: red; background: url(../../image/a.png); }');file_put_contents($tmp.'/shop/catalog/view/two.css','.two { color: blue; }');
$bs=Settings::normalize(array('css_merge'=>1,'js_merge'=>1,'merge_allow'=>"catalog/view/one.js\ncatalog/view/two.js\ncatalog/view/one.css\ncatalog/view/two.css"));$ba=new Assets($tmp.'/shop',$tmp.'/shop/image/cache/furmedia_cache','https://shop.example/',$bs);$bo=new Optimizer($bs,$ba);
$js='<script src="catalog/view/one.js"></script> <script src="catalog/view/two.js"></script>';$merged=$bo->transform($js);check(substr_count($merged,'<script')===1,'adjacent approved JS combined');$bundle=$ba->bundle(array('catalog/view/one.js','catalog/view/two.js'),'js');check(strpos(file_get_contents($ba->local($bundle)),'[1]')<strpos(file_get_contents($ba->local($bundle)),'push(2)'),'bundle preserves order');
check(substr_count($bo->transform(str_replace('src=','defer src=',$js)),'<script')===2,'defer boundary preserved');check(substr_count($bo->transform(str_replace('</script> ','</script><script>window.inline=1;</script>',$js)),'<script')===3,'inline execution boundary preserved');
check($bo->transform('<pre>'.$js.'</pre>')==='<pre>'.$js.'</pre>','raw-text merge protection');
$css='<link rel="stylesheet" href="catalog/view/one.css"><link rel="stylesheet" href="catalog/view/two.css">';check(substr_count($bo->transform($css),'<link')===1,'adjacent CSS combined');check(substr_count($bo->transform(str_replace('href="catalog/view/two.css"','media="print" href="catalog/view/two.css"',$css)),'<link')===2,'CSS media boundary preserved');
file_put_contents($tmp.'/shop/catalog/view/two.js','"use strict";window.bundleOrder.push(2);');check(substr_count($bo->transform($js),'<script')===2,'strict script scope preserved');

$indexHistory=new \FurMedia\Cache\History($tmp.'/index-history');check($indexHistory->append('indexes',array('added'=>array('category_parent'))),'index history supports bounded audit');check(count($indexHistory->rows('indexes'))===1,'index audit retained');
