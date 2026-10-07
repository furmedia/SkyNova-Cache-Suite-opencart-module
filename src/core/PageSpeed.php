<?php
namespace FurMedia\Cache;
class PageSpeed {
    private $fetch;
    private $directory;
    public function __construct($fetch=null,$directory=null){$this->fetch=$fetch;$this->directory=$directory;}
    public function analyze($storeUrl,$strategy='mobile',$storeId=0) {
        if (!in_array($strategy,array('mobile','desktop'),true)) { throw new \InvalidArgumentException('Invalid strategy'); }
        $target=parse_url($storeUrl);
        if (!$target || !isset($target['scheme'],$target['host']) || $target['scheme']!=='https' || isset($target['user']) || isset($target['pass']) || isset($target['query'])) { throw new \InvalidArgumentException('Store must have a clean HTTPS URL'); }
        $url='https://www.googleapis.com/pagespeedonline/v5/runPagespeed?category=performance&strategy='.$strategy.'&url='.rawurlencode($storeUrl);
        $apiKey=Vault::value('FURMEDIA_PAGESPEED_KEY',$storeId);
        if ($apiKey) { $url.='&key='.rawurlencode($apiKey); }
        $directory=$this->directory!==null?$this->directory:(defined('DIR_CACHE')?DIR_CACHE.'furmedia_cache-pagespeed-'.(int)$storeId:null);
        $cache=$directory?new FileStore($directory):null;
        if($cache && $cache->get('quota')){throw new \RuntimeException('Google PageSpeed: limita de cereri a fost depășită (HTTP 429). Reîncearcă după 5 minute sau configurează cheia API PageSpeed în Integrări externe. Raportul anterior este păstrat.');}
        try{$response=$this->fetch?call_user_func($this->fetch,$url):(new HttpClient())->get($url,8388608,55);}
        catch(\Exception $e){if(strpos($e->getMessage(),'HTTP 429')!==false){if($cache){$cache->set('quota',true,300);}throw new \RuntimeException('Google PageSpeed: limita de cereri a fost depășită (HTTP 429). Reîncearcă după 5 minute sau configurează cheia API PageSpeed în Integrări externe. Raportul anterior este păstrat.');}throw $e;}
        $data=json_decode($response['body'],true);
        if (!isset($data['lighthouseResult']['categories']['performance']['score'])) { throw new \RuntimeException('PageSpeed returned no performance report'); }
        return $this->report($data['lighthouseResult'],$storeUrl,$strategy);
    }

    /** Convert the measured audit into suggestions, without silently changing store settings. */
    public function report(array $lh,$storeUrl,$strategy='mobile') {
        if (!isset($lh['categories']['performance']['score']) || !is_numeric($lh['categories']['performance']['score'])) { throw new \InvalidArgumentException('Missing performance score'); }
        $metrics=array();
        foreach (array('first-contentful-paint','largest-contentful-paint','cumulative-layout-shift','total-blocking-time','speed-index') as $id) {
            if (isset($lh['audits'][$id]['displayValue'])) { $metrics[$id]=$lh['audits'][$id]['displayValue']; }
        }
        return array('score'=>(int)round(max(0,min(1,$lh['categories']['performance']['score']))*100),'strategy'=>$strategy,'measured_at'=>gmdate('c'),'metrics'=>$metrics,'url'=>$storeUrl,'recommendations'=>$this->recommendations(isset($lh['audits']) && is_array($lh['audits'])?$lh['audits']:array()));
    }

    public function recommendations(array $audits) {
        $map=array(
            'server-response-time'=>array('Cache pagini','Verifică HIT și timpul până la primul byte; aprobă numai rutele publice fără personalizare.','general'),
            'uses-optimized-images'=>array('Optimizare imagini','Generează variante mai mici în lot și verifică imaginile produselor.','images'),
            'modern-image-formats'=>array('WebP / AVIF','Activează formatele suportate de GD și păstrează originalele pentru clienții incompatibili.','images'),
            'uses-responsive-images'=>array('Imagini responsive','Verifică srcset și sizes pentru dimensiunile efective din layout.','images'),
            'offscreen-images'=>array('Încărcare întârziată imagini','Activează lazy pentru imaginile de sub primul ecran; păstrează imaginea LCP imediată.','images'),
            'unminified-css'=>array('Minificare CSS','Pre-minifică resursele locale; verifică vizual paginile și pipeline-ul Journal.','assets'),
            'unminified-javascript'=>array('Minificare JavaScript','Pre-minifică fișierele locale aprobate și verifică interacțiunile magazinului.','assets'),
            'render-blocking-resources'=>array('Resurse care blochează afișarea','Generează CSS critic și aprobă separat scripturile independente pentru defer.','assets'),
            'unused-css-rules'=>array('CSS neutilizat','Verifică CSS-ul temei; eliminarea automată poate afecta meniuri, variante și ferestre modale.','assets'),
            'unused-javascript'=>array('JavaScript neutilizat','Identifică extensiile încărcate inutil înainte de a modifica ordinea scripturilor.','assets'),
            'uses-long-cache-ttl'=>array('Cache browser','Verifică antetele resurselor originale și CDN; resursele generate au URL-uri cu hash.','cdn'),
            'uses-text-compression'=>array('Compresie','Verifică gzip pentru HTML și Brotli/gzip în server pentru CSS și JavaScript.','assets'),
            'font-display'=>array('Fonturi','Configurează font-display în CSS-ul fonturilor și preîncarcă doar fonturile folosite imediat.','assets'),
            'unsized-images'=>array('Dimensiuni imagini','Adaugă width și height pentru a rezerva spațiul înainte de descărcare.','images'),
            'third-party-summary'=>array('Scripturi externe','Verifică impactul fiecărui furnizor și amână numai scripturile independente, conform consimțământului.','assets')
        );
        $rows=array();
        foreach($map as $id=>$suggestion){
            if(!isset($audits[$id]) || !is_array($audits[$id])){continue;}
            $audit=$audits[$id];
            if(!isset($audit['score']) || !is_numeric($audit['score']) || $audit['score']>=0.9 || (isset($audit['scoreDisplayMode']) && in_array($audit['scoreDisplayMode'],array('notApplicable','manual','informative','error'),true))){continue;}
            $details=isset($audit['details']) && is_array($audit['details'])?$audit['details']:array();
            $ms=isset($details['overallSavingsMs']) && is_numeric($details['overallSavingsMs'])?max(0,(float)$details['overallSavingsMs']):0;
            $bytes=isset($details['overallSavingsBytes']) && is_numeric($details['overallSavingsBytes'])?max(0,(float)$details['overallSavingsBytes']):0;
            $rows[]=array('audit'=>$id,'title'=>$suggestion[0],'action'=>$suggestion[1],'section'=>$suggestion[2],'score'=>max(0,min(1,(float)$audit['score'])),'savings_ms'=>$ms,'savings_bytes'=>$bytes);
        }
        usort($rows,function($a,$b){if($a['savings_ms']!==$b['savings_ms']){return $a['savings_ms']>$b['savings_ms']?-1:1;}if($a['score']!==$b['score']){return $a['score']<$b['score']?-1:1;}return strcmp($a['audit'],$b['audit']);});
        return $rows;
    }
}
