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
function tpm_dcs_csv_col($row,$map,$names,$default=''){
 foreach($names as $name){$key=mb_strtolower($name,'UTF-8');if(isset($map[$key]))return trim((string)($row[$map[$key]]??$default));}return $default;
}
function tpm_dcs_start_import(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 check_admin_referer('tpm_dcs_start_import');
 $url=get_option('tpm_dcs_csv_url','');
 if(!$url||!tpm_dcs_csv_url_valid($url))wp_die('Gem først et gyldigt DCS-link under Leverandører.');
 if(get_option('tpm_dcs_import_job'))wp_die('En import er allerede i gang.');
 $path=wp_tempnam('tpm-dcs-catalog.csv');
 if(!$path)wp_die('Kunne ikke oprette midlertidig fil.');
 $r=wp_safe_remote_get($url,['timeout'=>120,'redirection'=>2,'stream'=>true,'filename'=>$path,'headers'=>['Accept'=>'text/csv,text/plain,*/*']]);
 if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200){@unlink($path);wp_die('Kunne ikke hente DCS CSV. Kontrollér URL og adgang.');}
 $h=fopen($path,'rb');$first=$h?fgets($h):'';if($h)fclose($h);
 if(!$first||count(str_getcsv($first,';'))<5||stripos($first,'<html')!==false){@unlink($path);wp_die('DCS returnerede ikke en gyldig CSV.');}
 update_option('tpm_dcs_import_job',['path'=>$path,'offset'=>0,'count'=>0,'started'=>time()],false);
 wp_schedule_single_event(time()+5,'tpm_dcs_import_batch');
 wp_safe_redirect(admin_url('admin.php?page=terttus-suppliers#tpm-dcs-catalog'));exit;
}
add_action('admin_post_tpm_dcs_start_import','tpm_dcs_start_import');
function tpm_dcs_import_batch(){
 global $wpdb;$job=get_option('tpm_dcs_import_job');if(!is_array($job))return;
 $path=$job['path']??'';if(!$path||!is_file($path)){delete_option('tpm_dcs_import_job');update_option('tpm_dcs_import_error','Importfilen mangler.',false);return;}
 $h=fopen($path,'rb');if(!$h)return;
 $headers=fgetcsv($h,0,';','"','\\');if(!$headers){fclose($h);return;}
 $map=[];foreach($headers as $i=>$v)$map[mb_strtolower(trim((string)$v," \t\r\n\xEF\xBB\xBF"),'UTF-8')]=$i;
 if(!isset($map['varenummer'])||!isset($map['varenavn'])){fclose($h);delete_option('tpm_dcs_import_job');update_option('tpm_dcs_import_error','CSV mangler Varenummer eller Varenavn.',false);@unlink($path);return;}
 if($job['offset']>0)fseek($h,(int)$job['offset']);
 $count=0;$table=tpm_dcs_table();
 while($count<300&&($row=fgetcsv($h,0,';','"','\\'))!==false){
  $sku=tpm_dcs_csv_col($row,$map,['Varenummer']);$title=tpm_dcs_csv_col($row,$map,['Varenavn']);
  if($sku===''||$title==='')continue;
  $cost=(float)str_replace(',','.',tpm_dcs_csv_col($row,$map,['Pris']));
  $stock=max(0,(int)tpm_dcs_csv_col($row,$map,['Beholdning']));
  $remote=max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal på fjernlager','Antal på fjernlager']));
  $days=max(0,(int)tpm_dcs_csv_col($row,$map,['Leveringstid']));
  $rdays=max(0,(int)tpm_dcs_csv_col($row,$map,['field_name.Antal dage fra fjernlager','Antal dage fra fjernlager']));
  $values=['supplier_sku'=>$sku,'title'=>$title,'brand'=>tpm_dcs_csv_col($row,$map,['Producentnavn']),'model'=>tpm_dcs_csv_col($row,$map,['Modelbetegnelse']),'group_name'=>tpm_dcs_csv_col($row,$map,['Varegruppenavn']),'subgroup'=>tpm_dcs_csv_col($row,$map,['Undergruppenavn']),'cost'=>$cost,'supplier_stock'=>$stock,'remote_stock'=>$remote,'lead_days'=>$days,'remote_days'=>$rdays,'updated_at'=>current_time('mysql')];
  $existing=$wpdb->get_row($wpdb->prepare("SELECT id,product_id FROM $table WHERE supplier_sku=%s",$sku));
  if($existing){
   $wpdb->update($table,$values,['id'=>$existing->id]);
   if($existing->product_id){
    $pid=(int)$existing->product_id;
    update_post_meta($pid,'_tpm_cost',$cost);
    update_post_meta($pid,'_tpm_remote_stock_qty',$remote);
    update_post_meta($pid,'_tpm_dcs_stock',$stock);
    update_post_meta($pid,'_tpm_dcs_lead_days',$days);
    update_post_meta($pid,'_tpm_dcs_remote_days',$rdays);
    $product=wc_get_product($pid);if($product&&!$product->managing_stock()){$product->set_stock_status(($stock+$remote)>0?'instock':'outofstock');$product->save();}
   }
  }else $wpdb->insert($table,$values);
  $count++;
 }
 $done=feof($h);$job['offset']=ftell($h);$job['count']+=$count;fclose($h);
 if($done){@unlink($path);delete_option('tpm_dcs_import_job');update_option('tpm_dcs_last_import',['at'=>current_time('mysql'),'count'=>$job['count']],false);delete_option('tpm_dcs_import_error');}
 else{update_option('tpm_dcs_import_job',$job,false);wp_schedule_single_event(time()+10,'tpm_dcs_import_batch');}
}
add_action('tpm_dcs_import_batch','tpm_dcs_import_batch');
function tpm_dcs_catalog_page(){
 if(!current_user_can('manage_woocommerce'))wp_die('Ingen adgang.');
 global $wpdb;$table=tpm_dcs_table();$search=sanitize_text_field(wp_unslash($_GET['dcs_search']??''));$page=max(1,absint($_GET['dcs_page']??1));$per=30;
 $where='1=1';$params=[];if($search!==''){$where='(title LIKE %s OR supplier_sku LIKE %s OR brand LIKE %s OR model LIKE %s)';$like='%'.$wpdb->esc_like($search).'%';$params=[$like,$like,$like,$like];}
 $count_sql="SELECT COUNT(*) FROM $table WHERE $where";$total=(int)$wpdb->get_var($params?$wpdb->prepare($count_sql,...$params):$count_sql);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY product_id ASC, id DESC LIMIT %d OFFSET %d",...array_merge($params,[$per,($page-1)*$per])));
 $job=get_option('tpm_dcs_import_job');$last=get_option('tpm_dcs_last_import');$error=get_option('tpm_dcs_import_error');
 echo '<section id="tpm-dcs-catalog" class="tpm-card" style="margin:20px 0;padding:20px"><h2>DCS produktkatalog</h2><p>Alle varer er interne, indtil du udgiver dem. Prisforslag beregnes med moms (25 %) og valgt avance på kostprisen ekskl. moms.</p>';
 echo '<p><strong>'.number_format_i18n($total).' varer</strong> · '.($job?'Import kører: '.(int)$job['count'].' behandlet.':($last?'Seneste import: '.esc_html($last['at']):'Ingen fuldført import.')).'</p>';
 if($error)echo '<p style="color:#b42318">'.esc_html($error).'</p>';
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('tpm_dcs_start_import','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_start_import"><button class="button button-primary" '.($job?'disabled':'').'>'.($job?'Import i gang':'Hent/opdatér alle DCS-varer').'</button></form>';
 echo '<form method="get" style="display:flex;gap:8px;margin:14px 0"><input type="hidden" name="page" value="terttus-suppliers"><input name="dcs_search" value="'.esc_attr($search).'" placeholder="Søg navn, varenummer eller mærke" style="width:330px;max-width:65%"><button class="button">Søg</button></form>';
 echo '<p style="display:flex;align-items:center;gap:8px"><label for="tpm-dcs-margin">Prisforslag, avance:</label><select id="tpm-dcs-margin"><option value="15">15 %</option><option value="20" selected>20 %</option><option value="25">25 %</option><option value="30">30 %</option><option value="40">40 %</option></select><span class="description">Du kan altid rette prisen manuelt.</span></p>';
 echo '<style>#tpm-dcs-catalog .tpm-dcs-table{table-layout:fixed;width:100%}#tpm-dcs-catalog .tpm-dcs-table td,#tpm-dcs-catalog .tpm-dcs-table th{padding:9px 8px;vertical-align:middle}#tpm-dcs-catalog .tpm-dcs-table td:first-child{overflow-wrap:anywhere}#tpm-dcs-catalog .tpm-dcs-actions{display:flex;flex-wrap:wrap;gap:5px;align-items:center}#tpm-dcs-catalog .tpm-dcs-actions select{width:160px;max-width:100%;min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions input{width:115px;min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions button{min-height:32px}#tpm-dcs-catalog .tpm-dcs-actions .tpm-dcs-new{width:150px}@media(max-width:1100px){#tpm-dcs-catalog .tpm-dcs-table{min-width:900px}}</style>';
 echo '<div style="overflow-x:auto"><table class="widefat striped tpm-dcs-table"><colgroup><col style="width:30%"><col style="width:11%"><col style="width:9%"><col style="width:12%"><col style="width:9%"><col style="width:29%"></colgroup><thead><tr><th>Produkt</th><th>Indkøb</th><th>DCS lager</th><th>Fjernlager</th><th>Status</th><th>Kategori og pris</th></tr></thead><tbody>';
 $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);if(is_wp_error($cats))$cats=[];
 foreach($rows as $r){
  echo '<tr><td><strong>'.esc_html($r->title).'</strong><br><small>'.esc_html($r->brand.' · '.$r->supplier_sku.' · '.$r->model).'</small></td><td>'.esc_html(number_format_i18n((float)$r->cost,2)).' kr.</td><td>'.(int)$r->supplier_stock.'</td><td>'.(int)$r->remote_stock.' · '.(int)$r->remote_days.' dage</td>';
  echo '<td>'.($r->product_id?'<a href="'.esc_url(get_edit_post_link((int)$r->product_id)).'">Udgivet</a>':'Kladde').'</td><td>';
  if(!$r->product_id){
   echo '<form class="tpm-dcs-actions" method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-cost="'.esc_attr($r->cost).'">'.wp_nonce_field('tpm_dcs_publish','_wpnonce',true,false).'<input type="hidden" name="action" value="tpm_dcs_publish"><input type="hidden" name="row_id" value="'.(int)$r->id.'">';
   echo '<select name="category"><option value="">Vælg kategori</option>';foreach($cats as $cat)echo '<option value="'.(int)$cat->term_id.'">'.esc_html(str_repeat('— ',count(get_ancestors($cat->term_id,'product_cat'))).$cat->name).'</option>';echo '</select>';
   echo '<input class="tpm-dcs-new" name="new_category" placeholder="Ny kategori" maxlength="100" title="Opret ny kategori"><select name="parent_category" title="Overkategori"><option value="0">Ingen overkategori</option>';foreach($cats as $cat)echo '<option value="'.(int)$cat->term_id.'">'.esc_html($cat->name).'</option>';echo '</select>';
   echo '<input class="tpm-dcs-price" type="number" name="sale_price" min="0.01" step="0.01" required placeholder="Pris inkl. moms" title="Salgspris inkl. moms"> <button class="button button-primary">Udgiv</button></form>';
  }echo '</td></tr>';
 }
 echo '</tbody></table></div>';
 $pages=(int)ceil($total/$per);if($pages>1){echo '<p>';for($i=max(1,$page-3);$i<=min($pages,$page+3);$i++)echo '<a class="button '.($i===$page?'button-primary':'').'" href="'.esc_url(add_query_arg(['page'=>'terttus-suppliers','dcs_page'=>$i,'dcs_search'=>$search],admin_url('admin.php'))).'">'.$i.'</a> ';echo '</p>';}
 echo '<script>(function(){const select=document.getElementById("tpm-dcs-margin");if(!select)return;function fill(){const pct=Number(select.value)/100;document.querySelectorAll("#tpm-dcs-catalog .tpm-dcs-actions").forEach(function(form){const input=form.querySelector(".tpm-dcs-price");if(!input||input.dataset.manual==="1")return;const cost=Number(form.dataset.cost);if(cost>0)input.value=(Math.ceil(cost*(1+pct)*1.25)-0.05).toFixed(2);});}document.querySelectorAll("#tpm-dcs-catalog .tpm-dcs-price").forEach(function(input){input.addEventListener("input",function(){input.dataset.manual="1";});});select.addEventListener("change",fill);fill();})();</script>';
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

/* Daily refresh only if a previous import has completed. */
add_action('init',function(){if(!wp_next_scheduled('tpm_dcs_daily_sync'))wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','tpm_dcs_daily_sync');});
add_action('tpm_dcs_daily_sync',function(){
 if(!get_option('tpm_dcs_last_import')||get_option('tpm_dcs_import_job'))return;
 $url=get_option('tpm_dcs_csv_url','');if(!$url||!tpm_dcs_csv_url_valid($url))return;
 $path=wp_tempnam('tpm-dcs-daily.csv');if(!$path)return;
 $r=wp_safe_remote_get($url,['timeout'=>120,'redirection'=>2,'stream'=>true,'filename'=>$path]);
 if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200){@unlink($path);update_option('tpm_dcs_import_error','Automatisk hentning fejlede.',false);return;}
 $h=fopen($path,'rb');$header=$h?fgetcsv($h,0,';','"','\\'):false;if($h)fclose($h);
 if(!$header||!in_array('Varenummer',$header,true)||!in_array('Varenavn',$header,true)){@unlink($path);update_option('tpm_dcs_import_error','Automatisk CSV-kontrol fejlede.',false);return;}
 update_option('tpm_dcs_import_job',['path'=>$path,'offset'=>0,'count'=>0,'started'=>time()],false);
 wp_schedule_single_event(time()+5,'tpm_dcs_import_batch');
});
