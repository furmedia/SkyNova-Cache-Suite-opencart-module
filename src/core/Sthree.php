<?php
namespace FurMedia\Cache;
/** AWS S3 SigV4 uploader restricted to the suite's generated immutable assets. */
class Sthree {
    public static function headers($host,$path,$body,$type,$region,$access,$secret,$date,$session='') {
        $day=substr($date,0,8);$scope=$day.'/'.$region.'/s3/aws4_request';
        $h=array('cache-control'=>'public, max-age=31536000, immutable','content-type'=>$type,'host'=>$host,'x-amz-content-sha256'=>hash('sha256',$body),'x-amz-date'=>$date);
        if($session){$h['x-amz-security-token']=$session;}ksort($h);$canonical='';
        foreach($h as $key=>$value){if(preg_match('/[\r\n]/',$value)){throw new \InvalidArgumentException('Invalid signing header');}$canonical.=$key.':'.trim($value)."\n";}
        $signed=implode(';',array_keys($h));$request="PUT\n".$path."\n\n".$canonical."\n".$signed."\n".$h['x-amz-content-sha256'];
        $toSign="AWS4-HMAC-SHA256\n".$date."\n".$scope."\n".hash('sha256',$request);
        $key=hash_hmac('sha256',$day,'AWS4'.$secret,true);
        foreach(array($region,'s3','aws4_request') as $part){$key=hash_hmac('sha256',$part,$key,true);}
        $h['authorization']='AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256',$toSign,$key);
        $headers=array();foreach($h as $name=>$value){$headers[]=$name.': '.$value;}return $headers;
    }
    public function upload($directory,$limit=20,$transport=null,$stateDirectory=null,$storeId=0) {
        $root=realpath($directory);
        if(!$root || is_link($directory) || basename($root)!=='furmedia_cache'){throw new \InvalidArgumentException('Generated asset directory required');}
        $bucket=Vault::value('SKYNOVA_S3_BUCKET',$storeId);$region=Vault::value('SKYNOVA_S3_REGION',$storeId);$access=Vault::value('SKYNOVA_S3_ACCESS_KEY',$storeId);$secret=Vault::value('SKYNOVA_S3_SECRET_KEY',$storeId);
        if(!$access || !$secret || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D',(string)$bucket) || !preg_match('/^[a-z]{2}-[a-z]+-[0-9]+$/D',(string)$region)){throw new \RuntimeException('Configure SKYNOVA_S3_BUCKET/REGION/ACCESS_KEY/SECRET_KEY');}
        $host=$bucket.'.s3.'.$region.'.amazonaws.com';$count=0;$bytes=0;
        $state=$stateDirectory?new FileStore($stateDirectory,array('max_entries'=>20000,'max_mb'=>16)):null;
        $types=array('css'=>'text/css','js'=>'application/javascript','webp'=>'image/webp','avif'=>'image/avif');
        $deadline=microtime(true)+20;
        foreach(new \DirectoryIterator($root) as $file){
            if(microtime(true)>=$deadline){break;}
            if($count>=max(1,min(100,(int)$limit)) || $bytes>33554432){break;}
            if(!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{64}\.(css|js|webp|avif)$/D',$file->getFilename(),$m) || $file->getSize()>8388608){continue;}
            $stateKey='s3:'.hash('sha256',$host.':'.$file->getFilename());
            if($state && $state->get($stateKey)){continue;}
            $body=file_get_contents($file->getPathname());$path='/image/cache/furmedia_cache/'.$file->getFilename();
            $headers=self::headers($host,$path,$body,$types[$m[1]],$region,$access,$secret,gmdate('Ymd\THis\Z'),Vault::value('SKYNOVA_S3_SESSION_TOKEN',$storeId)?:'');
            if($transport){call_user_func($transport,'PUT','https://'.$host.$path,$body,$headers);}else{(new HttpClient())->request('PUT','https://'.$host.$path,$body,$headers,65536,20);}
            $count++;$bytes+=strlen($body);
            if($state){$state->set($stateKey,true,31536000);}
        }
        return array('uploaded'=>$count,'bytes'=>$bytes);
    }
}
