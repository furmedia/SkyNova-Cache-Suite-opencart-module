<?php
namespace FurMedia\Cache;
class Entropy {
    public static function bytes($length) {
        if (function_exists('random_bytes')) { return random_bytes($length); }
        $strong = false; $bytes = openssl_random_pseudo_bytes($length,$strong);
        if (!$strong || $bytes === false) { throw new \RuntimeException('Secure random generator unavailable'); }
        return $bytes;
    }
}
