<?php
if(!defined('ABSPATH'))exit;
/* DCS catalog staging: rows are never public until approved. */
function tpm_dcs_table(){global $wpdb;return $wpdb->prefix.'tpm_dcs_catalog';}
function tpm_dcs_schema(){
 global $wpdb;$table=tpm_dcs_table();$collate=$wpdb->get_charset_collate();
 require_once ABSPATH.'wp-admin/includes/upgrade.php';
 dbDelta("CREATE TABLE $table (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 supplier_sku varchar(100) NOT NULL,
 title text NOT NULL,
 brand varchar(190) NOT NULL DEFAULT '',
 model varchar(190) NOT NULL DEFAULT '',
 group_name varchar(190) NOT NULL DEFAULT '',
 subgroup varchar(190) NOT NULL DEFAULT '',
 cost decimal(15,4) NOT NULL DEFAULT 0,
 supplier_stock int NOT NULL DEFAULT 0,
 remote_stock int NOT NULL DEFAULT 0,
 lead_days int NOT NULL DEFAULT 0,
 remote_days int NOT NULL DEFAULT 0,
 product_id bigint(20) unsigned NOT NULL DEFAULT 0,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY supplier_sku (supplier_sku),
 KEY product_id (product_id)
 ) $collate;");
 update_option('tpm_dcs_schema_version','1',false);
}
add_action('admin_init',function(){if(get_option('tpm_dcs_schema_version')!=='1'&&current_user_can('manage_woocommerce'))tpm_dcs_schema();});
function tpm_dcs_catalog_text($value){
 $value=(string)$value;
 if(!preg_match('//u',$value)){$converted=@iconv('Windows-1252','UTF-8//IGNORE',$value);if($converted!==false)$value=$converted;}
 if(preg_match('/(?:Ã.|Â.|â€|â€™)/u',$value)){$fixed=@iconv('UTF-8','Windows-1252//IGNORE',$value);if($fixed!==false&&preg_match('//u',$fixed)&&substr_count($fixed,'Ã')<substr_count($value,'Ã'))$value=$fixed;}
 return $value;
}
function tpm_dcs_csv_col($row,$map,$names,$default=''){
 foreach($names as $name){$key=mb_strtolower($name,'UTF-8');if(isset($map[$key]))return trim(tpm_dcs_catalog_text($row[$map[$key]]??$default));}return $default;
}
function tpm_dcs_start_import(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_start_import');
 $url=get_option('tpm_dcs_csv_url','');
 if(!$url||!tpm_dcs_csv_url_valid($url))wp_die('Gem først et gyldigt DCS-link under Leverandører.');
 if(get_option('tpm_dcs_import_job'))wp_die('En import er allerede i gang.');
 $selected=array_values(array_filter(array_map('sanitize_text_field',(array)wp_unslash($_POST['dcs_groups']??[]))));
 $all=!empty($_POST['dcs_all']);
 if(!$all&&!$selected)wp_die('Vælg mindst én DCS-varegruppe eller hele kataloget.');
 $path=wp_tempnam('tpm-dcs-catalog.csv');
 if(!$path)wp_die('Kunne ikke oprette midlertidig fil.');
 $r=wp_safe_remote_get($url,['timeout'=>120,'redirection'=>2,'stream'=>true,'filename'=>$path,'headers'=>['Accept'=>'text/csv,text/plain,*/*']]);
 if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200){@unlink($path);wp_die('Kunne ikke hente DCS CSV. Kontrollér URL og adgang.');}
 $h=fopen($path,'rb');$first=$h?fgets($h):'';if($h)fclose($h);
 if(!$first||count(str_getcsv($first,';'))<5||stripos($first,'<html')!==false){@unlink($path);wp_die('DCS returnerede ikke en gyldig CSV.');}
 update_option('tpm_dcs_import_job',['path'=>$path,'offset'=>0,'count'=>0,'started'=>time(),'groups'=>$all?[]:$selected,'all'=>$all,'scanned'=>0,'matched'=>0,'inserted'=>0,'updated'=>0],false);
 wp_schedule_single_event(time()+5,'tpm_dcs_import_batch');
 wp_safe_redirect(admin_url('admin.php?page=terttus-suppliers#tpm-dcs-catalog'));exit;
}
add_action('admin_post_tpm_dcs_start_import','tpm_dcs_start_import');
/* Fast staging import: bounded multi-row SQL upserts. product_id is deliberately
   excluded from updates so already published WooCommerce links survive. */
