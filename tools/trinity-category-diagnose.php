<?php
if(PHP_SAPI!=='cli'){exit(1);}
$c=curl_init('https://trinityconcept.ro/index.php?route=product/category&path=64');
curl_setopt_array($c,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_ENCODING=>''));
$body=curl_exec($c);$report=array('http'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'seconds'=>curl_getinfo($c,CURLINFO_TOTAL_TIME),'bytes'=>strlen((string)$body),'error'=>curl_error($c));curl_close($c);
libxml_use_internal_errors(true);$doc=new DOMDocument();$doc->loadHTML((string)$body);$xpath=new DOMXPath($doc);$rows=array();
foreach($xpath->query('//body/* | //*[@id="content"]/* | //*[@id="column-left"]/* | //script') as $node){$rows[]=array('tag'=>$node->nodeName,'id'=>$node->getAttribute('id'),'class'=>$node->getAttribute('class'),'bytes'=>strlen($doc->saveHTML($node)));}
usort($rows,function($a,$b){return $a['bytes']===$b['bytes']?0:($a['bytes']>$b['bytes']?-1:1);});$report['largest']=array_slice($rows,0,20);$report['nodes']=$doc->getElementsByTagName('*')->length;
$details=array();foreach($xpath->query('//*[contains(@class,"module-")]') as $node){$details[]=array('tag'=>$node->nodeName,'class'=>$node->getAttribute('class'),'bytes'=>strlen($doc->saveHTML($node)));}usort($details,function($a,$b){return $a['bytes']===$b['bytes']?0:($a['bytes']>$b['bytes']?-1:1);});$report['grid_details']=array_slice($details,0,8);
file_put_contents(__DIR__.'/category-diagnosis.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
