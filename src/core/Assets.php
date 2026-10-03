<?php
namespace FurMedia\Cache;

class Assets {
    private $root;
    private $public;
    private $base;
    private $settings;

    public function __construct($root, $public, $base, array $settings) {
        $this->root = realpath($root);
        $this->public = rtrim($public, '/\\');
        $this->base = rtrim($base, '/') . '/';
        $this->settings = $settings;
        if (!$this->root) { throw new \RuntimeException('Store root unavailable'); }
    }

    public function local($url) {
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $parsed = parse_url($url); $base = parse_url($this->base);
        if (!$parsed || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])) { return false; }
        if (isset($parsed['host']) && (strtolower($parsed['host']) !== strtolower($base['host']) || (isset($parsed['port']) ? $parsed['port'] : 443) !== (isset($base['port']) ? $base['port'] : 443))) { return false; }
        if (isset($parsed['scheme']) && !in_array($parsed['scheme'], array('http','https'), true)) { return false; }
        $path = rawurldecode(isset($parsed['path']) ? $parsed['path'] : '');
        if (preg_match('~(?:^|/)\.\.(?:/|$)~',$path)) { return false; }
        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) { return false; }
        $basePath = isset($base['path']) ? $base['path'] : '/';
        if (substr($path, 0, 1) === '/') {
            if (strpos($path, $basePath) !== 0) { return false; }
            $path = substr($path, strlen($basePath));
        }
        if (!preg_match('~^(?:image|catalog|extension)/~', $path)) { return false; }
        $file = realpath($this->root . '/' . $path);
        if (!$file || strpos(str_replace('\\','/',$file), str_replace('\\','/',$this->root) . '/') !== 0 || !is_file($file)) { return false; }
        return $file;
    }

    public function excluded($url) {
        foreach (Settings::lines($this->settings['exclude_assets']) as $word) { if (stripos($url, $word) !== false) { return true; } }
        return strpos($url, '/furmedia_cache/') !== false;
    }

    private function room($size) {
        if (!is_dir($this->public) && !@mkdir($this->public, 0755, true) && !is_dir($this->public)) { return false; }
        if (is_link($this->public)) { return false; }
        if (!is_file($this->public . '/.htaccess')) {
            @file_put_contents($this->public . '/.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\n<FilesMatch \"^[a-f0-9]{64}\\.(css|js|webp|avif)$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\nHeader set X-Content-Type-Options \"nosniff\"\n</FilesMatch>\n</IfModule>\n<FilesMatch \"^(minify-|convert-|import-|\\.)\">\nRequire all denied\n</FilesMatch>\n");
        }
        $free = @disk_free_space($this->public);
        if ($free !== false && $free < $this->settings['reserve_mb'] * 1048576 + $size) { return false; }
        $bytes = 0;
        foreach (glob($this->public . '/*') ?: array() as $file) { if (is_file($file)) { $bytes += filesize($file); } }
        return $bytes + $size <= $this->settings['max_mb'] * 1048576;
    }

    private function url($name) { return $this->base . 'image/cache/furmedia_cache/' . $name; }

    private function withLock($callback,$fallback) {
        if (!is_dir($this->public) && !@mkdir($this->public,0755,true) && !is_dir($this->public)) { return $fallback; }
        if (is_link($this->public)) { return $fallback; }
        $handle=@fopen($this->public.'/.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) { fclose($handle); } return $fallback; }
        try { return call_user_func($callback); }
        finally { flock($handle,LOCK_UN);fclose($handle); }
    }

    public function minify($url,$type) {
        if (!in_array($type,array('css','js'),true) || !$this->settings[$type.'_minify']) { return $url; }
        return $this->withLock(function()use($url,$type){return $this->minifyUnlocked($url,$type);},$url);
    }

    public function script($content) {
        if(!is_string($content) || strlen($content)>2097152 || trim($content)===''){return false;}
        return $this->withLock(function()use($content){
            $name=hash('sha256','skynova-inline-v1:'.$content).'.js';$target=$this->public.'/'.$name;
            if(!is_file($target)){
                if(!$this->room(strlen($content))){return false;}
                $temporary=tempnam($this->public,'minify-');if(!$temporary){return false;}
                if(file_put_contents($temporary,$content)!==strlen($content) || !@rename($temporary,$target)){@unlink($temporary);return false;}
            }
            return $this->url($name);
        },false);
    }

    private function minifyUnlocked($url, $type) {
        if (!in_array($type, array('css','js'), true) || $this->excluded($url) || !$this->settings[$type . '_minify']) { return $url; }
        $file = $this->local($url);
        if (!$file || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== $type || filesize($file) > 2097152) { return $url; }
        $hash = hash_file('sha256', $file);
        $name = hash('sha256', $file . $hash . 'minify-1.3.75') . '.' . $type;
        $target = $this->public . '/' . $name;
        if (!is_file($target)) {
            if (!$this->room(filesize($file) * 2)) { return $url; }
            $class = '\\MatthiasMullie\\Minify\\' . strtoupper($type);
            if (!class_exists($class)) { return $url; }
            try {
                if ($type === 'css' && stripos(file_get_contents($file),'@import') !== false) { return $url; }
                $minifier = new $class($file);
                if ($type === 'css') { $minifier->setMaxImportSize(0); }
                $temporary = tempnam($this->public, 'minify-');
                $result = $minifier->minify($temporary);
                // CSS library rebases relative URLs for the destination directory.
                if (!is_string($result) || strlen($result) > 4194304) { @unlink($temporary); return $url; }
                if (!@rename($temporary, $target)) { @unlink($temporary); return $url; }
            } catch (\Exception $e) { if (isset($temporary) && is_file($temporary)) { @unlink($temporary); } return $url; }
        }
        return $this->url($name);
    }


    public function advancedResource($url,$type,array $settings,$sourceUrl) {
        $rules=Advanced::rows($settings['replacements']);$found=false;foreach($rules as $rule){if(isset($rule['type'])&&$rule['type']===$type&&(!isset($rule['url'])||$rule['url']===$sourceUrl)){$found=true;}}if(!$found||$this->excluded($sourceUrl)){return $url;}
        return $this->withLock(function()use($url,$type,$settings,$sourceUrl){$file=$this->local($url);if(!$file||strtolower(pathinfo($file,PATHINFO_EXTENSION))!==$type||filesize($file)>2097152){return $url;}$body=file_get_contents($file);if($type==='css'){if(stripos($body,'@import')!==false){return $url;}$css=new \MatthiasMullie\Minify\CSS($file);$css->setMaxImportSize(0);if(!$this->room(strlen($body)*2)){return $url;}$cssTemp=tempnam($this->public,'import-');try{$body=$css->minify($cssTemp);}finally{@unlink($cssTemp);}}$body=Advanced::replace($body,$settings,$type,$sourceUrl);if(!$this->room(strlen($body))){return $url;}$name=hash('sha256',$body).'.'.$type;$target=$this->public.'/'.$name;if(!is_file($target)){$tmp=tempnam($this->public,'import-');if(file_put_contents($tmp,$body,LOCK_EX)!==strlen($body)||!rename($tmp,$target)){@unlink($tmp);return $url;}}return $this->url($name);},$url);
    }

    public function bundle(array $urls,$type) {
        if(!in_array($type,array('css','js'),true) || !$this->settings[$type.'_merge'] || count($urls)<2 || count($urls)>32){return false;}
        return $this->withLock(function()use($urls,$type){
            $files=array();$signature=array();$size=0;
            foreach($urls as $url){
                if(!in_array($url,Settings::lines($this->settings['merge_allow']),true) || $this->excluded($url)){return false;}
                $file=$this->local($url);if(!$file || strtolower(pathinfo($file,PATHINFO_EXTENSION))!==$type || filesize($file)>2097152){return false;}
                $content=file_get_contents($file);$size+=strlen($content);if($size>2097152){return false;}
                if($type==='css' && preg_match('/@(?:import|charset)\b/i',$content)){return false;}
                if($type==='js' && preg_match('/document\s*\.\s*(?:write|currentScript)|[\x22\x27]use strict[\x22\x27]/i',$content)){return false;}
                $files[]=$file;$signature[]=array($file,hash('sha256',$content));
            }
            $class='\\MatthiasMullie\\Minify\\'.strtoupper($type);if(!class_exists($class)){return false;}
            $name=hash('sha256',serialize(array('bundle-v1',$type,$signature))).'.'.$type;$target=$this->public.'/'.$name;
            if(!is_file($target)){
                if(!$this->room($size*2)){return false;}$temp=null;
                try{
                    $minifier=new $class();foreach($files as $file){$minifier->add($file);}if($type==='css'){$minifier->setMaxImportSize(0);}
                    $temp=tempnam($this->public,'minify-');$result=$minifier->minify($temp);
                    if(!is_string($result) || strlen($result)>4194304 || !@rename($temp,$target)){if($temp){@unlink($temp);}return false;}
                }catch(\Exception $e){if($temp){@unlink($temp);}return false;}
            }
            return $this->url($name);
        },false);
    }

    public function image($url,$accept,$width=0) {
        if (!$this->settings['webp'] && !$this->settings['avif']) { return $url; }
        return $this->withLock(function()use($url,$accept,$width){return $this->imageUnlocked($url,$accept,$width);},$url);
    }

    private function imageUnlocked($url, $accept, $width=0) {
        $file = $this->local($url);
        if (!$file || $this->excluded($url) || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), array('jpg','jpeg','png'), true) || filesize($file) > 8388608) { return $url; }
        $format = $this->settings['avif'] && stripos($accept, 'image/avif') !== false && function_exists('imageavif') ? 'avif' : ($this->settings['webp'] && stripos($accept, 'image/webp') !== false && function_exists('imagewebp') ? 'webp' : '');
        if (!$format || !function_exists('imagecreatefromstring')) { return $url; }
        $info = @getimagesize($file);
        if (!$info || $info[0] * $info[1] > 12000000 || $info[0] * $info[1] * 8 > 67108864) { return $url; }
        $limit = trim(ini_get('memory_limit')); $memory = (int)$limit;
        $unit = strtolower(substr($limit,-1));
        if ($unit === 'g') { $memory *= 1073741824; } elseif ($unit === 'm') { $memory *= 1048576; } elseif ($unit === 'k') { $memory *= 1024; }
        if ($memory > 0 && memory_get_usage(true) + $info[0] * $info[1] * 8 + 16777216 > $memory) { return $url; }
        $width=max(0,min(1920,(int)$width));if($width>=$info[0]){$width=0;}
        $name = hash('sha256', $file . hash_file('sha256',$file) . $this->settings['quality'].'width:'.$width) . '.' . $format;
        $target = $this->public . '/' . $name;
        if (!is_file($target)) {
            if (!$this->room(filesize($file) * 2)) { return $url; }
            $image = @imagecreatefromstring(file_get_contents($file));
            if (!$image) { return $url; }
            imagepalettetotruecolor($image); imagealphablending($image, false); imagesavealpha($image, true);
            if($width){
                $height=max(1,(int)round($info[1]*$width/$info[0]));
                if($width*$height*4+memory_get_usage(true)+16777216>$memory && $memory>0){imagedestroy($image);return $url;}
                $resized=imagecreatetruecolor($width,$height);imagealphablending($resized,false);imagesavealpha($resized,true);
                imagecopyresampled($resized,$image,0,0,0,0,$width,$height,$info[0],$info[1]);imagedestroy($image);$image=$resized;
            }
            $temp = tempnam($this->public, 'convert-');
            $ok = call_user_func('image' . $format, $image, $temp, $this->settings['quality']);
            imagedestroy($image);
            if (!$ok || filesize($temp) >= filesize($file)) { @unlink($temp); return $url; }
            if (!@rename($temp, $target)) { @unlink($temp); return $url; }
        }
        return $this->url($name);
    }

    public function cdn($url) {
        if (!$this->settings['cdn'] || !$this->local($url) || $this->excluded($url)) { return $url; }
        $path = str_replace('\\','/',substr($this->local($url), strlen($this->root) + 1));
        if (!preg_match('/\.(?:css|js|png|jpe?g|gif|webp|avif|svg|woff2?)$/iD', $path)) { return $url; }
        $query = parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY);
        return rtrim($this->settings['cdn'],'/') . '/' . $path . ($query !== null && $query !== false ? '?' . $query : '');
    }

    public function variants($url,$accept) {
        $file=$this->local($url);$info=$file?@getimagesize($file):false;$items=array();
        if(!$info || !$this->settings['responsive_images']){return '';}
        foreach(array(320,640,960,1280) as $width){
            if($width>=$info[0]){continue;}
            $variant=$this->image($url,$accept,$width);
            if($variant!==$url){$items[]=$this->cdn($variant).' '.$width.'w';}
        }
        if($items){$items[]=$this->cdn($this->image($url,$accept)).' '.$info[0].'w';}
        return implode(', ',$items);
    }
}