function tpm_dcs_import_flush($table,$rows,&$job){
 global $wpdb;
 if(!$rows)return true;
 $skus=array_keys($rows);
 $placeholders=implode(',',array_fill(0,count($skus),'%s'));
 $existing=$wpdb->get_col($wpdb->prepare("SELECT supplier_sku FROM $table WHERE supplier_sku IN ($placeholders)",...$skus));
 $existing_set=array_fill_keys($existing,true);
 $fields=['supplier_sku','title','brand','model','group_name','subgroup','cost','supplier_stock','remote_stock','lead_days','remote_days','updated_at'];
 $formats=['%s','%s','%s','%s','%s','%s','%f','%d','%d','%d','%d','%s'];
 $values=[];$tuples=[];
 foreach($rows as $r){$tuples[]='('.implode(',',$formats).')';foreach($fields as $field)$values[]=$r[$field];}
 $updates=[];foreach(array_slice($fields,1) as $field)$updates[]="`$field`=VALUES(`$field`)";
 $sql="INSERT INTO $table (".implode(',',array_map(function($field){return "`$field`";},$fields)).") VALUES ".implode(',',$tuples)." ON DUPLICATE KEY UPDATE ".implode(',',$updates);
 $result=$wpdb->query($wpdb->prepare($sql,...$values));
 if($result===false)return false;
 foreach($skus as $sku){if(isset($existing_set[$sku]))$job['updated']++;else $job['inserted']++;}
 return true;
}
function tpm_dcs_import_batch(){
 global $wpdb;
 $job=get_option('tpm_dcs_import_job');if(!is_array($job))return;
 $path=$job['path']??'';
 if(!$path||!is_file($path)){delete_option('tpm_dcs_import_job');update_option('tpm_dcs_import_error','Importfilen mangler.',false);return;}
 $h=@fopen($path,'rb');if(!$h)return;
 $headers=fgetcsv($h,0,';','"','\\');if(!$headers){fclose($h);return;}
 $map=[];foreach($headers as $i=>$v)$map[mb_strtolower(tpm_dcs_catalog_text(trim((string)$v," \t\r\n\xEF\xBB\xBF")),'UTF-8')]=$i;
 if(!isset($map['varenummer'])||!isset($map['varenavn'])){fclose($h);delete_option('tpm_dcs_import_job');update_option('tpm_dcs_import_error','CSV mangler varenummer eller varenavn.',false);@unlink($path);return;}
 if(!empty($job['offset']))fseek($h,(int)$job['offset']);
 $table=tpm_dcs_table();$rows=[];$count=0;$failed=false;
 $limit=5000;
 while($count<$limit&&($row=fgetcsv($h,0,';','"','\\'))!==false){
  if(!get_option('tpm_dcs_import_job'))break;
  $count++;$job['scanned']++;
  $group=tpm_dcs_csv_col($row,$map,['Varegruppenavn']);
  if(!empty($job['groups'])&&!in_array($group,$job['groups'],true))continue;
  $sku=tpm_dcs_csv_col($row,$map,['Varenummer']);$title=tpm_dcs_csv_col($row,$map,['Varenavn']);
  if($sku===''||$title==='')continue;
  $job['matched']++;
  $rows[$sku]=[
   'supplier_sku'=>$sku,'title'=>$title,
   'brand'=>tpm_dcs_csv_col($row,$map,['Producentnavn']),
   'model'=>tpm_dcs_csv_col($row,$map,['Modelbetegnelse']),
   'group_name'=>$group,'subgroup'=>tpm_dcs_csv_col($row,$map,['Undergruppenavn']),
   'cost'=>(float)str_replace(',','.',tpm_dcs_csv_col($row,$map,['Pris'])),
   'supplier_stock'=>max(0,(int)tpm_dcs_csv_col($row,$map,['Beholdning'])),
   'remote_stock'=>max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal på fjernlager','Antal på fjernlager'])),
   'lead_days'=>max(0,(int)tpm_dcs_csv_col($row,$map,['Leveringstid'])),
   'remote_days'=>max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal dage fra fjernlager','Antal dage fra fjernlager'])),
   'updated_at'=>current_time('mysql')
  ];
  if(count($rows)>=200){if(!tpm_dcs_import_flush($table,$rows,$job)){$failed=true;break;}$rows=[];}
 }
 if(!$failed&&$rows&&!tpm_dcs_import_flush($table,$rows,$job))$failed=true;
 $done=feof($h);$job['offset']=ftell($h);$job['count']=($job['count']??0)+$count;fclose($h);
 if($failed){update_option('tpm_dcs_import_error','Databasefejl under masseimport. Importen er sat på pause; de allerede gemte varer er bevaret.',false);$job['failed']=true;update_option('tpm_dcs_import_job',$job,false);return;}
 if(!get_option('tpm_dcs_import_job'))return;
 if($done){
  @unlink($path);delete_option('tpm_dcs_import_job');
  update_option('tpm_dcs_last_import',['at'=>current_time('mysql'),'count'=>$job['count'],'scanned'=>$job['scanned'],'matched'=>$job['matched'],'inserted'=>$job['inserted'],'updated'=>$job['updated']],false);
  delete_option('tpm_dcs_import_error');
 }else{update_option('tpm_dcs_import_job',$job,false);wp_schedule_single_event(time()+1,'tpm_dcs_import_batch');}
}
add_action('tpm_dcs_import_batch','tpm_dcs_import_batch');
function tpm_dcs_stop_import(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_stop_import');
 $job=get_option('tpm_dcs_import_job');
 if(is_array($job)){delete_option('tpm_dcs_import_job');if(!empty($job['path'])&&is_file($job['path']))@unlink($job['path']);}
 wp_clear_scheduled_hook('tpm_dcs_import_batch');
 update_option('tpm_dcs_import_notice','Import stoppet. Allerede indlæste varer er bevaret.',false);
 wp_safe_redirect(admin_url('admin.php?page=terttus-suppliers#tpm-dcs-catalog'));exit;
}
add_action('admin_post_tpm_dcs_stop_import','tpm_dcs_stop_import');
function tpm_dcs_available_groups(){
 global $wpdb;$table=tpm_dcs_table();
 return $wpdb->get_col("SELECT DISTINCT group_name FROM $table WHERE group_name <> '' ORDER BY group_name ASC LIMIT 200");
}
function tpm_dcs_catalog_page(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 global $wpdb;$table=tpm_dcs_table();$search=sanitize_text_field(wp_unslash($_GET['dcs_search']??''));$page=max(1,absint($_GET['dcs_page']??1));$per=30;$group=sanitize_text_field(wp_unslash($_GET['dcs_group']??''));$subgroup=sanitize_text_field(wp_unslash($_GET['dcs_subgroup']??''));$filter=sanitize_key($_GET['dcs_filter']??'all');if(!in_array($filter,['all','pending','published','local','remote'],true))$filter='all';
 $where='1=1';$params=[];if($search!==''){$where='(title LIKE %s OR supplier_sku LIKE %s OR brand LIKE %s OR model LIKE %s)';$like='%'.$wpdb->esc_like($search).'%';$params=[$like,$like,$like,$like];}
 if($group!==''){$where.=' AND group_name=%s';$params[]=$group;}
 if($subgroup!==''){$where.=' AND subgroup=%s';$params[]=$subgroup;}
 if($filter==='pending')$where.=' AND product_id=0';elseif($filter==='published')$where.=' AND product_id>0';elseif($filter==='local')$where.=' AND supplier_stock>0';elseif($filter==='remote')$where.=' AND supplier_stock=0 AND remote_stock>0';
 $count_sql="SELECT COUNT(*) FROM $table WHERE $where";$total=(int)$wpdb->get_var($params?$wpdb->prepare($count_sql,...$params):$count_sql);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY product_id ASC, id DESC LIMIT %d OFFSET %d",...array_merge($params,[$per,($page-1)*$per])));
 $job=get_option('tpm_dcs_import_job');$last=get_option('tpm_dcs_last_import');$error=get_option('tpm_dcs_import_error');
 echo '<section id="tpm-dcs-catalog" class="tpm-card" style="margin:20px 0;padding:20px"><h2>DCS produktkatalog</h2><p>Alle varer er interne, indtil du udgiver dem. Prisforslag beregnes med moms (25 %) og valgt avance på kostprisen ekskl. moms.</p>';
 $catalog_total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
 echo '<p><strong>'.number_format_i18n($catalog_total).' varer i kataloget</strong></p>';
 if($job){
 echo '<p><strong>'.(!empty($job['failed'])?'Import sat på pause pga. fejl':'Import kører').'</strong> · CSV-linjer gennemgået: '.number_format_i18n((int)($job['scanned']??$job['count']??0)).' · Matchede varer: '.number_format_i18n((int)($job['matched']??0)).' · Nye varer: '.number_format_i18n((int)($job['inserted']??0)).' · Opdaterede varer: '.number_format_i18n((int)($job['updated']??0)).'</p>';
 }elseif($last){echo '<p>Seneste import: '.esc_html($last['at']).' · '.number_format_i18n((int)($last['scanned']??$last['count']??0)).' linjer gennemgået · '.number_format_i18n((int)($last['inserted']??0)).' nye varer</p>';}
 else echo '<p>Ingen fuldført import.</p>';
 if(isset($_GET['dcs_bulk_published']))echo '<p class="notice notice-success" style="padding:10px">'.(int)$_GET['dcs_bulk_published'].' varer udgivet.</p>';
 if(isset($_GET['dcs_enriched']))echo '<p class="notice notice-info" style="padding:10px">Produktdata hentet. '.(int)$_GET['dcs_enriched'].' nye oplysninger gemt.</p>';
 if($error)echo '<p style="color:#b42318">'.esc_html($error).'</p>';
 if($notice=get_option('tpm_dcs_import_notice')){echo '<p class="notice notice-success" style="padding:10px">'.esc_html($notice).'</p>';delete_option('tpm_dcs_import_notice');}
 if($job){
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('tpm_dcs_stop_import','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_stop_import"><button class="button button-secondary">Stop import og behold varer</button></form>';
 }else{
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('tpm_dcs_start_import','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_start_import">';
 echo '<p><label><input type="checkbox" name="dcs_all" value="1" style="width:16px;height:16px;min-height:0"> <strong>Importér HELE DCS-kataloget (alle varegrupper)</strong></label></p><p><strong>Eller vælg enkelte DCS-varegrupper</strong></p><div style="display:flex;flex-wrap:wrap;gap:8px 18px;max-height:210px;overflow:auto;padding:10px;border:1px solid #ddd;border-radius:6px">';
 foreach(tpm_dcs_available_groups() as $group)echo '<label style="min-width:190px"><input type="checkbox" name="dcs_groups[]" value="'.esc_attr($group).'"> '.esc_html($group).'</label>';
 echo '</div><p class="description">Listen er baseret på allerede indlæste varegrupper. Hvis en gruppe mangler, kan den tilføjes her:</p><input name="dcs_groups[]" placeholder="DCS-varegruppenavn" style="width:300px;max-width:100%"> <button class="button button-primary">Importér valgte varegrupper</button></form>';
 }
 $sync=get_option('tpm_dcs_active_sync_job');$sync_last=get_option('tpm_dcs_active_sync_last');
 echo '<div style="margin:14px 0;padding:12px;border:1px solid #ddd;border-radius:6px"><strong>Synkronisering af udgivne DCS-varer</strong><p>Opdaterer kun leverandørpris, lager og leveringstider. Dine salgspriser og kategorier ændres ikke.</p>';
 if($sync){echo '<p>Synkronisering kører · '.(int)($sync['scanned']??0).' CSV-linjer gennemgået · '.(int)($sync['updated']??0).' udgivne varer opdateret.</p>';}
 else {if($sync_last)echo '<p>Seneste synkronisering: '.esc_html($sync_last['at']??'').' · '.(int)($sync_last['updated']??0).' varer opdateret.</p>';
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('tpm_dcs_active_sync_start','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_active_sync_start"><button class="button">Opdatér udgivne varer nu</button></form>';}
 echo '<p class="description">Kører automatisk dagligt via WP-Cron. Kræver at DCS-prisfilen er tilgængelig.</p></div>';
 $groups=$wpdb->get_col("SELECT DISTINCT group_name FROM $table WHERE group_name<>'' ORDER BY group_name");
 $subgroups=$group!==''?$wpdb->get_col($wpdb->prepare("SELECT DISTINCT subgroup FROM $table WHERE group_name=%s AND subgroup<>'' ORDER BY subgroup",$group)):[];
 echo '<form method="get" style="display:flex;gap:8px;margin:14px 0;flex-wrap:wrap;align-items:center"><input type="hidden" name="page" value="terttus-suppliers"><select name="dcs_group" id="tpm-dcs-group" style="width:210px"><option value="">Alle DCS-hovedkategorier</option>';
 foreach($groups as $g)echo '<option value="'.esc_attr($g).'" '.selected($group,$g,false).'>'.esc_html($g).'</option>';
 echo '</select><select name="dcs_subgroup" style="width:210px"><option value="">Alle underkategorier</option>';
 foreach($subgroups as $sg)echo '<option value="'.esc_attr($sg).'" '.selected($subgroup,$sg,false).'>'.esc_html($sg).'</option>';
 echo '</select><select name="dcs_filter" style="width:170px">';foreach(['all'=>'Alle varer','pending'=>'Ikke oprettet','published'=>'Udgivet','local'=>'På DCS-lager','remote'=>'På fjernlager'] as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($filter,$key,false).'>'.esc_html($label).'</option>';echo '</select><input name="dcs_search" value="'.esc_attr($search).'" placeholder="Søg navn, varenummer eller mærke" style="width:330px;max-width:65%"><button class="button">Søg</button></form>';
 echo '<p style="margin-top:-5px" class="description">Viser '.number_format_i18n($total).' produkter med de valgte filtre. DCS-kategorier er kun søgefiltre og ændrer ikke WooCommerce-kategorier.</p>';
 echo '<p style="display:flex;align-items:center;gap:8px"><label for="tpm-dcs-margin">Prisforslag, avance:</label><select id="tpm-dcs-margin"><option value="15">15 %</option><option value="20" selected>20 %</option><option value="25">25 %</option><option value="30">30 %</option><option value="40">40 %</option></select><span class="description">Du kan altid rette prisen manuelt.</span></p>';
 echo '<style>#tpm-dcs-catalog input[type=checkbox]{appearance:auto!important;-webkit-appearance:checkbox!important;width:16px!important;height:16px!important;min-height:0!important;display:inline-block!important;vertical-align:middle!important;margin:0 6px 0 0!important;padding:0!important}#tpm-dcs-catalog .tpm-dcs-table{table-layout:fixed;width:100%}#tpm-dcs-catalog .tpm-dcs-table td,#tpm-dcs-catalog .tpm-dcs-table th{padding:9px 8px;vertical-align:middle}#tpm-dcs-catalog .tpm-dcs-table td:first-child{overflow-wrap:anywhere}#tpm-dcs-catalog .tpm-dcs-actions{display:flex;flex-wrap:nowrap;gap:4px;align-items:center;min-width:0}#tpm-dcs-catalog .tpm-dcs-table{min-width:1060px}#tpm-dcs-catalog .tpm-dcs-actions>.button{padding-left:7px;padding-right:7px;font-size:12px}#tpm-dcs-catalog .tpm-dcs-extra{display:none;flex-basis:100%;padding-top:5px}#tpm-dcs-catalog .tpm-dcs-actions:has(.tpm-dcs-extra:not([hidden])){flex-wrap:wrap}#tpm-dcs-catalog .tpm-dcs-extra:not([hidden]){display:flex;gap:5px;align-items:center}#tpm-dcs-catalog .tpm-dcs-actions .tpm-dcs-toggle{white-space:nowrap;padding:0 5px}#tpm-dcs-catalog .tpm-dcs-actions select{width:125px;max-width:100%;min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions input{width:90px;min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions button{min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions .tpm-dcs-new{width:150px}@media(max-width:1100px){#tpm-dcs-catalog .tpm-dcs-table{min-width:900px}}</style>';
 echo '<div style="margin:10px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button type="button" class="button button-primary" id="tpm-dcs-bulk-button">Udgiv markerede</button><select id="tpm-dcs-bulk-category" style="max-width:240px"><option value="">Fælles kategori til markerede</option>';foreach($cats as $cat)echo '<option value="'.(int)$cat->term_id.'">'.esc_html($cat->name).'</option>';echo '</select><button type="button" class="button" id="tpm-dcs-apply-category">Anvend kategori</button><span class="description">Markér varer på denne side, anvend fælles kategori, kontrollér priserne og udgiv.</span></div><form id="tpm-dcs-bulk-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('tpm_dcs_bulk_publish','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_bulk_publish"></form>';
 echo '<div style="overflow-x:auto"><table class="widefat striped tpm-dcs-table"><colgroup><col style="width:3%"><col style="width:30%"><col style="width:11%"><col style="width:9%"><col style="width:12%"><col style="width:9%"><col style="width:29%"></colgroup><thead><tr><th><input type="checkbox" id="tpm-dcs-check-all" aria-label="Markér alle ikke oprettede varer"></th><th>Produkt</th><th>Indkøb</th><th>DCS lager</th><th>Fjernlager</th><th>Status</th><th>Kategori og pris</th></tr></thead><tbody>';
 $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);if(is_wp_error($cats))$cats=[];
 foreach($rows as $r){
  echo '<tr><td>'.(!$r->product_id?'<input type="checkbox" class="tpm-dcs-row-check" value="'.(int)$r->id.'" aria-label="Markér vare">':'').'</td><td><strong>'.esc_html(tpm_dcs_catalog_text($r->title)).'</strong><br><small>'.esc_html(tpm_dcs_catalog_text($r->brand.' · '.$r->supplier_sku.' · '.$r->model)).'</small></td><td>'.esc_html(number_format_i18n((float)$r->cost,2)).' kr.</td><td>'.(int)$r->supplier_stock.'</td><td>'.(int)$r->remote_stock.' · '.(int)$r->remote_days.' dage</td>';
  echo '<td>'.($r->product_id?'<a href="'.esc_url(get_edit_post_link((int)$r->product_id)).'">Udgivet</a>':'Ikke oprettet').'</td><td>';
  if(!$r->product_id){
   echo '<form class="tpm-dcs-actions" method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-cost="'.esc_attr($r->cost).'">'.wp_nonce_field('tpm_dcs_publish','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_publish"><input type="hidden" name="row_id" value="'.(int)$r->id.'">';
   echo '<select name="category"><option value="">Vælg kategori</option>';foreach($cats as $cat)echo '<option value="'.(int)$cat->term_id.'">'.esc_html(str_repeat('— ',count(get_ancestors($cat->term_id,'product_cat'))).$cat->name).'</option>';echo '</select>';
   echo '<input class="tpm-dcs-price" type="number" name="sale_price" min="0.01" step="0.01" required placeholder="Pris inkl. moms" title="Salgspris inkl. moms"><button class="button button-primary">Udgiv</button><button type="button" class="button tpm-dcs-toggle" aria-expanded="false" title="Opret ny kategori">+ Kategori</button>';
   echo '<div class="tpm-dcs-extra" hidden><input class="tpm-dcs-new" name="new_category" placeholder="Nyt kategorinavn" maxlength="100"><select name="parent_category" title="Overkategori"><option value="0">Ingen overkategori</option>';foreach($cats as $cat)echo '<option value="'.(int)$cat->term_id.'">'.esc_html($cat->name).'</option>';echo '</select></div></form>';
  }else{
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="tpm-dcs-enrich">'.wp_nonce_field('tpm_dcs_enrich','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_enrich"><input type="hidden" name="row_id" value="'.(int)$r->id.'"><input type="url" name="product_url" required placeholder="DCS-produktlink" aria-label="DCS-produktlink" style="width:170px;max-width:100%"><button class="button">Hent produktdata</button></form>';
  }echo '</td></tr>';
 }
 echo '</tbody></table></div>';
 $pages=(int)ceil($total/$per);if($pages>1){echo '<p>';for($i=max(1,$page-3);$i<=min($pages,$page+3);$i++)echo '<a class="button '.($i===$page?'button-primary':'').'" href="'.esc_url(add_query_arg(['page'=>'terttus-suppliers','dcs_page'=>$i,'dcs_search'=>$search,'dcs_filter'=>$filter,'dcs_group'=>$group,'dcs_subgroup'=>$subgroup],admin_url('admin.php'))).'">'.$i.'</a> ';echo '</p>';}
 echo '<script>(function(){const select=document.getElementById("tpm-dcs-margin");if(!select)return;function fill(){const pct=Number(select.value)/100;document.querySelectorAll("#tpm-dcs-catalog .tpm-dcs-actions").forEach(function(form){const input=form.querySelector(".tpm-dcs-price");if(!input||input.dataset.manual==="1")return;const cost=Number(form.dataset.cost);if(cost>0)input.value=(Math.ceil(cost*(1+pct)*1.25)-0.05).toFixed(2);});}document.querySelectorAll("#tpm-dcs-catalog .tpm-dcs-price").forEach(function(input){input.addEventListener("input",function(){input.dataset.manual="1";});});select.addEventListener("change",fill);document.querySelectorAll("#tpm-dcs-catalog .tpm-dcs-toggle").forEach(function(button){button.addEventListener("click",function(){const extra=button.closest("form").querySelector(".tpm-dcs-extra");extra.hidden=!extra.hidden;button.setAttribute("aria-expanded",String(!extra.hidden));});});const all=document.getElementById("tpm-dcs-check-all");if(all)all.addEventListener("change",function(){document.querySelectorAll(".tpm-dcs-row-check").forEach(function(ch){ch.checked=all.checked;});});const apply=document.getElementById("tpm-dcs-apply-category");if(apply)apply.addEventListener("click",function(){const val=document.getElementById("tpm-dcs-bulk-category").value;if(!val){alert("Vælg en fælles kategori.");return;}let count=0;document.querySelectorAll(".tpm-dcs-row-check:checked").forEach(function(ch){const select=ch.closest("tr").querySelector(".tpm-dcs-actions [name=category]");if(select){select.value=val;count++;}});if(!count)alert("Markér først de varer, der skal have kategorien.");});const groupSelect=document.getElementById("tpm-dcs-group");if(groupSelect)groupSelect.addEventListener("change",function(){const url=new URL(window.location.href);url.searchParams.set("page","terttus-suppliers");url.searchParams.set("dcs_group",groupSelect.value);url.searchParams.delete("dcs_subgroup");url.searchParams.delete("dcs_page");url.hash="tpm-dcs-catalog";window.location.assign(url.toString());});const subSelect=document.querySelector("#tpm-dcs-catalog select[name=dcs_subgroup]");if(subSelect)subSelect.addEventListener("change",function(){const url=new URL(window.location.href);url.searchParams.set("page","terttus-suppliers");url.searchParams.set("dcs_group",groupSelect?groupSelect.value:"");url.searchParams.set("dcs_subgroup",subSelect.value);url.searchParams.delete("dcs_page");url.hash="tpm-dcs-catalog";window.location.assign(url.toString());});const bulk=document.getElementById("tpm-dcs-bulk-button");if(bulk)bulk.addEventListener("click",function(){const chosen=Array.from(document.querySelectorAll(".tpm-dcs-row-check:checked"));if(!chosen.length){alert("Markér mindst én vare.");return;}const target=document.getElementById("tpm-dcs-bulk-form");target.querySelectorAll(".tpm-bulk-added").forEach(function(el){el.remove();});for(const ch of chosen){const row=ch.closest("tr"),form=row.querySelector(".tpm-dcs-actions"),category=form.querySelector("[name=category]").value,price=form.querySelector("[name=sale_price]").value;if(!category||!(Number(price)>0)){alert("Vælg kategori og pris for alle markerede varer.");return;}for(const [key,val] of Object.entries({row_id:ch.value,category:category,sale_price:price})){const input=document.createElement("input");input.type="hidden";input.name="items["+ch.value+"]["+key+"]";input.value=val;input.className="tpm-bulk-added";target.appendChild(input);}}if(confirm("Udgiv "+chosen.length+" markerede varer?"))target.submit();});fill();})();</script>';
 echo '</section>';
}
function tpm_dcs_publish(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');check_admin_referer('tpm_dcs_publish');
 global $wpdb;$table=tpm_dcs_table();$id=absint($_POST['row_id']??0);$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));
 if(!$r||$r->product_id)wp_die('Varen er allerede oprettet eller findes ikke.');
 $price=wc_format_decimal(wp_unslash($_POST['sale_price']??''));if((float)$price<=0)wp_die('Angiv en gyldig salgspris.');
 $cat=absint($_POST['category']??0);$new=sanitize_text_field(wp_unslash($_POST['new_category']??''));
 if($new!==''){$term=term_exists($new,'product_cat');if(!$term)$term=wp_insert_term($new,'product_cat',['parent'=>absint($_POST['parent_category']??0)]);if(is_wp_error($term))wp_die(esc_html($term->get_error_message()));$cat=(int)(is_array($term)?$term['term_id']:$term);}
 if(!$cat||!term_exists($cat,'product_cat'))wp_die('Vælg eller opret en kategori.');
 $sku='DCS-'.$r->supplier_sku;
 if(wc_get_product_id_by_sku($sku))wp_die('Et produkt med dette DCS-varenummer findes allerede.');
 $p=new WC_Product_Simple();$p->set_name($r->title);$p->set_status('publish');$p->set_sku($sku);$p->set_regular_price($price);$p->set_category_ids([$cat]);
 $p->set_manage_stock(false);$p->set_stock_status(($r->supplier_stock+$r->remote_stock)>0?'instock':'outofstock');
 $pid=$p->save();if(!$pid)wp_die('Produktet kunne ikke gemmes.');
 update_post_meta($pid,'_tpm_supplier','DCS');update_post_meta($pid,'_tpm_dcs_sku',$r->supplier_sku);update_post_meta($pid,'_tpm_cost',$r->cost);update_post_meta($pid,'_tpm_dcs_stock',$r->supplier_stock);update_post_meta($pid,'_tpm_remote_stock_qty',$r->remote_stock);update_post_meta($pid,'_tpm_dcs_lead_days',$r->lead_days);update_post_meta($pid,'_tpm_dcs_remote_days',$r->remote_days);
 $wpdb->update($table,['product_id'=>$pid],['id'=>$r->id]);
 wp_safe_redirect(admin_url('admin.php?page=terttus-suppliers&dcs_published=1#tpm-dcs-catalog'));exit;
}
add_action('admin_post_tpm_dcs_publish','tpm_dcs_publish');

/* Full catalog daily sync disabled: published products retain their existing data until selective refresh is implemented. */
add_action('init',function(){wp_clear_scheduled_hook('tpm_dcs_daily_sync');});

function tpm_dcs_bulk_publish(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_bulk_publish');
 global $wpdb;$table=tpm_dcs_table();$items=(array)($_POST['items']??[]);$success=0;
 if(count($items)>30)wp_die('Højst 30 varer ad gangen.');
 foreach($items as $key=>$item){
  if(!is_array($item))continue;
  $id=absint($key);$cat=absint($item['category']??0);$price=wc_format_decimal(wp_unslash($item['sale_price']??''));
  if(!$id||!$cat||!term_exists($cat,'product_cat')||(float)$price<=0)continue;
  $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND product_id=0",$id));
  if(!$r||wc_get_product_id_by_sku('DCS-'.$r->supplier_sku))continue;
  try{
   $p=new WC_Product_Simple();$p->set_name(tpm_dcs_catalog_text($r->title));$p->set_status('publish');$p->set_sku('DCS-'.$r->supplier_sku);$p->set_regular_price($price);$p->set_category_ids([$cat]);$p->set_manage_stock(false);$p->set_stock_status(($r->supplier_stock+$r->remote_stock)>0?'instock':'outofstock');
   $pid=$p->save();if(!$pid)continue;
   foreach(['supplier'=>'DCS','dcs_sku'=>$r->supplier_sku,'cost'=>$r->cost,'dcs_stock'=>$r->supplier_stock,'remote_stock_qty'=>$r->remote_stock,'dcs_lead_days'=>$r->lead_days,'dcs_remote_days'=>$r->remote_days] as $k=>$v)update_post_meta($pid,'_tpm_'.$k,$v);
   $wpdb->update($table,['product_id'=>$pid],['id'=>$id,'product_id'=>0]);$success++;
  }catch(Exception $e){continue;}
 }
 wp_safe_redirect(add_query_arg(['page'=>'terttus-suppliers','dcs_bulk_published'=>$success],admin_url('admin.php')).'#tpm-dcs-catalog');exit;
}
add_action('admin_post_tpm_dcs_bulk_publish','tpm_dcs_bulk_publish');
/* Optional product enrichment: only DCS URLs entered by an administrator.
   Existing images/descriptions/specifications are not overwritten. */
function tpm_dcs_enrich(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_enrich');
 global $wpdb;$table=tpm_dcs_table();$id=absint($_POST['row_id']??0);
 $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND product_id>0",$id));
 if(!$r)wp_die('Produkt ikke fundet.');
 $product=wc_get_product((int)$r->product_id);if(!$product)wp_die('WooCommerce-produkt ikke fundet.');
 $url=esc_url_raw(wp_unslash($_POST['product_url']??''));
 $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
 if(!$url||!preg_match('/(^|\\.)dcs\\.dk$/',$host)||!wp_http_validate_url($url))wp_die('Kun DCS-produktlinks er tilladt.');
 $response=wp_safe_remote_get($url,['timeout'=>25,'redirection'=>2,'limit_response_size'=>1500000,'headers'=>['Accept'=>'text/html']]);
 if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)wp_die('DCS-produktet kunne ikke hentes.');
 $html=wp_remote_retrieve_body($response);
 if(!$html||!class_exists('DOMDocument'))wp_die('Kunne ikke læse produktsiden.');
 $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
 $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);
 $xp=new DOMXPath($dom);$description='';$images=[];$specs=[];
 foreach($xp->query('//script[@type="application/ld+json"]') as $node){
  $json=json_decode($node->textContent,true);if(!is_array($json))continue;
  $queue=isset($json['@graph'])?$json['@graph']:(isset($json[0])?$json:[$json]);
  foreach($queue as $entry){
   if(!is_array($entry))continue;$type=(array)($entry['@type']??[]);
   if(!in_array('Product',$type,true))continue;
   if(!empty($entry['sku'])&&strcasecmp(trim((string)$entry['sku']),trim((string)$r->supplier_sku))!==0)continue;
   if(!empty($entry['description'])&&is_string($entry['description']))$description=wp_kses_post($entry['description']);
   foreach((array)($entry['image']??[]) as $img){if(is_array($img))$img=$img['url']??($img['contentUrl']??'');if(is_string($img))$images[]=$img;}
  }
 }
 foreach($xp->query('//table//tr') as $tr){
  $cells=$xp->query('./th|./td',$tr);
  if($cells->length!==2)continue;
  $name=sanitize_text_field(trim($cells->item(0)->textContent));$value=sanitize_text_field(trim($cells->item(1)->textContent));
  if($name!==''&&$value!==''&&mb_strlen($name)<100&&mb_strlen($value)<500)$specs[]=['name'=>$name,'value'=>$value];
  if(count($specs)>=40)break;
 }
 $changed=0;
 if(!$product->get_description()&&$description!==''){$product->set_description($description);$changed++;}
 if(!$product->get_image_id()&&$images){
  foreach(array_unique($images) as $img){
   if(strpos($img,'//')===0)$img='https:'.$img;elseif(strpos($img,'/')===0)$img='https://'.$host.$img;
   $image_host=strtolower((string)wp_parse_url($img,PHP_URL_HOST));
   if(!preg_match('/(^|\\.)dcs\\.dk$/',$image_host))continue;
   $image_id=tpm_import_image_url($img,$product->get_id());
   if($image_id){$product->set_image_id($image_id);$changed++;break;}
  }
 }
 if(!get_post_meta($product->get_id(),'_tpm_specs',true)&&$specs){update_post_meta($product->get_id(),'_tpm_specs',$specs);$changed++;}
 $product->save();update_post_meta($product->get_id(),'_tpm_supplier_url',$url);
 wp_safe_redirect(add_query_arg(['page'=>'terttus-suppliers','dcs_enriched'=>$changed],admin_url('admin.php')).'#tpm-dcs-catalog');exit;
}
add_action('admin_post_tpm_dcs_enrich','tpm_dcs_enrich');
/* Published-only feed sync: scans the supplier file in bounded cron batches,
   and never creates staging rows or changes storefront selling prices. */
