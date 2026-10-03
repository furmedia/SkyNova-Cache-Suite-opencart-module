<?php
namespace FurMedia\Cache;

/** Public HTTPS fetches with DNS pinning and no redirects; never consumes admin cookies. */
class HttpClient {
    public function get($url, $maxBytes = 2097152, $timeout = 20) {
        return $this->request('GET',$url,'',array(),$maxBytes,$timeout);
    }
    public function request($method,$url,$payload='',array $requestHeaders=array(),$maxBytes=2097152,$timeout=20) {
        if (!in_array($method,array('GET','POST','PUT'),true)) { throw new \InvalidArgumentException('Invalid method'); }
        if (!function_exists('curl_init')) { throw new \RuntimeException('cURL required'); }
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'],$parts['host']) || $parts['scheme'] !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) { throw new \InvalidArgumentException('Public HTTPS URL required'); }
        $host = $parts['host'];
        if (!preg_match('/^[a-z0-9.-]+$/iD',$host)) { throw new \InvalidArgumentException('Invalid host'); }
        $addresses = gethostbynamel($host);
        if (!$addresses) { throw new \RuntimeException('DNS resolution failed'); }
        foreach ($addresses as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) { throw new \InvalidArgumentException('Non-public destination refused'); } }
        $ch=curl_init($url); $body=''; $headers=array();
        curl_setopt_array($ch,array(CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(1,min(55,$timeout)),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'FurMediaCache/0.1',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>array($host . ':443:' . $addresses[0]),CURLOPT_PROXY=>'',CURLOPT_ENCODING=>''));
        curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);
        foreach($requestHeaders as $header){if(!is_string($header)||preg_match('/[\r\n]/',$header)){curl_close($ch);throw new \InvalidArgumentException('Invalid header');}}
        curl_setopt($ch,CURLOPT_HTTPHEADER,$requestHeaders);
        if($method!=='GET'){curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);}
        curl_setopt($ch,CURLOPT_WRITEFUNCTION,function ($handle,$chunk) use (&$body,$maxBytes) { if (strlen($body)+strlen($chunk)>$maxBytes) { return 0; } $body.=$chunk; return strlen($chunk); });
        curl_setopt($ch,CURLOPT_HEADERFUNCTION,function ($handle,$line) use (&$headers) { $headers[]=$line; return strlen($line); });
        $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if (!$ok || !in_array($status,array(200,201,204),true)) { throw new \RuntimeException('Fetch failed (HTTP ' . (int)$status . ')'); }
        return array('body'=>$body,'headers'=>$headers);
    }
}
