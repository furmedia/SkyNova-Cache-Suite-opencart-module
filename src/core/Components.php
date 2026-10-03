<?php
namespace FurMedia\Cache;
/** Loader facade keeps native signatures and event dispatch, including OC4.1 which ignores pre-event returns. */
class Components {
    private $native; private $bridge;
    public function __construct($native,Bridge $bridge){$this->native=$native;$this->bridge=$bridge;}
    public function __call($name,$args){return call_user_func_array(array($this->native,$name),$args);}
    public function controller($route,...$args){
        try{if($this->bridge->componentBefore($route,$args)){return $this->bridge->componentOutput();}}catch(\Exception $e){}catch(\Throwable $e){}
        $output=call_user_func_array(array($this->native,'controller'),array_merge(array($route),$args));
        try{$this->bridge->componentAfter($route,$output);}catch(\Exception $e){}catch(\Throwable $e){}
        return $output;
    }
}