function tpm_dcs_active_sync_start($manual=false){
 if(get_option('tpm_dcs_active_sync_job'))return false;
 $url=get_option('tpm_dcs_csv_url','');
 if(!$url||!tpm_dcs_csv_url_valid($url))return false;
 $path=wp_tempnam('tpm-dcs-active.csv');if(!$path)return false;
 $response=wp_safe_remote_get($url,['timeout'=>120,'redirection'=>2,'stream'=>true,'filename'=>$path,'headers'=>['Accept'=>'text/csv,text/plain,*/*']]);
 if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200){@unlink($path);update_option('tpm_dcs_active_sync_error','Kunne ikke hente DCS-prisfil.',false);return false;}
 $h=@fopen($path,'rb');$headers=$h?fgetcsv($h,0,';','"','\\'):false;if($h)fclose($h);
 if(!$headers||!in_array('Varenummer',$headers,true)||!in_array('Varenavn',$headers,true)){@unlink($path);update_option('tpm_dcs_active_sync_error','DCS returnerede ikke en gyldig CSV.',false);return false;}
 update_option('tpm_dcs_active_sync_job',['path'=>$path,'offset'=>0,'scanned'=>0,'updated'=>0,'started'=>time()],false);
 delete_option('tpm_dcs_active_sync_error');
 wp_schedule_single_event(time()+5,'tpm_dcs_active_sync_batch');return true;
}
add_action('admin_post_tpm_dcs_active_sync_start',function(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_active_sync_start');
 tpm_dcs_active_sync_start(true);
 wp_safe_redirect(admin_url('admin.php?page=terttus-suppliers#tpm-dcs-catalog'));exit;
});
function tpm_dcs_active_sync_batch(){
 global $wpdb;$job=get_option('tpm_dcs_active_sync_job');if(!is_array($job))return;
 $path=$job['path']??'';if(!$path||!is_file($path)){delete_option('tpm_dcs_active_sync_job');update_option('tpm_dcs_active_sync_error','Midlertidig synkroniseringsfil mangler.',false);return;}
 $h=@fopen($path,'rb');if(!$h)return;
 $headers=fgetcsv($h,0,';','"','\\');if(!$headers){fclose($h);return;}
 $map=[];foreach($headers as $i=>$name)$map[mb_strtolower(trim((string)$name," \t\r\n\xEF\xBB\xBF"),'UTF-8')]=$i;
 if($job['offset']>0)fseek($h,(int)$job['offset']);
 $table=tpm_dcs_table();$processed=0;
 while($processed<2500&&($row=fgetcsv($h,0,';','"','\\'))!==false){
  $processed++;$sku=tpm_dcs_csv_col($row,$map,['Varenummer']);if($sku==='')continue;
  $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT product_id FROM $table WHERE supplier_sku=%s AND product_id>0",$sku));
  if(!$pid)continue;
  $product=wc_get_product($pid);if(!$product||$product->get_status()!=='publish')continue;
  $cost=(float)str_replace(',','.',tpm_dcs_csv_col($row,$map,['Pris']));
  $stock=max(0,(int)tpm_dcs_csv_col($row,$map,['Beholdning']));
  $remote=max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal på fjernlager','Antal på fjernlager']));
  $days=max(0,(int)tpm_dcs_csv_col($row,$map,['Leveringstid']));
  $rdays=max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal dage fra fjernlager','Antal dage fra fjernlager']));
  update_post_meta($pid,'_tpm_cost',$cost);update_post_meta($pid,'_tpm_dcs_stock',$stock);
  update_post_meta($pid,'_tpm_remote_stock_qty',$remote);update_post_meta($pid,'_tpm_dcs_lead_days',$days);update_post_meta($pid,'_tpm_dcs_remote_days',$rdays);
  if(!$product->managing_stock()){$product->set_stock_status(($stock+$remote)>0?'instock':'outofstock');$product->save();}
  $wpdb->update($table,['cost'=>$cost,'supplier_stock'=>$stock,'remote_stock'=>$remote,'lead_days'=>$days,'remote_days'=>$rdays,'updated_at'=>current_time('mysql')],['supplier_sku'=>$sku]);
  $job['updated']++;
 }
 $done=feof($h);$job['offset']=ftell($h);$job['scanned']+=$processed;fclose($h);
 if($done){@unlink($path);delete_option('tpm_dcs_active_sync_job');update_option('tpm_dcs_active_sync_last',['at'=>current_time('mysql'),'updated'=>$job['updated'],'scanned'=>$job['scanned']],false);}
 else{update_option('tpm_dcs_active_sync_job',$job,false);wp_schedule_single_event(time()+10,'tpm_dcs_active_sync_batch');}
}
add_action('tpm_dcs_active_sync_batch','tpm_dcs_active_sync_batch');
add_action('init',function(){
 if(!wp_next_scheduled('tpm_dcs_active_sync_daily'))wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','tpm_dcs_active_sync_daily');
});
add_action('tpm_dcs_active_sync_daily',function(){tpm_dcs_active_sync_start();});
