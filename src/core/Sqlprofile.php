<?php
namespace FurMedia\Cache;
/** Logs digests and normalized shapes only, never SQL literal values. */
class Sqlprofile {
 private $native;private $history;private $threshold;private $calls=0;private $root;private $area;private $samples=array();
 public function __construct($native,$root,$threshold,$area='catalog'){$this->root=$root;$this->area=$area==='admin'?'admin':'catalog';$this->native=$native;$this->history=new History($root);$this->threshold=$threshold;}
 public function __call($name,$args){return call_user_func_array(array($this->native,$name),$args);}
 public static function shape($sql){$sql=preg_replace('~/\*.*?\*/~s','',substr($sql,0,16384));$sql=preg_replace('/--[^\r\n]*|#[^\r\n]*/','',$sql);$sql=preg_replace("~'(?:''|\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"~s",'?',substr($sql,0,16384));if(preg_match('/[\x22\x27]/',$sql)||preg_match('/`[^`]*[^a-zA-Z0-9_`][^`]*`/',$sql)){return '[unparsed SELECT]';}$sql=preg_replace('/\b0x[a-f0-9]+\b/i','?',$sql);$sql=preg_replace('/\b[0-9]+(?:\.[0-9]+)?\b/','?',$sql);return preg_replace('/\s+/',' ',trim($sql));}
 public function flush(){if($this->samples){$samples=$this->samples;$this->samples=array();try{Sqlmetrics::append($this->root.'/metrics',$samples);}catch(\Exception $e){}}}
 public function __destruct(){$this->flush();}
 public function query($sql){$start=microtime(true);$ok=false;try{$result=$this->native->query($sql);$ok=true;return $result;}finally{$ms=(microtime(true)-$start)*1000;if(is_string($sql)&&preg_match('/^\s*SELECT\b/i',$sql)&&!preg_match('/skynova_(?:bak|restore|meta|prev|ngram)_/i',$sql)&&++$this->calls<=200){$shape=self::shape($sql);$sample=array('digest'=>hash('sha256',$shape),'shape'=>$shape,'ms'=>round($ms,3),'area'=>$this->area,'ok'=>$ok);$this->samples[]=$sample;if(count($this->samples)>=50){$this->flush();}if($ms>=$this->threshold&&$this->calls<=30){try{$this->history->append('sqlprofile',$sample);}catch(\Exception $e){}}}}}
}
