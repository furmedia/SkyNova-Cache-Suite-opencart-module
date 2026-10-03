<?php
namespace FurMedia\Cache;
/** Additional DB controls, invoked only after native permission and nonce checks. */
class SuiteAdmin {
    public static function accepts($op){return in_array($op,array('search_start','search_step','search_pause','search_resume','db_archive_start','db_archive_step','db_archive_pause','db_archive_resume','db_archive_restore','db_archive_commit','db_archive_undo','db_archive_convert','maintenance_run','maintenance_pause','maintenance_resume','maintenance_retry','query_advice','query_measure','query_benchmark'),true);}
    public static function run($op,array $post,$db,$prefix,array $settings,$maintenance,$storeId){
        if($storeId!==0){throw new \RuntimeException('Database controls belong to the main store');}
        if(!Paths::privateStorage(DIR_CACHE,dirname(rtrim(DIR_APPLICATION,'/\\')))){throw new \RuntimeException('Private database storage required');}
        $root=DIR_CACHE.'furmedia_cache-db-archives';$id=isset($post['db_archive_id'])?$post['db_archive_id']:'';
        if(strpos($op,'search_')===0){if(!$settings['search_acceleration']){throw new \RuntimeException('Enable the experimental search index first');}$index=new SearchIndex($db,$prefix,DIR_CACHE.'furmedia_cache-search',$root,$settings['db_archive_mb']);if($op==='search_start'){return $index->start();}if($op==='search_step'){return $index->step();}return $index->pause($op==='search_resume');}
        if(strpos($op,'db_archive_')===0){$a=new Dbarchive($db,$prefix,$root,$settings['db_archive_mb']);
            if($op==='db_archive_start'){return $a->start(isset($post['db_archive_table'])?$post['db_archive_table']:'');}
            if($op==='db_archive_step'){return $a->step($id);}
            if($op==='db_archive_pause'||$op==='db_archive_resume'){return $a->pause($id,$op==='db_archive_resume');}
            if(empty($post['db_confirm'])){throw new \RuntimeException('Confirm the selected database change');}
            if($op==='db_archive_restore'){return $a->restoreStart($id);}
            if(!$maintenance){throw new \RuntimeException('Enable native store maintenance before replacing or converting a table');}
            if($op==='db_archive_convert'){return $a->convert($id);}
            return $a->commit($id,true,$op==='db_archive_undo');
        }
        if(strpos($op,'maintenance_')===0){$m=new Maintenance($db,$prefix,DIR_CACHE.'furmedia_cache-db-schedules',$root,$settings['db_archive_mb']);
            if($op==='maintenance_run'){return $m->tick($settings['db_schedules'],$settings['db_schedule_limit']);}
            return $m->control($op==='maintenance_pause',$op==='maintenance_retry');
        }
        $q=new QueryAdvice($db,$prefix,DIR_CACHE.'furmedia_cache-query-advice');
        if($op==='query_advice'){return $q->recommend(Sqlmetrics::rows(DIR_CACHE.'furmedia_cache-sqlprofile-0/metrics'));}
        return $q->measure(html_entity_decode(isset($post['explain_sql'])?$post['explain_sql']:'',ENT_QUOTES,'UTF-8'),$op==='query_benchmark');
    }
    public static function render(array $data){$e=array('FurMedia\\Cache\\Admin','e');ob_start(); ?>
<section class="fm-panel" id="fm-resumable-db"><h2>Arhive DB în loturi și programări</h2><p>Copie stabilă per tabel, export și verificare în loturi. Copierea inițială poate bloca scrierile și necesită spațiu suplimentar în MySQL. Restaurarea pregătește un tabel separat; înlocuirea și conversia necesită mentenanța nativă activă. Tabelele cu relații externe sau trigger-e necesită restaurare administrată separat.</p>
<input name="db_archive_table" aria-label="Tabel nativ pentru arhivă" placeholder="prefix_product_description"><button class="secondary" name="operation" value="db_archive_start">Începe arhiva</button>
<input name="db_archive_id" aria-label="ID arhivă DB" placeholder="ID arhivă de 32 caractere">
<div class="fm-actions"><?php foreach(array('step'=>'Execută lotul următor','pause'=>'Pauză','resume'=>'Reia','restore'=>'Pregătește restaurarea','commit'=>'Înlocuiește tabelul verificat','undo'=>'Revino la tabelul anterior','convert'=>'Convertește arhiva MyISAM în InnoDB') as $action=>$label){?><button class="secondary" name="operation" value="db_archive_<?= $e($action) ?>"><?= $e($label) ?></button><?php } ?></div>
<p>Confirmarea operațiilor DB este în secțiunea Mentenanță DB. Arhivele rămân în storage privat; salvează ID-ul pentru continuare.</p>
<?php if(!empty($data['db_archives'])){?><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $e(json_encode($data['db_archives'],JSON_PRETTY_PRINT)) ?></pre><?php } ?>
<div class="fm-actions"><?php foreach(array('run'=>'Execută programările scadente','pause'=>'Oprește programările','resume'=>'Reia programările','retry'=>'Reîncearcă operațiile eșuate') as $action=>$label){?><button class="secondary" name="operation" value="maintenance_<?= $e($action) ?>"><?= $e($label) ?></button><?php } ?></div>
<p>Programările se salvează ca JSON în setări: id, action (ANALYZE, OPTIMIZE sau backup), tables, at (HH:MM), timezone, days (1–7), enabled. Runnerul CRON existent execută operațiile scadente. Pauza este persistentă.</p>
<?php if(!empty($data['db_schedule'])){?><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $e(json_encode($data['db_schedule'],JSON_PRETTY_PRINT)) ?></pre><?php } ?>
<button class="secondary" name="operation" value="query_advice">Recomandă indecși din mostrele măsurate</button><button class="secondary" name="operation" value="query_measure">Compară EXPLAIN</button><button class="secondary" name="operation" value="query_benchmark">Măsoară SELECT de trei ori</button><p>Folosește câmpul SELECT din Mentenanță DB. Măsurarea cere LIMIT final de maximum 100; poate rămâne costisitoare. Recomandările sunt candidați și necesită verificarea planului.</p>
<h3>Index auxiliar de căutare experimental</h3><p>Păstrează condiția LIKE nativă și adaugă selecția candidaților. Numai căutări SQL simple, fără subinterogări, cu minimum trei caractere, fără wildcarduri interne. Journal păstrează căutarea nativă. După importuri externe, invalidează și reconstruiește indexul.</p><div class="fm-actions"><?php foreach(array('start'=>'Construiește indexul','step'=>'Continuă lotul','pause'=>'Pauză index','resume'=>'Reia indexul') as $action=>$label){?><button class="secondary" name="operation" value="search_<?= $e($action) ?>"><?= $e($label) ?></button><?php } ?></div><?php if(!empty($data['search_state'])){?><pre><?= $e(json_encode($data['search_state'],JSON_PRETTY_PRINT)) ?></pre><?php } ?><h3>Cache PWA în browserul administratorului</h3><p>Ștergerea de aici afectează acest browser. Invalidarea cache-ului serverului schimbă versiunea resurselor SkyNova la următoarea actualizare a workerului online.</p><?php echo Pwa::controls($data['settings']['pwa_cache_names']); ?><h3>Afișări publice agregate</h3><p>Număr de afișări, fără identificatori de vizitatori; nu reprezintă vizitatori unici. Cache-ul servit integral de serverul LiteSpeed poate ocoli numărătoarea PHP.</p><?php if(!empty($data['views'])){?><pre><?= $e(json_encode($data['views'],JSON_PRETTY_PRINT)) ?></pre><?php } ?></section>
<?php return ob_get_clean();}
}
