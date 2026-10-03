<?php
namespace FurMedia\Cache;
/** Refresh native cart/wishlist/compare UI using the current browser session. */
class Widgets {
    public static function script($endpoint,$interval=60) {
        if(!is_string($endpoint) || substr($endpoint,0,1)!=='/' || substr($endpoint,0,2)==='//' || preg_match('/[\x00-\x20\x7f]/',$endpoint)){throw new \InvalidArgumentException('Invalid widget endpoint');}
        $url=json_encode($endpoint,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);$interval=max(10,min(300,(int)$interval))*1000;
        return '<script id="skynova-widgets">(function(){if(window.skynovaWidgets)return;window.skynovaWidgets=true;var busy=false,last="";function refresh(){if(busy||document.hidden)return;busy=true;fetch('.$url.',{credentials:"same-origin",cache:"no-store",headers:{"X-Requested-With":"XMLHttpRequest"}}).then(function(r){if(!r.ok)throw Error("widgets");return r.json();}).then(function(data){var signature=JSON.stringify(data);if(signature===last)return;last=signature;var parsed=new DOMParser().parseFromString(data.header,"text/html"),wishlist=parsed.querySelector("#wishlist-total"),current=document.querySelector("#wishlist-total");if(wishlist&&current)current.replaceWith(wishlist);var cart=document.querySelector("#cart");if(cart&&typeof data.cart==="string"){var wrap=document.createElement("div");wrap.innerHTML=data.cart;var fresh=wrap.querySelector("#cart");if(fresh)cart.replaceWith(fresh);else cart.innerHTML=data.cart;}var compare=document.querySelector("#compare-total");if(compare&&typeof data.compare==="string")compare.textContent=data.compare;}).catch(function(){}).then(function(){busy=false;});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",refresh,{once:true});else refresh();document.addEventListener("skynova:refresh",refresh);document.addEventListener("visibilitychange",refresh);setInterval(refresh,'.$interval.');})();</script>';
    }
}
