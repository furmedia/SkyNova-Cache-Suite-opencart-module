<?php
namespace FurMedia\Cache;

class Optimizer {
    private $settings;
    private $assets;
    public function __construct(array $settings, Assets $assets) { $this->settings = $settings; $this->assets = $assets; }
    private function attr($tag, $name) {
        return preg_match('~\s' . preg_quote($name,'~') . '\s*=\s*(["\x27])(.*?)\1~is', $tag, $m) ? html_entity_decode($m[2], ENT_QUOTES, 'UTF-8') : '';
    }
    private function set($tag, $name, $value) {
        $attr = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        $pattern = '~\s' . preg_quote($name,'~') . '\s*=\s*(["\x27]).*?\1~is';
        if (preg_match($pattern, $tag)) { return preg_replace_callback($pattern, function () use ($attr) { return ' ' . $attr; }, $tag, 1); }
        return preg_replace('~\s*/?>$~', ' ' . $attr . '>', $tag, 1);
    }

    private function merge($html) {
        if(!$this->settings['css_merge'] && !$this->settings['js_merge']){return $html;}
        $pattern='~<(?:pre|textarea|style)\b[^>]*>.*?</(?:pre|textarea|style)\s*>|<!--[\s\S]*?-->|(?:<script\b[^>]*>.*?</script\s*>\s*)+|(?:<link\b[^>]*>\s*)+~is';
        return preg_replace_callback($pattern,function($m){
            $group=$m[0];$type=preg_match('/^<script\b/i',$group)?'js':(preg_match('/^<link\b/i',$group)?'css':'');
            if(!$type || !$this->settings[$type.'_merge']){return $group;}
            preg_match_all($type==='js'?'~<script\b[^>]*>.*?</script\s*>~is':'~<link\b[^>]*>~is',$group,$matches);
            $tags=$matches[0];if(count($tags)<2){return $group;}$urls=array();$media=null;
            foreach($tags as $tag){
                $open=substr($tag,0,strpos($tag,'>')+1);$url=$this->attr($open,$type==='js'?'src':'href');if(!$url){return $group;}
                // Only plain resource tags: event handlers, SRI, nonce, async/defer and module boundaries stay native.
                $rest=preg_replace('~\s(?:src|href|rel|type|media)\s*=\s*([\x22\x27]).*?\1~is','',$open);
                if(!preg_match('~^<(?:script|link)\s*/?>$~i',$rest)){return $group;}
                if($type==='js'){
                    if(!in_array(strtolower($this->attr($open,'type')),array('','text/javascript','application/javascript'),true) || trim(substr($tag,strlen($open),strrpos($tag,'</')-strlen($open)))!==''){return $group;}
                }else{
                    if(strtolower($this->attr($open,'rel'))!=='stylesheet'){return $group;}
                    $current=$this->attr($open,'media');if($media!==null && $media!==$current){return $group;}$media=$current;
                }
                $urls[]=$url;
            }
            $url=$this->assets->bundle($urls,$type);if(!$url){return $group;}
            $first=$this->set($tags[0],$type==='js'?'src':'href',$url);
            return $first.(preg_match('/\s+$/',$group,$space)?$space[0]:'');
        },$html);
    }

