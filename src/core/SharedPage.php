<?php
namespace FurMedia\Cache;
/** Catalog shell; header/footer are rendered anew in the current native session. */
class SharedPage {
    public static function marker($name){return '<template data-skynova-fragment="'.$name.'"></template>';}
    public static function stripMarkers($body){foreach(array('header-start','header-end','footer-start','footer-end') as $name){$body=str_replace(self::marker($name),'',$body);}return $body;}
    public static function privateEligible($registry,array $request,array $settings) {
        // Unknown cookies/history stay in the full private key; they are never ignored for sharing.
        $config=$registry->get('config');$customer=$registry->get('customer');
        return $settings['mode']!=='observe' && $customer && !$customer->isLogged() && $registry->has('journal3') && $config->get('config_theme')==='journal3'
            && defined('VERSION') && version_compare(VERSION,'3.0.0.0','>=') && version_compare(VERSION,'4.0.0.0','<')
            && in_array($request['route'],Settings::lines($settings['journal_private_routes']),true);
    }
    public static function unchangedHistoryCookie($header,$registry,$route) {
        // Journal renews jrv on every product visit. Ignore only an exact no-op renewal;
        // the cookie is not stored or replayed, and private keys still include its value.
        if($route!=='product/product' || !preg_match('/^Set-Cookie:\s*jrv=([^;]*)/i',$header,$match)){return false;}
        $value=rawurldecode($match[1]);$request=$registry->get('request');$session=$registry->get('session');
        return preg_match('/^[1-9][0-9]{0,9}(?:,[1-9][0-9]{0,9}){0,19}$/D',$value)
            && isset($request->cookie['jrv'],$session->data['jrv']) && is_string($request->cookie['jrv']) && is_array($session->data['jrv'])
            && $request->cookie['jrv']===$value && implode(',',$session->data['jrv'])===$value;
    }
    public static function eligible($registry,array $request,array $settings) {
        $customer=$registry->get('customer');$cart=$registry->get('cart');
        if(!$customer || !$cart || $customer->isLogged() || $cart->hasProducts()){return false;}
        if ($settings['mode']!=='shared' || !in_array($request['route'],Settings::lines($settings['shared_routes']),true)) { return false; }
        $config=$registry->get('config');$theme=$config->get('config_theme');
        $journal=$registry->has('journal3') && $theme==='journal3' && !empty($settings['journal_shared']) && defined('VERSION') && version_compare(VERSION,'3.0.0.0','>=') && version_compare(VERSION,'4.0.0.0','<');
        if ($registry->has('journal2') || (!$journal && (!in_array($theme,array('default','theme_default'),true) || $registry->has('journal3')))) { return false; }
        $session=$registry->get('session');
        foreach ($session->data as $key=>$value) {
            // This token lives only in the freshly rendered native footer, never in the shell.
            if($key==='binoclo_bis_csrf' && is_string($value) && preg_match('/^[a-f0-9]{48}$/iD',$value)){continue;}
            if($journal && $key==='trinity_session_started_at' && is_int($value) && $value>0){continue;}
            if (in_array($key,array('wishlist','compare'),true) && empty($value)) { continue; }
            if (!in_array($key,array('language','currency'),true) || !is_scalar($value) || strlen((string)$value)>32) { return false; }
        }
        $name=$config->get('session_name') ?: 'OCSESSID';
        foreach ($registry->get('request')->cookie as $key=>$value) {
            if (!in_array($key,array($name,'language','currency'),true)) { return false; }
        }
        return true;
    }
    public static function context(array $context) {
        $context['session_id']='shared-guest';$context['state']='empty-guest';$context['cookies']='native-only';
        return $context;
    }
    public static function pack($body,array $fragments,$document,&$reason=null,$minifier=null) {
        $reason='';
        if(!empty($fragments['_marked'])){
            $last=-1;
            foreach(array('header','footer') as $name){$start=self::marker($name.'-start');$end=self::marker($name.'-end');
                if(substr_count($body,$start)!==1 || substr_count($body,$end)!==1){$reason='boundary-count';return null;}
                $a=strpos($body,$start);$b=strpos($body,$end);if($a<=$last || $b<=$a){$reason='boundary-order';return null;}$last=$b+strlen($end);
                $fragments['common/'.$name]=substr($body,$a,$last-$a);
            }
        }
        foreach(array('common/header','common/footer') as $route) {
            // Journal minifies the complete page after composing native fragments.
            // Reuse its own transform, then still require an exact unique match.
            if(!empty($fragments[$route]) && substr_count($body,$fragments[$route])!==1 && is_callable($minifier)){$fragments[$route]=trim(call_user_func($minifier,$fragments[$route]));}
            if (empty($fragments[$route]) || substr_count($body,$fragments[$route])!==1) {
                $reason=str_replace('common/','',$route).(empty($fragments[$route])?'-missing':'-mismatch');
                return null;
            }
        }
        if (strpos($body,'<!--skynova-dynamic-')!==false) { return null; }
        $shell=str_replace(array($fragments['common/header'],$fragments['common/footer']),array('<!--skynova-dynamic-header-->','<!--skynova-dynamic-footer-->'),$body);
        // Only the shared shell is persisted. Unrecognized forms/nonce/session data refuse admission.
        if (!(new Policy())->responseAllowed($shell.'</html>',array(),200)) { $reason='shell-policy';return null; }
        $metadata=array();
        foreach(array('Title','Description','Keywords','Links','Styles') as $field){$getter='get'.$field;if(!method_exists($document,$getter)){return null;}$metadata[$field]=$document->$getter();}
        $metadata['scripts']=array('header'=>$document->getScripts('header'),'footer'=>$document->getScripts('footer'));
        // Journal uses additional native script positions. Preserve only resource URLs.
        foreach(array('inline','js-defer','lib-countdown','lib-imagezoom','lib-lightgallery','lib-masterslider','lib-swiper','lib-swiper-latest','lib-typeahead','lib-smoothscroll','lib-datetimepicker','lib-countup') as $position){$scripts=$document->getScripts($position);if($scripts){$metadata['scripts'][$position]=$scripts;}}
        if(!(new Policy())->responseAllowed(json_encode($metadata).'</html>',array(),200)){$reason='metadata-policy';return null;}
        return array('shell'=>$shell,'document'=>$metadata);
    }
    public static function render(array $entry,$registry,&$timings=null) {
        if (!isset($entry['shell'],$entry['document'])) { throw new \RuntimeException('Invalid shared entry'); }
        $journal=$registry->has('journal3');
        if($journal){if(empty($entry['journal'])){throw new \RuntimeException('Journal presentation state missing');}JournalState::restore($registry,$entry['journal']);}
        $doc=$registry->get('document');$m=$entry['document'];
        foreach(array('Title','Description','Keywords') as $field){$setter='set'.$field;$doc->$setter($m[$field]);}
        foreach($m['Links'] as $link){$doc->addLink($link['href'],$link['rel']);}
        foreach($m['Styles'] as $style){$doc->addStyle($style['href'],$style['rel'],$style['media']);}
        foreach($m['scripts'] as $position=>$scripts){foreach($scripts as $script){$doc->addScript($script,$position);}}
        // Native OC catalog controllers render footer before header. Journal footer modules
        // register CSS/fonts needed by the header; reversing this order loses those assets.
        $start=microtime(true);$footer=$registry->get('load')->controller('common/footer');$middle=microtime(true);
        if($journal){JournalState::deduplicateCss($registry);}
        $header=$registry->get('load')->controller('common/header');
        $timings=array('footer'=>round(1000*($middle-$start),2),'header'=>round(1000*(microtime(true)-$middle),2));
        if (!is_string($header) || !$header || !is_string($footer) || !$footer) { throw new \RuntimeException('Native fragments unavailable'); }
        // The native outer catalog view applies Journal's HTML transform after composing
        // these fragments. The cached shell already has that transform; only fresh parts need it.
        if($journal && $registry->get('journal3')->get('performanceHTMLMinify') && class_exists('Journal3\\Utils\\Min')){$header=\Journal3\Utils\Min::minifyHTML($header);$footer=\Journal3\Utils\Min::minifyHTML($footer);}
        return str_replace(array('<!--skynova-dynamic-header-->','<!--skynova-dynamic-footer-->'),array($header,$footer),$entry['shell']);
    }
}
