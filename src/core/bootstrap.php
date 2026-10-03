<?php
/* SkyNova Cache Suite. Original implementation. PHP 5.6+ core. */
spl_autoload_register(function ($class) {
    foreach (array('MatthiasMullie\\Minify\\'=>'minify','MatthiasMullie\\PathConverter\\'=>'path-converter') as $vendor=>$dir) {
        if (strpos($class,$vendor) === 0) {
            $path = __DIR__ . '/vendor/' . $dir . '/src/' . str_replace('\\','/',substr($class,strlen($vendor))) . '.php';
            if (is_file($path)) { require_once $path; }
            return;
        }
    }
    $prefix = 'FurMedia\\Cache\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/', $name)) {
        $file = __DIR__ . '/' . $name . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
