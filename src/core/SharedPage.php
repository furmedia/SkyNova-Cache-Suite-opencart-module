<?php
namespace FurMedia\Cache;
/** Standard-theme guest shell; header/footer are rendered anew in the current native session. */
class SharedPage {
    public static function eligible($registry,array $request,array $settings) {
        $customer=$registry->get('customer');$cart=$registry->get('cart');
        if(!$customer || !$cart || $customer->isLogged() || $cart->hasProducts()){return false;}
        if ($settings['mode']!=='shared' || !in_array($request['route'],Settings::lines($settings['shared_routes']),true)) { return false; }
        $config=$registry->get('config');$theme=$config->get('config_theme');
        if (!in_array($theme,array('default','theme_default'),true) || $registry->has('journal3') || $registry->has('journal2')) { return false; }
        $session=$registry->get('session');
        foreach ($session->data as $key=>$value) {
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
    public static function pack($body,array $fragments,$document) {
        foreach(array('common/header','common/footer') as $route) {
            if (empty($fragments[$route]) || substr_count($body,$fragments[$route])!==1) { return null; }
        }
        if (strpos($body,'<!--skynova-dynamic-')!==false) { return null; }
        $shell=str_replace(array($fragments['common/header'],$fragments['common/footer']),array('<!--skynova-dynamic-header-->','<!--skynova-dynamic-footer-->'),$body);
        // Only the shared shell is persisted. Unrecognized forms/nonce/session data refuse admission.
        if (!(new Policy())->responseAllowed($shell.'</html>',array(),200)) { return null; }
        $metadata=array();
        foreach(array('Title','Description','Keywords','Links','Styles') as $field){$getter='get'.$field;if(!method_exists($document,$getter)){return null;}$metadata[$field]=$document->$getter();}
        $metadata['scripts']=array('header'=>$document->getScripts('header'),'footer'=>$document->getScripts('footer'));
        return array('shell'=>$shell,'document'=>$metadata);
    }
    public static function render(array $entry,$registry) {
        if (!isset($entry['shell'],$entry['document'])) { throw new \RuntimeException('Invalid shared entry'); }
        $doc=$registry->get('document');$m=$entry['document'];
        foreach(array('Title','Description','Keywords') as $field){$setter='set'.$field;$doc->$setter($m[$field]);}
        foreach($m['Links'] as $link){$doc->addLink($link['href'],$link['rel']);}
        foreach($m['Styles'] as $style){$doc->addStyle($style['href'],$style['rel'],$style['media']);}
        foreach($m['scripts'] as $position=>$scripts){foreach($scripts as $script){$doc->addScript($script,$position);}}
        $header=$registry->get('load')->controller('common/header');
        $footer=$registry->get('load')->controller('common/footer');
        if (!is_string($header) || !$header || !is_string($footer) || !$footer) { throw new \RuntimeException('Native fragments unavailable'); }
        return str_replace(array('<!--skynova-dynamic-header-->','<!--skynova-dynamic-footer-->'),array($header,$footer),$entry['shell']);
    }
}
