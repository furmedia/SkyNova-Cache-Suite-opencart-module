<?php
namespace FurMedia\Cache;
class Cloudflare {
    private $transport;
    public function __construct($transport=null){$this->transport=$transport;}
    public function purge($origin,array $urls,$storeId=0) {
        $zone=Vault::value('SKYNOVA_CF_ZONE',$storeId);$token=Vault::value('SKYNOVA_CF_TOKEN',$storeId);
        if(!$zone || !preg_match('/^[a-f0-9]{32}$/iD',$zone) || !$token || preg_match('/[\r\n]/',$token)){throw new \RuntimeException('Configurează SKYNOVA_CF_ZONE/TOKEN pentru acest magazin în mediul serverului.');}
        $base=parse_url($origin);$safe=array();
        if(!$base || !isset($base['host']) || count($urls)<1 || count($urls)>30){throw new \InvalidArgumentException('Furnizează 1–30 URL-uri ale magazinului.');}
        foreach($urls as $url){
            $p=parse_url($url);
            $scope=isset($base['path'])?rtrim($base['path'],'/'):'';$path=isset($p['path'])?$p['path']:'/';
            if(!$p || !isset($p['scheme'],$p['host']) || $p['scheme']!=='https' || strtolower($p['host'])!==strtolower($base['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment']) || preg_match('/[\x00-\x20]/',$url) || ($scope && $path!==$scope && strpos($path,$scope.'/')!==0)){throw new \InvalidArgumentException('URL în afara magazinului.');}
            $safe[]=$url;
        }
        $endpoint='https://api.cloudflare.com/client/v4/zones/'.$zone.'/purge_cache';
        $headers=array('Authorization: Bearer '.$token,'Content-Type: application/json');$body=json_encode(array('files'=>array_values(array_unique($safe))));
        $response=$this->transport?call_user_func($this->transport,'POST',$endpoint,$body,$headers):(new HttpClient())->request('POST',$endpoint,$body,$headers,65536,15);
        $data=json_decode($response['body'],true);
        if(!is_array($data)||empty($data['success'])){throw new \RuntimeException('Cloudflare a refuzat invalidarea. Verifică zona și permisiunea Cache Purge.');}
        return array('success'=>true,'count'=>count($safe));
    }
}
