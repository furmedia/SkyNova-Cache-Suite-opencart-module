<?php
// Deploy OUTSIDE public_html. CLI only; never loads OpenCart or shop config.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/core/bootstrap.php';
$options = getopt('',array('config:','action:'));
if (empty($options['config']) || empty($options['action'])) { fwrite(STDERR,"Usage: php tools/cron.php --config=/private/cache-runner.json --action=gc|purge|warm|batch\n"); exit(2); }
$config = json_decode(file_get_contents($options['config']),true);
if (!is_array($config) || empty($config['cache_directory'])) { fwrite(STDERR,"Invalid runner config\n");exit(2); }
try {
    $result=(new \FurMedia\Cache\Runner())->run($config,$options['action']);
    echo json_encode($result)."\n";
    if (!empty($result['failed'])) { exit(1); }
} catch (\Exception $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
