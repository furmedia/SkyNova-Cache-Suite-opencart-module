<?php
require __DIR__.'/../src/core/bootstrap.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$hash=str_repeat('a',64);
if($path==='/worker-furmedia_cache.js'){header('Content-Type: application/javascript');header('Cache-Control: no-cache');header('Service-Worker-Allowed: /');$epoch=file_get_contents(__DIR__.'/../work/local-stage/pwa-epoch.txt');echo FurMedia\Cache\Pwa::worker('/',$epoch);}
elseif($path==='/image/cache/furmedia_cache/'.$hash.'.css'){header('Content-Type: text/css');header('Cache-Control: public, max-age=31536000, immutable');echo 'body{color:#123456}';}
elseif($path==='/account'||$path==='/checkout'){header('Cache-Control: private, no-store');echo 'private dynamic fixture';}
else{header('Content-Type: text/html');echo '<!doctype html><title>Own PWA test</title><body>Fixture'.FurMedia\Cache\Pwa::registration('/worker-furmedia_cache.js','/').'</body>';}
