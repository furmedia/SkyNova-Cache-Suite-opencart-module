<?php
namespace FurMedia\Cache;
/** Private cache for approved dynamic CSS/JS responses, including safe response metadata. */
class Resources {
    public static function validate($text) {
        foreach(Settings::lines($text) as $uri){
            if(strlen($uri)>2048 || substr($uri,0,1)!=='/' || substr($uri,0,2)==='//' || preg_match('/[\x00-\x20\x7f\\\\]/',$uri)){throw new \InvalidArgumentException('Dynamic resources require exact relative request URIs');}
            $parsed=parse_url($uri);if(!$parsed || isset($parsed['host']) || isset($parsed['fragment']) || preg_match('/(?:token|nonce|session|account|checkout|admin|api\/|\.\.)/i',rawurldecode(rawurldecode($uri)))){throw new \InvalidArgumentException('Sensitive dynamic resource URI');}
        }
    }
    public static function eligible(array $request,array $context,array $settings) {
        return !empty($settings['dynamic_cache']) && $request['method']==='GET' && empty($request['authorization']) && empty($request['ajax']) && empty($request['range']) && empty($context['customer']) && empty($context['cart']) && empty($context['affiliate']) && empty($context['maintenance']) && !empty($context['session_id']) && !preg_match('~^(account|checkout|api|journal[23])/~i',$request['route']) && in_array($request['uri'],Settings::lines($settings['dynamic_urls']),true);
    }
    public static function headers(array $headers,$status) {
        if($status!==200){return false;}$safe=array();$type='';
        foreach($headers as $header){
            if(preg_match('~^Cache-Control:.*(?:no-store|no-cache)~i',$header)){return false;}
            if(!is_string($header) || preg_match('/[\r\n]/',$header) || preg_match('~^(?:Set-Cookie|Location|Content-Disposition|Content-Encoding):~i',$header) || preg_match('~^HTTP/\S+\s+(?!200\b)~i',$header) || preg_match('~^Vary:.*\*~i',$header)){return false;}
            if(preg_match('~^Content-Type:\s*(text/css|(?:text|application)/(?:javascript|x-javascript))\b~i',$header,$match)){$type=strtolower($match[1]);}
            if(preg_match('~^(?:Content-Type|Content-Language|ETag|Last-Modified|Link):~i',$header)){$safe[]=$header;}
        }
        if(!$type){return false;}
        $safe[]='Cache-Control: private, no-store';$safe[]='Vary: Cookie, Accept, Accept-Encoding';$safe[]='X-Content-Type-Options: nosniff';return $safe;
    }
}
