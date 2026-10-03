<?php
if(PHP_SAPI!=='cli'){exit(1);}
require __DIR__.'/../src/core/bootstrap.php';
$root=realpath(__DIR__.'/../work/local-stage/shop');if(!$root){throw new Exception('Local staging absent');}
file_put_contents($root.'/catalog/skynova-one.js','window.skynovaDelayOrder=["one"];');
file_put_contents($root.'/catalog/skynova-two.js','window.skynovaDelayOrder.push("two");');
$settings=\FurMedia\Cache\Settings::normalize(array('delay_js'=>1,'delay_allow'=>"catalog/skynova-one.js\ncatalog/skynova-two.js"));
$assets=new \FurMedia\Cache\Assets($root,$root.'/image/cache/furmedia_cache','http://127.0.0.1:8796/',$settings);
$html='<html><head><style>.hero{color:rgb(12, 34, 56)}.unused{color:red} .below{margin-top:2000px}</style></head><body><button class="hero">Run</button><div class="below">Below</div><script src="catalog/skynova-one.js"></script><script src="catalog/skynova-two.js"></script></body></html>';
file_put_contents($root.'/skynova-fixture.html',(new \FurMedia\Cache\Optimizer($settings,$assets))->transform($html));
echo "Browser fixture prepared inside isolated local shop\n";

$settings=\FurMedia\Cache\Settings::normalize(array('js_merge'=>1,'css_merge'=>1,'merge_allow'=>"catalog/skynova-one.js\ncatalog/skynova-two.js\ncatalog/skynova-one.css\ncatalog/skynova-two.css"));
file_put_contents($root.'/catalog/skynova-one.css','.hero{color:rgb(12,34,56)}');file_put_contents($root.'/catalog/skynova-two.css','.hero{background:rgb(210,220,230)}');
$assets=new \FurMedia\Cache\Assets($root,$root.'/image/cache/furmedia_cache','http://127.0.0.1:8796/',$settings);
$html='<html><head><link rel="stylesheet" href="catalog/skynova-one.css"><link rel="stylesheet" href="catalog/skynova-two.css"></head><body><div class="hero">Merged</div><script src="catalog/skynova-one.js"></script><script src="catalog/skynova-two.js"></script></body></html>';
file_put_contents($root.'/skynova-merge-fixture.html',(new \FurMedia\Cache\Optimizer($settings,$assets))->transform($html));

$one='window.skynovaExtractOrder=["one"];';$two='window.skynovaExtractOrder.push("two");';
foreach(array('top','bottom') as $position){
    $settings=\FurMedia\Cache\Settings::normalize(array('extract_js'=>1,'script_position'=>$position,'script_allow'=>'sha256:'.hash('sha256',$one)."\nsha256:".hash('sha256',$two)));
    $assets=new \FurMedia\Cache\Assets($root,$root.'/image/cache/furmedia_cache','http://127.0.0.1:8796/',$settings);
    $html='<html><head><script>'.$one.'</script><script>'.$two.'</script></head><body><div>Extraction fixture</div></body></html>';
    file_put_contents($root.'/skynova-extracted-'.$position.'.html',(new \FurMedia\Cache\Optimizer($settings,$assets))->transform($html));
}
file_put_contents($root.'/catalog/skynova-advanced.js','window.skynovaAdvanced=(window.skynovaAdvanced||0)+1;');
$settings=\FurMedia\Cache\Settings::normalize(array('lazy_media'=>1,'lazy_blocks'=>'later','custom_css'=>'.advanced{color:rgb(12,34,56)}','custom_js'=>'window.skynovaCustom=7;','delay_rules'=>'{"catalog/skynova-advanced.js":{"event":"pointerdown","ms":15000}}','replacements'=>'[{"find":"OLDTEXT","replace":"NEWTEXT","type":"html"}]'));
$assets=new \FurMedia\Cache\Assets($root,$root.'/image/cache/furmedia_cache','http://127.0.0.1:8796/',$settings);
$html='<html><head></head><body><button class="advanced">Run</button><div id="later">OLDTEXT</div><video src="fixture.mp4"></video><audio src="fixture.mp3"></audio><script src="catalog/skynova-advanced.js"></script></body></html>';
file_put_contents($root.'/skynova-advanced-fixture.html',\FurMedia\Cache\Advanced::transform($html,$settings,$assets,'common/home'));
