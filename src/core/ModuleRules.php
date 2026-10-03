<?php
namespace FurMedia\Cache;
class ModuleRules {
 public static function parse($text){
  if(trim($text)===''){return array();}$rows=json_decode($text,true);
  if(!is_array($rows)||count($rows)>100){throw new \InvalidArgumentException('Module rules require JSON, maximum 100 routes');}
  foreach($rows as $route=>$row){
   if(!preg_match('~^(?:extension/(?:[a-z0-9_]+/)?module|information)/[a-z0-9_/]+$~D',$route)||preg_match('/cart|account|checkout|payment|journal|captcha|consent/i',$route)||!is_array($row)){throw new \InvalidArgumentException('Invalid module route');}
   self::validate($row,true);
   if(isset($row['instances'])){if(!is_array($row['instances'])||count($row['instances'])>100){throw new \InvalidArgumentException('Invalid instances');}foreach($row['instances'] as $id=>$rule){if(!preg_match('/^(module|layout):[1-9][0-9]*$/D',$id)||!is_array($rule)){throw new \InvalidArgumentException('Invalid instance selector');}self::validate($rule,false);}}
  }return $rows;
 }
 private static function validate(array $row,$instances){foreach($row as $key=>$v){if(!in_array($key,$instances?array('enabled','ttl','instances'):array('enabled','ttl'),true)){throw new \InvalidArgumentException('Unknown module option');}}if(isset($row['enabled'])&&!in_array($row['enabled'],array(true,false,0,1,'0','1'),true)){throw new \InvalidArgumentException('Invalid enabled flag');}if(isset($row['ttl'])&&(!is_numeric($row['ttl'])||$row['ttl']<5||$row['ttl']>86400)){throw new \InvalidArgumentException('Module TTL must be 5–86400');}}
 public static function resolve($text,$route,array $args,$fallback){$rows=self::parse($text);if(!isset($rows[$route])){return null;}$row=$rows[$route];$data=isset($args[0])&&is_array($args[0])?$args[0]:array();$id='';foreach(array('module_id'=>'module','layout_id'=>'layout') as $field=>$kind){if(isset($data[$field])&&ctype_digit((string)$data[$field])&&(int)$data[$field]>0){$id=$kind.':'.(int)$data[$field];break;}}if($id&&isset($row['instances'][$id])){$row=array_merge($row,$row['instances'][$id]);}return array('enabled'=>!isset($row['enabled'])||(bool)$row['enabled'],'ttl'=>isset($row['ttl'])?(int)$row['ttl']:(int)$fallback,'tag'=>'module:'.$route.($id?':'.$id:''));}
 public static function presets($v4){$rows=array();foreach(array('category','latest','featured','information','filter','banner','slideshow','carousel','store') as $name){$rows[($v4?'extension/opencart/module/':'extension/module/').$name]=array('enabled'=>false,'ttl'=>60);}return $rows;}
}
