<?php
namespace FurMedia\Cache;
class Searchquery {
    private $native;private $index;private $prefix;private $root;
    public function __construct($native,SearchIndex $index,$prefix,$root){$this->native=$native;$this->index=$index;$this->prefix=$prefix;$this->root=$root;}
    public function __call($name,$args){return call_user_func_array(array($this->native,$name),$args);}
    public function query($sql){if(is_string($sql)&&preg_match('/^\s*(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|TRUNCATE\s+(?:TABLE\s+)?|DROP\s+TABLE)\s+`?'.preg_quote($this->prefix,'/').'(?:product_description|product)`?\b/i',$sql)){SearchIndex::invalidate($this->root);}elseif(is_string($sql)&&preg_match('/^\s*RENAME\s+TABLE\b/i',$sql)&&preg_match('/\b'.preg_quote($this->prefix,'/').'(?:product_description|product)\b/',$sql)){SearchIndex::invalidate($this->root);}
        $generation=$this->index->generation();$optimized=$this->index->rewrite($sql);if($optimized===$sql){return $this->native->query($sql);}
        try{$result=$this->native->query($optimized);}catch(\Exception $e){SearchIndex::invalidate($this->root);return $this->native->query($sql);}
        return $generation===$this->index->generation()?$result:$this->native->query($sql);
    }
}
