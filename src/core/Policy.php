<?php
namespace FurMedia\Cache;

class Policy {
    public function reason(array $request, array $context, array $settings) {
        if (!$settings['status']) { return 'disabled'; }
        if ($request['method'] !== 'GET') { return 'method'; }
        if (!empty($request['authorization']) || !empty($request['ajax']) || !empty($request['range'])) { return 'private-request'; }
        $rule=PageRules::route($settings,$request['route']);
        if (!empty($context['maintenance']) || !empty($context['affiliate']) || (!empty($context['customer']) && !$rule['logged']) || (!empty($context['cart']) && !$rule['cart'])) { return 'private-customer'; }
        if (empty($context['session_id'])) { return 'no-session'; }
        if (preg_match('~^(?:account|checkout|api|journal2|journal3|extension)/~i',$request['route'])) { return 'sensitive-route'; }
        if (!in_array($request['route'], Settings::lines($settings['routes']), true)) { return 'route'; }
        if(!$rule['enabled']){return 'page-rule';}
        if(!empty($settings['include_urls']) && !PageRules::matches($request['uri'],$settings['include_urls'])){return 'url-include';}
        if(PageRules::matches($request['uri'],isset($settings['exclude_urls'])?$settings['exclude_urls']:'')){return 'url-exclude';}
        if(!empty($context['customer']) && PageRules::matches($request['uri'],isset($settings['logged_exclude_urls'])?$settings['logged_exclude_urls']:'')){return 'logged-url-exclude';}
        foreach (Settings::lines($settings['exclude_paths']) as $word) {
            if (stripos($request['uri'], $word) !== false || stripos($request['route'], $word) !== false) { return 'excluded'; }
        }
        $allowed = array('route','_route_','product_id','path','manufacturer_id','information_id','sort','order','page','limit','language','currency');
        foreach ($request['query'] as $key=>$value) {
            if($key==='customer_token' && !empty($context['customer']) && isset($context['customer_token_hash']) && is_string($value) && hash_equals($context['customer_token_hash'],hash('sha256',$value))){continue;}
            if (!in_array($key, $allowed, true) || !is_scalar($value) || strlen((string)$value) > 512) { return 'query'; }
        }
        if (strlen($request['uri']) > 2048) { return 'uri-length'; }
        return '';
    }

    public function key(array $request, array $context) {
        // Full session and cookie digest intentionally sacrifices cross-visitor hits for isolation.
        // Never persist raw session IDs, cookies, personal data, or customer HTML across sessions.
        $query = $request['query']; ksort($query);
        return 'page:' . hash('sha256', serialize(array($request['uri'],$request['route'],$query,$context)));
    }

    public function responseAllowed($body, array $headers, $status,$allow404=false,$nativeCustomerToken=null) {
        if (($status !== 200 && !($allow404 && $status===404)) || !is_string($body) || stripos($body, '</html>') === false) { return false; }
        foreach ($headers as $header) {
            if (preg_match('~^(?:Location|Set-Cookie|Content-Disposition|Content-Encoding):~i', $header)) { return false; }
            if (preg_match('~^HTTP/\S+\s+(\d+)~i', $header, $statusMatch) && (int)$statusMatch[1] !== $status) { return false; }
            if (preg_match('~^Cache-Control:.*(?:no-store|no-cache)~i', $header)) { return false; }
            if (stripos($header,'Content-Type:') === 0 && !preg_match('~^Content-Type:\s*text/html\b~i',$header)) { return false; }
            if (preg_match('~^Vary:.*\*~i', $header)) { return false; }
        }
        // Tokens/nonces must be regenerated; never share or replay them from disk.
        // OC4 customer_token links are session-bound, not single-use CSRF fields. Only an exact
        // current native token may be retained inside explicitly approved private customer pages.
        if(is_string($nativeCustomerToken) && preg_match('/^[a-zA-Z0-9]{16,128}$/D',$nativeCustomerToken)){
            $body=preg_replace_callback('~customer_token=([a-zA-Z0-9]{16,128})(?=[&\x22\x27<>\s#]|$)~',function($m)use($nativeCustomerToken){return hash_equals($nativeCustomerToken,$m[1])?'skynova_native_link':$m[0];},$body);
        }
        if (preg_match('~(?:nonce\s*=|(?:user_token|csrf|form_token|api_token|customer_token|session_id|PHPSESSID)[="\x27\s:]|name=["\x27]token["\x27])~i', $body)) { return false; }
        return true;
    }
}
