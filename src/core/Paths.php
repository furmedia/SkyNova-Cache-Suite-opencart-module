<?php
namespace FurMedia\Cache;
class Paths {
    public static function privateStorage($cache,$webRoot) {
        $cache=realpath($cache);$webRoot=realpath($webRoot);
        if (!$cache || !$webRoot) { return false; }
        $cache=str_replace('\\','/',$cache).'/';$webRoot=str_replace('\\','/',$webRoot).'/';
        if (DIRECTORY_SEPARATOR === '\\') { $cache=strtolower($cache);$webRoot=strtolower($webRoot); }
        return strpos($cache,$webRoot)!==0;
    }
}
