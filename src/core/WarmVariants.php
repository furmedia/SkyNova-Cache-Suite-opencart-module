<?php
namespace FurMedia\Cache;
class WarmVariants {
    public static function parse($json) {
        if(trim((string)$json)===''){return array(array('headers'=>array()));}
        $config=json_decode($json,true);
        if(!is_array($config)){throw new \InvalidArgumentException('Crawler variants require a JSON object');}
        foreach($config as $key=>$value){if(!in_array($key,array('languages','currencies','agents'),true) || !is_array($value)){throw new \InvalidArgumentException('Invalid crawler variant field');}}
        $languages=isset($config['languages'])?$config['languages']:array('');$currencies=isset($config['currencies'])?$config['currencies']:array('');$agents=isset($config['agents'])?$config['agents']:array('');
        if(!$languages || !$currencies || !$agents || count($languages)*count($currencies)*count($agents)>32){throw new \InvalidArgumentException('Crawler requires 1 to 32 combinations');}
        $rows=array();
        foreach($languages as $language){foreach($currencies as $currency){foreach($agents as $agent){
            if(!is_string($language) || ($language!=='' && !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$language)) || !is_string($currency) || ($currency!=='' && !preg_match('/^[A-Z]{3}$/D',$currency)) || !is_string($agent) || strlen($agent)>512 || preg_match('/[\x00-\x1f\x7f]/',$agent)){throw new \InvalidArgumentException('Invalid crawler language, currency or user agent');}
            $cookies=array();if($language!==''){$cookies[]='language='.$language;}if($currency!==''){$cookies[]='currency='.$currency;}
            $headers=array();if($cookies){$headers[]='Cookie: '.implode('; ',$cookies);}if($agent!==''){$headers[]='User-Agent: '.$agent;}
            $rows[]=array('headers'=>$headers,'language'=>$language,'currency'=>$currency,'agent'=>$agent);
        }}}
        return $rows;
    }
}
