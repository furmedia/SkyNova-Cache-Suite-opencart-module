<?php
namespace FurMedia\Cache;

/** Journal 3.2.10 presentation state, captured before native footer/header rendering.
 * No Journal source, registry, customer, session or controller objects are serialized.
 */
class JournalState {
    private static function layoutAvailable() {
        if(!class_exists('ControllerJournal3EventLayout',false) && defined('DIR_APPLICATION') && is_file(DIR_APPLICATION.'controller/journal3/event/layout.php')){require_once DIR_APPLICATION.'controller/journal3/event/layout.php';}
        return class_exists('ControllerJournal3EventLayout',false);
    }
    private static function property($object,$name,$value=null,$write=false) {
        $reflection=is_object($object)?new \ReflectionObject($object):new \ReflectionClass($object);
        $property=$reflection->getProperty($name);$property->setAccessible(true);
        if($write){$property->setValue(is_object($object)?$object:null,$value);return;}
        return $property->getValue(is_object($object)?$object:null);
    }
    public static function baseline($registry) {
        if(!defined('JOURNAL3_VERSION') || JOURNAL3_VERSION!=='3.2.10' || !$registry->has('journal3_document')){return null;}
        $doc=$registry->get('journal3_document');
        if(get_class($doc)!=='Journal3\\Document'){return null;}
        $state=array();
        foreach(array('classes','css','js','fonts','metas','links') as $field){$state[$field]=self::property($doc,$field);}
        return $state;
    }
    public static function capture($registry,array $baseline) {
        $current=self::baseline($registry);if(!$current){return null;}
        $state=array('version'=>'3.2.10');
        foreach(array('classes','js') as $field){$state[$field]=array();foreach($current[$field] as $key=>$value){if(!array_key_exists($key,$baseline[$field]) || $baseline[$field][$key]!==$value){$state[$field][$key]=$value;}}}
        foreach(array('css','metas','links') as $field){$state[$field]=array();foreach($current[$field] as $row){if(!in_array($row,$baseline[$field],true)){$state[$field][]=$row;}}}
        $state['fonts']=$current['fonts'];$doc=$registry->get('journal3_document');
        $state['page_route']=$doc->getPageRoute();$state['page_id']=$doc->getPageId();
        $state['settings']=array();foreach(array('layout_id','columnsCount','headerDesktop','headerMobile','footerMenu','footerMenuPhone') as $key){$state['settings'][$key]=$registry->get('journal3')->get($key);}
        if(!self::layoutAvailable()){return null;}
        $layout=self::property('ControllerJournal3EventLayout','layout');$state['layout']=array();
        foreach(array('top','bottom','footer_top','footer_bottom','popup','notification','header_notice','bottom_menu','side_menu','background_slider') as $key){if(isset($layout[$key])){$state['layout'][$key]=$layout[$key];}}
        $state['layout_id']=self::property('ControllerJournal3EventLayout','layout_id');
        return self::safe($state)?$state:null;
    }
    private static function scalarTree($value,$depth=0) {
        if($depth>20 || is_object($value) || is_resource($value)){return false;}
        if(is_array($value)){foreach($value as $key=>$item){if(preg_match('/(?:token|nonce|session|customer|csrf|password|secret)/i',(string)$key) || !self::scalarTree($item,$depth+1)){return false;}}}
        return true;
    }
    public static function safe($state) {
        if(!is_array($state) || !isset($state['version']) || $state['version']!=='3.2.10' || !self::scalarTree($state)){return false;}
        foreach(array('classes','css','js','fonts','metas','links','settings','layout') as $field){if(!isset($state[$field]) || !is_array($state[$field])){return false;}}
        foreach(array('page_route','page_id','layout_id') as $field){if(!array_key_exists($field,$state)){return false;}}
        if(!is_string($state['page_route']) || !preg_match('~^(?:common/home|product/(?:category|product|manufacturer(?:/info)?)|information/information)$~D',$state['page_route'])){return false;}
        foreach(array('page_id','layout_id') as $field){if($state[$field]!==null && (!is_int($state[$field]) || $state[$field]<0)){return false;}}
        if(array_diff(array_keys($state['settings']),array('layout_id','columnsCount','headerDesktop','headerMobile','footerMenu','footerMenuPhone')) || array_diff(array_keys($state['layout']),array('top','bottom','footer_top','footer_bottom','popup','notification','header_notice','bottom_menu','side_menu','background_slider'))){return false;}
        $json=json_encode($state);if(!is_string($json) || strlen($json)>2097152){return false;}
        // Scan decoded string leaves as well as JSON: escaping must not hide a token field.
        $strings=array();array_walk_recursive($state,function($value)use(&$strings){if(is_string($value)){$strings[]=$value;}});
        return (new Policy())->responseAllowed(implode("\n",$strings).'</html>',array(),200);
    }
    public static function restore($registry,array $state) {
        if(!self::baseline($registry) || !self::safe($state) || !self::layoutAvailable()){throw new \RuntimeException('Journal presentation state unavailable');}
        $doc=$registry->get('journal3_document');
        foreach(array('classes','js') as $field){self::property($doc,$field,array_merge(self::property($doc,$field),$state[$field]),true);}
        foreach(array('css','metas','links') as $field){$rows=self::property($doc,$field);foreach($state[$field] as $row){if(!in_array($row,$rows,true)){$rows[]=$row;}}self::property($doc,$field,$rows,true);}
        self::property($doc,'fonts',array_replace_recursive(self::property($doc,'fonts'),$state['fonts']),true);
        $doc->setPageRoute($state['page_route']);$doc->setPageId($state['page_id']);
        foreach($state['settings'] as $key=>$value){$registry->get('journal3')->set($key,$value);}
        self::property('ControllerJournal3EventLayout','layout',$state['layout'],true);
        self::property('ControllerJournal3EventLayout','layout_id',$state['layout_id'],true);
    }
    public static function deduplicateCss($registry) {
        $doc=$registry->get('journal3_document');$rows=self::property($doc,'css');$seen=array();$unique=array();
        foreach($rows as $row){$key=serialize(array($row['id'],$row['css']));if(!isset($seen[$key])){$seen[$key]=true;$unique[]=$row;}}
        self::property($doc,'css',$unique,true);
    }
}
