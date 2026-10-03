<?php
// Optional HTTPS endpoint. Deploy only with environment configuration; not installed by the OpenCart ZIP.
require_once __DIR__.'/../src/core/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (PHP_SAPI==='cli' || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);echo '{"error":"POST required"}';exit; }
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') { http_response_code(403);echo '{"error":"HTTPS required"}';exit; }
$expected=getenv('FURMEDIA_CRON_SHA256');$path=getenv('FURMEDIA_RUNNER_CONFIG');
if (!$expected || !preg_match('/^[a-f0-9]{64}$/D',$expected) || !$path || !is_file($path)) { http_response_code(503);echo '{"error":"Runner not configured"}';exit; }
$authorization=isset($_SERVER['HTTP_AUTHORIZATION'])?$_SERVER['HTTP_AUTHORIZATION']:'';
if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,128})$/D',$authorization,$match) || !hash_equals($expected,hash('sha256',$match[1]))) { http_response_code(403);echo '{"error":"Forbidden"}';exit; }
$config=json_decode(file_get_contents($path),true);
if (!is_array($config) || empty($config['cache_directory']) || !\FurMedia\Cache\Paths::privateStorage($config['cache_directory'],$_SERVER['DOCUMENT_ROOT'])) { http_response_code(503);echo '{"error":"Private runner storage required"}';exit; }
$store=new \FurMedia\Cache\FileStore($config['cache_directory']);
// Authenticated endpoint permits periodic warm/GC only. Purge remains explicit CLI/admin.
$last=$store->get('runner:last');
if ($last!==null) { http_response_code(429);echo '{"error":"Retry after 60 seconds"}';exit; }
$store->set('runner:last',time(),60);
try {
    $result=(new \FurMedia\Cache\Runner())->run($config,'warm');
    $result['expired_removed']=$store->gc();
    echo json_encode($result);
} catch (\Exception $e) { http_response_code(503);echo '{"error":"Runner failed; inspect local configuration"}'; }
