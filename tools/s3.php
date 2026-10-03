<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../src/core/bootstrap.php';
$options=getopt('',array('directory:','limit:','state:'));
if(empty($options['directory']) || empty($options['state'])){fwrite(STDERR,"Usage: php tools/s3.php --directory=/shop/image/cache/furmedia_cache --state=/private/skynova-s3-state --limit=20\n");exit(2);}
try{echo json_encode((new \FurMedia\Cache\Sthree())->upload($options['directory'],isset($options['limit'])?$options['limit']:20,null,$options['state']))."\n";}
catch(\Exception $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
