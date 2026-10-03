<?php
namespace FurMedia\Cache;
/** Discover inline code by hash; transform only explicit independent-script approvals. */
class Scripts {
    public static function unsafe($body) {return (bool)preg_match('/document\s*\.\s*(?:write|writeln|currentScript)|\b(?:csrf|nonce|token|session_id|user_token|customer_token)\b|\beval\s*\(/i',$body);}
    public static function inventory($html) {
        $rows=array();
        preg_replace_callback('~<(pre|textarea|style)\b[^>]*>.*?</\1\s*>|<!--[\s\S]*?-->|<script\b([^>]*)>(.*?)</script\s*>~is',function($m)use(&$rows){
            if(!isset($m[2],$m[3]) || preg_match('/\ssrc\s*=/i',$m[2]) || trim($m[3])===''){return $m[0];}
            $rows[]=array('hash'=>hash('sha256',$m[3]),'bytes'=>strlen($m[3]),'blocked'=>self::unsafe($m[3]) || !preg_match('/^\s*(?:type\s*=\s*([\x22\x27])(?:text|application)\/javascript\1\s*)?$/iD',$m[2]));
            return $m[0];
        },$html);
        return array_slice($rows,0,200);
    }
    public static function transform($html,Assets $assets,array $settings) {
        if(!$settings['extract_js'] && $settings['script_position']==='native'){return $html;}
        $approved=Settings::lines($settings['script_allow']);$moved=array();
        $out=preg_replace_callback('~<(pre|textarea|style)\b[^>]*>.*?</\1\s*>|<!--[\s\S]*?-->|<script\b([^>]*)>(.*?)</script\s*>~is',function($m)use($assets,$settings,$approved,&$moved){
            if(!isset($m[2],$m[3])){return $m[0];}$attributes=$m[2];$body=$m[3];$tag=$m[0];
            if(preg_match('~\ssrc\s*=\s*([\x22\x27])(.*?)\1~is',$attributes,$match)){
                $url=html_entity_decode($match[2],ENT_QUOTES,'UTF-8');
                $rest=preg_replace('~\s(?:src|type)\s*=\s*([\x22\x27]).*?\1~is','',$attributes);
                if(trim($rest)!=='' || trim($body)!=='' || !in_array($url,$approved,true) || $assets->excluded($url)){return $tag;}
                $file=$assets->local($url);if(!$file || filesize($file)>2097152 || self::unsafe(file_get_contents($file))){return $tag;}
                if(preg_match('~\stype\s*=\s*([\x22\x27])(.*?)\1~is',$attributes,$type) && !in_array(strtolower($type[2]),array('text/javascript','application/javascript'),true)){return $tag;}
            }else{
                if(!$settings['extract_js'] || self::unsafe($body) || !in_array('sha256:'.hash('sha256',$body),$approved,true) || !preg_match('/^\s*(?:type\s*=\s*([\x22\x27])(?:text|application)\/javascript\1\s*)?$/iD',$attributes)){return $tag;}
                $url=$assets->script($body);if(!$url){return $tag;}$tag='<script src="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"></script>';
            }
            if($settings['script_position']==='native'){return $tag;}$moved[]=$tag;return '<!--skynova-script-position-->';
        },$html);
        if(!$moved){return $out;}
        $boundary=$settings['script_position']==='top'?'head':'body';
        if(!preg_match('~</'.$boundary.'\s*>~i',$out)){return $html;}
        return preg_replace_callback('~</'.$boundary.'\s*>~i',function($m)use($moved){return implode("\n",$moved).$m[0];},$out,1);
    }
}
