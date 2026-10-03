<?php
namespace FurMedia\Cache;
/** Signed, generation-bound native module descriptors; no arbitrary controller proxy. */
class Esi {
    private $store;
    private $vault;
    private $rules;
    private static function plain($value,$depth=0) {
        if($depth>10){return false;}if($value===null || is_scalar($value)){return true;}
        if(!is_array($value) || count($value)>2000){return false;}
        foreach($value as $item){if(!self::plain($item,$depth+1)){return false;}}return true;
    }
    public function __construct($root,$rules) {
        $this->store=new FileStore($root,array('max_entry_kb'=>128,'max_entries'=>2000,'max_mb'=>32));
        $this->vault=new Vault($root.'-signing');$this->rules=self::parse($rules);
    }
    public static function parse($json) {
        if(trim((string)$json)===''){return array();}$rows=json_decode($json,true);
        if(!is_array($rows) || count($rows)>50){throw new \InvalidArgumentException('ESI requires a JSON object with at most 50 modules');}$out=array();
        foreach($rows as $route=>$row){
            if(!is_string($route) || !preg_match('~^(?:extension/(?:[a-z0-9_]+/)?module/[a-z0-9_]+|common/(?:cart|header|footer)|journal3/module/[a-z0-9_]+)$~D',$route) || !is_array($row)){throw new \InvalidArgumentException('Invalid ESI module route');}
            foreach($row as $key=>$value){if(!in_array($key,array('scope','ttl','args'),true)){throw new \InvalidArgumentException('Unknown ESI module field');}}
            $scope=isset($row['scope'])?$row['scope']:'private';$args=isset($row['args'])?$row['args']:null;
            if(!self::plain($args) || (isset($row['ttl']) && !is_numeric($row['ttl']))){throw new \InvalidArgumentException('Invalid ESI TTL or argument structure');}
            if(!in_array($scope,array('public','private','no-cache'),true) || ($args!==null && !is_array($args)) || strlen(json_encode($args))>32768 || preg_match('/token|nonce|password|secret|session_id|user_token/i',json_encode($args))){throw new \InvalidArgumentException('Invalid ESI scope or arguments');}
            if($scope==='public' && preg_match('/^common\/|journal|cart|account|checkout|payment|captcha|consent/i',$route)){throw new \InvalidArgumentException('Personal or theme modules cannot use public ESI');}
            $out[$route]=array('scope'=>$scope,'ttl'=>isset($row['ttl'])?max(0,min(3600,(int)$row['ttl'])):60,'args'=>$args);
        }
        return $out;
    }
    private static function context(array $context,$scope) {
        if($scope!=='public'){return $context;}
        $out=array();foreach(array('store','origin','language','currency','group','theme','user_agent','accept','https','host','settings') as $field){$out[$field]=isset($context[$field])?$context[$field]:null;}return $out;
    }
    public function includeModule($route,array $args,$body,array $context,$generation,$endpoint) {
        if(!self::plain($args) || json_encode($args)===false){return $body;}
        if(!isset($this->rules[$route]) || ($this->rules[$route]['args']!==null && $this->rules[$route]['args']!==$args) || strlen(json_encode($args))>32768 || preg_match('/token|nonce|password|secret|session_id|user_token/i',json_encode($args)) || !is_string($body) || strlen($body)>1048576){return $body;}
        $rule=$this->rules[$route];
        if($rule['scope']!=='no-cache' && (preg_match('/<form\b|\b(?:nonce|csrf|token)\b|(?:session|user_token)=/i',$body))){return $body;}
        if($rule['scope']==='public' && (empty($context['public_esi_safe']) || !empty($context['customer']) || !empty($context['cart']))){return $body;}
        $descriptor=array('route'=>$route,'rule'=>$rule,'args'=>$args,'context'=>hash('sha256',serialize(self::context($context,$rule['scope']))),'generation'=>$generation);
        $id=hash('sha256',serialize($descriptor));$signature=$this->vault->sign($id);
        if(!$this->store->set($id,$descriptor,86400,array('catalog'))){return $body;}
        return Litespeed::esi($endpoint.'&id='.$id.'&signature='.$signature,$rule['scope'],$rule['ttl'],'route:'.$route,$body);
    }
    public function descriptor($id,$signature,array $context,$generation) {
        if(!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id) || !is_string($signature) || !hash_equals($this->vault->sign($id),$signature)){throw new \RuntimeException('Invalid ESI signature');}
        $row=$this->store->get($id);
        if(!is_array($row) || !isset($row['route'],$row['rule'],$row['context'],$row['generation']) || $row['generation']!==$generation || !isset($this->rules[$row['route']]) || $row['rule']!==$this->rules[$row['route']] || !hash_equals($row['context'],hash('sha256',serialize(self::context($context,$row['rule']['scope']))))){throw new \RuntimeException('Expired ESI descriptor or changed context');}
        if($row['rule']['scope']==='public' && (empty($context['public_esi_safe']) || !empty($context['customer']) || !empty($context['cart']))){throw new \RuntimeException('Private ESI context');}return $row;
    }
    public static function headers(array $row,$body,array $headers) {
        $scope=$row['rule']['scope'];if($scope==='no-cache'){return array('X-LiteSpeed-Cache-Control: no-cache');}
        if(!is_string($body) || preg_match('/<form\b|\b(?:nonce|csrf|token)\b|(?:session|user_token)=/i',$body)){return array('X-LiteSpeed-Cache-Control: no-cache');}
        foreach($headers as $header){
            if(!is_string($header) || preg_match('/[\r\n]/',$header) || preg_match('/^(?:Set-Cookie|Location|Content-Disposition|Content-Encoding):/i',$header) || preg_match('~^HTTP/\S+\s+(?!200\b)~i',$header) || preg_match('~^Cache-Control:.*(?:no-store|no-cache)~i',$header) || (stripos($header,'Content-Type:')===0 && !preg_match('~^Content-Type:\s*text/html\b~i',$header))){return array('X-LiteSpeed-Cache-Control: no-cache');}
        }
        return array('X-LiteSpeed-Cache-Control: '.$scope.',max-age='.$row['rule']['ttl'],'X-LiteSpeed-Tag: '.($scope==='private'?'public:':'').Litespeed::tag('catalog').','.($scope==='private'?'public:':'').Litespeed::tag('route:'.$row['route']));
    }
}
