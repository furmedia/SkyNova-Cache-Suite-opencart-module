<?php
namespace FurMedia\Cache;
/** Candidate indexes require explicit selection; native indexes are never removed. */
class Dbindexes {
    private $db; private $prefix;
    public function __construct($db,$prefix) {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D',$prefix)) { throw new \InvalidArgumentException('Invalid DB prefix.'); }
        $this->db=$db;$this->prefix=$prefix;
    }
    public static function candidates() {
        return array('product_admin_sort'=>array('product',array('sort_order','product_id')),'product_admin_model'=>array('product',array('model','product_id')),'product_description_search'=>array('product_description',array('language_id','name','product_id')),'seo_query_lookup'=>array('seo_url',array('query','store_id','language_id')),'category_parent'=>array('category',array('parent_id','sort_order')),
          'category_path_reverse'=>array('category_path',array('path_id','category_id')),
          'product_manufacturer'=>array('product',array('manufacturer_id','status','date_available')),
          'special_customer'=>array('product_special',array('customer_group_id','product_id')),
          'discount_customer'=>array('product_discount',array('customer_group_id','product_id')),
          'order_product_lookup'=>array('order_product',array('product_id','order_id')));
    }
    public function inspect() {
        $out=array();
        $tables=$this->db->query('SHOW TABLES')->rows;$names=array();
        foreach($tables as $table){$names[]=reset($table);}
        foreach(self::candidates() as $id=>$candidate) {
            list($table,$columns)=$candidate;$full=$this->prefix.$table;
            if (!in_array($full,$names,true)) { continue; }
            $fields=$this->db->query('SHOW COLUMNS FROM `'.$full.'`')->rows;$fieldNames=array();
            foreach($fields as $field){$fieldNames[]=$field['Field'];}if(array_diff($columns,$fieldNames)){continue;}
            $indexes=$this->db->query('SHOW INDEX FROM `'.$full.'`')->rows;$groups=array();
            foreach($indexes as $row){$groups[$row['Key_name']][(int)$row['Seq_in_index']]=empty($row['Sub_part'])?$row['Column_name']:null;}
            $covered=false;foreach($groups as $parts){ksort($parts);if(array_slice(array_values($parts),0,count($columns))===$columns){$covered=true;}}
            $name='skynova_'.substr(hash('sha256',$id),0,16);
            $out[$id]=array('table'=>$full,'columns'=>$columns,'covered'=>$covered,'name'=>$name,'conflict'=>isset($groups[$name]));
        }
        return $out;
    }
    public function apply(array $ids) {
        if(count($ids)>5){throw new \InvalidArgumentException('Select at most 5 indexes.');}
        $report=$this->inspect();$selected=array_unique($ids);$added=array();
        foreach($selected as $id){if(!is_string($id) || !isset($report[$id])){throw new \InvalidArgumentException('Invalid index proposal.');}if(!$report[$id]['covered'] && $report[$id]['conflict']){throw new \RuntimeException('Index name already exists with other columns.');}}
        foreach($selected as $id){
            $row=$report[$id];if($row['covered']){continue;}
            $this->db->query('ALTER TABLE `'.$row['table'].'` ADD INDEX `'.$row['name'].'` (`'.implode('`,`',$row['columns']).'`)');$added[]=$id;
        }
        return $added;
    }
}