    public function transform($html, $accept = '') {
        $html=Scripts::transform($html,$this->assets,$this->settings);
        $html=$this->merge($html);
        $s = $this->settings; $images = 0; $delayed=false;
        // Tokenize complete raw-text elements so JS, CSS, JSON-LD and whitespace-sensitive text survive byte-for-byte.
        $pattern = '~<(script|style|textarea|pre)\b[^>]*>.*?</\1\s*>|<!--[\s\S]*?-->|<[^>]+>~i';
        $out = preg_replace_callback($pattern, function ($m) use ($s, $accept, &$images, &$delayed) {
            $tag = $m[0];
            if (strpos($tag, '<!--') === 0) { return $s['html_minify'] && !preg_match('/\[if|<!\[|ko\b|\/ko|google|noindex/i',$tag) ? '' : $tag; }
            if (preg_match('~^<img\b~i',$tag)) {
                $images++; $src = $this->attr($tag,'src');
                if (!$src || strpos($tag,'data-src') !== false || strpos($tag,'data-fm-skip') !== false) { return $tag; }
                if ($s['image_dimensions'] && !$this->attr($tag,'width') && !$this->attr($tag,'height')) {
                    $file = $this->assets->local($src); $info = $file ? @getimagesize($file) : false;
                    if ($info) { $tag = $this->set($this->set($tag,'width',(string)$info[0]),'height',(string)$info[1]); }
                }
                if ($s['lazy_images'] && $images > 2 && !$this->attr($tag,'loading') && $this->attr($tag,'fetchpriority') !== 'high') { $tag = $this->set($tag,'loading','lazy'); }
                if (!$this->attr($tag,'srcset')) {
                    $variants=$this->assets->variants($src,$accept);
                    if($variants){$tag=$this->set($tag,'srcset',$variants);if(!$this->attr($tag,'sizes')){$tag=$this->set($tag,'sizes',$s['image_sizes']);}}
                    $src = $this->assets->image($src,$accept);
                }
                return $this->set($tag,'src',$this->assets->cdn($src));
            }
            if (preg_match('~^<iframe\b~i',$tag) && $s['lazy_iframes'] && !$this->attr($tag,'loading')) { return $this->set($tag,'loading','lazy'); }
            if (preg_match('~^<link\b~i',$tag) && strtolower($this->attr($tag,'rel')) === 'stylesheet' && !$this->attr($tag,'integrity')) {
                $url = $this->attr($tag,'href');
                if ($url) { return $this->set($tag,'href',$this->assets->cdn($this->assets->minify($url,'css'))); }
            }
            if (preg_match('~^<script\b~i',$tag)) {
                $end = strpos($tag,'>'); $open = substr($tag,0,$end+1); $tail = substr($tag,$end+1);
                $url = $this->attr($open,'src');
                if (!$url || $this->attr($open,'integrity') || $this->assets->excluded($url) || !in_array(strtolower($this->attr($open,'type')),array('','text/javascript','application/javascript'),true)) { return $tag; }
                $open = $this->set($open,'src',$this->assets->cdn($this->assets->minify($url,'js')));
                if($s['delay_js'] && in_array($url,Settings::lines($s['delay_allow']),true) && !preg_match('/\s(?:async|defer|nomodule)\b/i',$open)){
                    $file=$this->assets->local($url);
                    if($file && filesize($file)<2097152 && stripos(file_get_contents($file),'document.write')===false){
                        $open=$this->set($open,'type','application/x-skynova-delayed');$open=preg_replace('/\ssrc\s*=/i',' data-skynova-src=',$open,1);$delayed=true;return $open.$tail;
                    }
                }
                if ($s['defer_js'] && !preg_match('/\s(?:async|defer|nomodule)\b/i',$open)) {
                    foreach (Settings::lines($s['defer_allow']) as $allowed) {
                        if ($allowed === $url) { $open = substr($open,0,-1) . ' defer>'; break; }
                    }
                }
                return $open . $tail;
            }
            return $tag;
        }, $html);
        $extra = '';
        if($delayed){
            $loader='<script>(function(){var started=false;function run(){if(started)return;started=true;var q=Array.prototype.slice.call(document.querySelectorAll("script[data-skynova-src]"));function next(){var old=q.shift();if(!old)return;var s=document.createElement("script");Array.prototype.forEach.call(old.attributes,function(a){if(["src","type","data-skynova-src"].indexOf(a.name)<0)s.setAttribute(a.name,a.value);});s.src=old.getAttribute("data-skynova-src");s.async=false;s.addEventListener("load",next,{once:true});s.addEventListener("error",next,{once:true});old.parentNode.replaceChild(s,old);}next();}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){setTimeout(run,4000);});}else{setTimeout(run,4000);}["pointerdown","keydown","touchstart"].forEach(function(e){window.addEventListener(e,function(){if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}},{once:true,passive:true});});})();</script>';
            $out=preg_replace_callback('~</body\s*>~i',function($m)use($loader){return $loader.$m[0];},$out,1);
        }
        if ($s['critical_css']) { $extra .= '<style id="furmedia-critical">' . $s['critical_css'] . '</style>'; }
        foreach (Settings::lines($s['preconnect']) as $origin) {
            if (preg_match('~^https://[a-z0-9.-]+$~iD',$origin)) { $extra .= '<link rel="preconnect" href="' . htmlspecialchars($origin,ENT_QUOTES,'UTF-8') . '" crossorigin>'; }
        }
        if ($s['preload_image'] && $this->assets->local($s['preload_image'])) { $extra .= '<link rel="preload" as="image" href="' . htmlspecialchars($s['preload_image'],ENT_QUOTES,'UTF-8') . '" fetchpriority="high">'; }
        return $extra ? preg_replace_callback('~</head\s*>~i', function ($m) use ($extra) { return $extra . $m[0]; }, $out, 1) : $out;
    }
}
