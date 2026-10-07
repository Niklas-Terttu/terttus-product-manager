<?php
/*
Plugin Name: Terttus Product Manager
Description: Moderne produktstyring oven på WooCommerce.
Version: 1.7.8
Author: Terttus
Requires PHP: 7.4
*/
if(!defined('ABSPATH'))exit;
define('TPM_VERSION','1.7.8');
define('TPM_GITHUB_REPO','Niklas-Terttu/terttus-product-manager');
define('TPM_PLUGIN_BASENAME',plugin_basename(__FILE__));

function tpm_github_token(){
 if(defined('TERTTUS_GITHUB_TOKEN')&&TERTTUS_GITHUB_TOKEN)return trim((string)TERTTUS_GITHUB_TOKEN);
 return '';
}
function tpm_github_request($endpoint){
 $headers=['Accept'=>'application/vnd.github+json','User-Agent'=>'Terttus-Commerce/'.TPM_VERSION,'X-GitHub-Api-Version'=>'2022-11-28'];
 $token=tpm_github_token();if($token)$headers['Authorization']='Bearer '.$token;
 return wp_remote_get('https://api.github.com/repos/'.TPM_GITHUB_REPO.$endpoint,['timeout'=>15,'headers'=>$headers]);
}
function tpm_remote_version($force=false){
 $key='tpm_github_update_meta';if(!$force){$cached=get_site_transient($key);if(is_array($cached))return $cached;}
 $r=tpm_github_request('/contents/terttus-product-manager.php?ref=main');
 if(is_wp_error($r)||200!==wp_remote_retrieve_response_code($r))return null;
 $j=json_decode(wp_remote_retrieve_body($r),true);if(empty($j['content']))return null;
 $src=base64_decode(str_replace(["\r","\n"],'',$j['content']),true);if(!$src)return null;
 if(!preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi',$src,$m))return null;
 $data=['version'=>trim($m[1]),'details'=>'https://github.com/'.TPM_GITHUB_REPO,'package'=>'https://api.github.com/repos/'.TPM_GITHUB_REPO.'/zipball/main'];
 set_site_transient($key,$data,15*MINUTE_IN_SECONDS);return $data;
}
function tpm_update_plugins($update,$plugin_data,$plugin_file){
 if($plugin_file!==TPM_PLUGIN_BASENAME)return $update;$d=tpm_remote_version();
 if(!$d||empty($d['version'])||version_compare(TPM_VERSION,$d['version'],'>='))return false;
 return ['id'=>'github.com/'.TPM_GITHUB_REPO,'slug'=>dirname(TPM_PLUGIN_BASENAME),'version'=>$d['version'],'url'=>$d['details'],'package'=>$d['package']];
}
add_filter('update_plugins_'.plugin_basename(__FILE__),'tpm_update_plugins',10,3);
function tpm_update_transient($transient){
 if(!is_object($transient))$transient=new stdClass();$d=tpm_remote_version();
 if($d&&!empty($d['version'])&&version_compare(TPM_VERSION,$d['version'],'<')){
  $o=new stdClass();$o->slug=dirname(TPM_PLUGIN_BASENAME);$o->plugin=TPM_PLUGIN_BASENAME;$o->new_version=$d['version'];$o->url=$d['details'];$o->package=$d['package'];
  $transient->response[TPM_PLUGIN_BASENAME]=$o;
 }
 return $transient;
}
add_filter('site_transient_update_plugins','tpm_update_transient');
function tpm_upgrader_package_options($options){
 if(empty($options['hook_extra']['plugin'])||$options['hook_extra']['plugin']!==TPM_PLUGIN_BASENAME)return $options;
 $token=tpm_github_token();if($token)add_filter('http_request_args','tpm_github_download_auth',10,2);return $options;
}
add_filter('upgrader_package_options','tpm_upgrader_package_options');
function tpm_github_download_auth($args,$url){
 if(strpos($url,'api.github.com/repos/'.TPM_GITHUB_REPO.'/zipball/')===false)return $args;
 $token=tpm_github_token();$args['headers']['Accept']='application/vnd.github+json';$args['headers']['User-Agent']='Terttus-Commerce/'.TPM_VERSION;if($token)$args['headers']['Authorization']='Bearer '.$token;return $args;
}
function tpm_upgrader_source($source,$remote_source,$upgrader,$hook_extra){
 if(empty($hook_extra['plugin'])||$hook_extra['plugin']!==TPM_PLUGIN_BASENAME)return $source;
 global $wp_filesystem;$target=trailingslashit($remote_source).dirname(TPM_PLUGIN_BASENAME);
 if(untrailingslashit($source)===untrailingslashit($target))return $source;
 if($wp_filesystem->exists($target))$wp_filesystem->delete($target,true);
 if(!$wp_filesystem->move($source,$target,true))return new WP_Error('tpm_rename_failed','Kunne ikke klargøre Terttus Commerce-opdateringen.');
 return trailingslashit($target);
}
add_filter('upgrader_source_selection','tpm_upgrader_source',10,4);
function tpm_force_update_check(){
 if(!current_user_can('update_plugins'))wp_die('Ingen adgang.');check_admin_referer('tpm_force_update');
 delete_site_transient('tpm_github_update_meta');delete_site_transient('update_plugins');wp_update_plugins();
 wp_safe_redirect(admin_url('plugins.php?tpm_checked=1'));exit;
}
add_action('admin_post_tpm_force_update_check','tpm_force_update_check');
function tpm_plugin_action_links($links){
 $url=wp_nonce_url(admin_url('admin-post.php?action=tpm_force_update_check'),'tpm_force_update');
 $links[]='<a href="'.esc_url($url).'">Søg efter opdatering</a>';return $links;
}
add_filter('plugin_action_links_'.TPM_PLUGIN_BASENAME,'tpm_plugin_action_links');
function tpm_menu(){
 add_menu_page('Terttus Commerce','Terttus Commerce','manage_woocommerce','terttus-commerce','tpm_dashboard','dashicons-store',56);
 add_submenu_page('terttus-commerce','Dashboard','Dashboard','manage_woocommerce','terttus-commerce','tpm_dashboard');
 add_submenu_page('terttus-commerce','Produkter','Produkter','edit_products','terttus-products','tpm_products');
 add_submenu_page('terttus-commerce','Nyt produkt','Nyt produkt','edit_products','terttus-product-manager','tpm_screen');
 add_submenu_page('terttus-commerce','Kategorier','Kategorier','manage_product_terms','terttus-categories','tpm_categories');
 add_submenu_page('terttus-commerce','Ordrer','Ordrer','manage_woocommerce','terttus-orders','tpm_orders');
 add_submenu_page('terttus-commerce','Lager','Lager','manage_woocommerce','terttus-stock','tpm_stock');
 add_submenu_page('terttus-commerce','Leverandører','Leverandører','manage_woocommerce','terttus-suppliers','tpm_suppliers');
}add_action('admin_menu','tpm_menu',30);
function tpm_assets($h){if(strpos($h,'terttus')===false)return;wp_enqueue_media();wp_enqueue_style('tpm',plugins_url('assets/admin.css',__FILE__),[],TPM_VERSION);wp_enqueue_script('jquery-ui-sortable');wp_enqueue_script('tpm',plugins_url('assets/admin.js',__FILE__),['jquery','jquery-ui-sortable'],TPM_VERSION,true);wp_localize_script('tpm','TPM',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('tpm_dcs_import'),'catNonce'=>wp_create_nonce('tpm_categories')]);}add_action('admin_enqueue_scripts','tpm_assets');
function tpm_val($p,$k,$d=''){if(!$p)return $d;if(in_array($k,['ean','brand','cost','supplier','supplier_sku','supplier_url'],true))return get_post_meta($p->get_id(),'_tpm_'.$k,true);$m=['name'=>'get_name','sku'=>'get_sku','price'=>'get_regular_price','sale'=>'get_sale_price','sale_from'=>'get_date_on_sale_from','sale_to'=>'get_date_on_sale_to','stock'=>'get_stock_quantity','stock_status'=>'get_stock_status','short_description'=>'get_short_description','description'=>'get_description','image'=>'get_image_id'];return isset($m[$k])?$p->{$m[$k]}():$d;}

function tpm_money($v){return wc_price((float)$v,['decimals'=>2]);}
function tpm_admin_head($title,$subtitle=''){echo '<div class="wrap tpm"><div class="tpm-head"><div><h1>'.esc_html($title).'</h1><p>'.esc_html($subtitle).'</p></div><div class="tpm-head-actions"><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=terttus-product-manager')).'">+ Nyt produkt</a><span class="tpm-badge">Terttus Commerce</span></div></div>';tpm_nav();}
function tpm_nav(){$items=['terttus-commerce'=>'Dashboard','terttus-products'=>'Produkter','terttus-categories'=>'Kategorier','terttus-orders'=>'Ordrer','terttus-stock'=>'Lager','terttus-suppliers'=>'Leverandører'];$cur=sanitize_key($_GET['page']??'');echo '<nav class="tpm-nav">';foreach($items as$slug=>$label)echo '<a class="'.($cur===$slug?'active':'').'" href="'.esc_url(admin_url('admin.php?page='.$slug)).'">'.esc_html($label).'</a>';echo '</nav>';}
function tpm_dashboard(){
 if(!class_exists('WooCommerce'))return;
 tpm_admin_head('Dashboard','Dit daglige overblik over webshoppen.');
 $today=(new DateTime('today',wp_timezone()))->format('Y-m-d H:i:s');
 $orders=wc_get_orders(['limit'=>-1,'date_created'=>'>='.$today,'status'=>array_keys(wc_get_order_statuses())]);
 $revenue=0;$profit=0;foreach($orders as$o){$revenue+=(float)$o->get_total();foreach($o->get_items() as$i){$p=$i->get_product();if($p){$cost=(float)get_post_meta($p->get_id(),'_tpm_cost',true);$profit+=((float)$i->get_total()/1.25)-($cost*$i->get_quantity());}}}
 $processing=wc_orders_count('processing');$onhold=wc_orders_count('on-hold');
 $low=wc_get_products(['limit'=>-1,'stock_status'=>'instock','manage_stock'=>true,'return'=>'objects']);$low=array_filter($low,fn($p)=>$p->get_stock_quantity()!==null&&$p->get_stock_quantity()<=3);
 echo '<div class="tpm-kpis"><div class="tpm-kpi"><span>Omsætning i dag</span><strong>'.wp_kses_post(tpm_money($revenue)).'</strong></div><div class="tpm-kpi"><span>Ordrer i dag</span><strong>'.count($orders).'</strong></div><div class="tpm-kpi"><span>Est. bruttoavance</span><strong>'.wp_kses_post(tpm_money($profit)).'</strong></div><div class="tpm-kpi"><span>Kræver handling</span><strong>'.($processing+$onhold+count($low)).'</strong></div></div>';
 echo '<div class="tpm-two"><section class="tpm-card"><h2>Kræver handling</h2><div class="tpm-action-row"><span>Ordrer under behandling</span><b>'.$processing.'</b></div><div class="tpm-action-row"><span>Ordrer på hold</span><b>'.$onhold.'</b></div><div class="tpm-action-row"><span>Varer med lav lagerbeholdning</span><b>'.count($low).'</b></div></section><section class="tpm-card"><h2>Hurtige handlinger</h2><div class="tpm-quick"><a href="'.esc_url(admin_url('admin.php?page=terttus-product-manager')).'">+ Opret vare</a><a href="'.esc_url(admin_url('admin.php?page=terttus-orders')).'">Se ordrer</a><a href="'.esc_url(admin_url('admin.php?page=terttus-stock')).'">Tjek lager</a><a href="'.esc_url(admin_url('admin.php?page=terttus-suppliers')).'">Leverandører</a></div></section></div></div>';
}

function tpm_category_branch($parent=0,$level=0){
 $uncategorized=(int)get_option('default_product_cat',0);
 $args=['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$parent,'orderby'=>'menu_order','order'=>'ASC'];
 if($parent===0&&$uncategorized)$args['exclude']=[$uncategorized];
 $terms=get_terms($args);if(is_wp_error($terms))$terms=[];
 echo '<div class="tpm-cat-level" data-parent="'.intval($parent).'">';
 foreach($terms as$t){
  $thumb=(int)get_term_meta($t->term_id,'thumbnail_id',true);$img=$thumb?wp_get_attachment_image_url($thumb,'thumbnail'):'';
  $mega=get_term_meta($t->term_id,'_tpm_show_mega',true)!=='no';
  $children=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$t->term_id,'fields'=>'ids']);
  $has_children=!is_wp_error($children)&&!empty($children);
  echo '<div class="tpm-cat-node" data-id="'.intval($t->term_id).'" data-name="'.esc_attr($t->name).'">';
  echo '<div class="tpm-cat-row"><button type="button" class="tpm-cat-collapse'.($has_children?'':' is-empty').'" aria-label="Fold kategori '.esc_attr($t->name).' '.($has_children?'sammen':'').'">'.($has_children?'▾':'·').'</button><span class="tpm-cat-handle" title="Træk for at flytte">⋮⋮</span><span class="tpm-cat-thumb">'.($img?'<img src="'.esc_url($img).'" alt="">':'◫').'</span>';
  echo '<div class="tpm-cat-name"><strong>'.esc_html($t->name).'</strong><small>/'.esc_html($t->slug).' · '.intval($t->count).' produkter</small></div>';
  echo '<span class="tpm-cat-mega '.($mega?'is-on':'is-off').'">'.($mega?'Mega-menu ✓':'Skjult i menu').'</span>';
  echo '<div class="tpm-cat-actions"><button type="button" class="button tpm-cat-edit" data-id="'.intval($t->term_id).'">Redigér</button><button type="button" class="button tpm-cat-add-child" data-id="'.intval($t->term_id).'" data-name="'.esc_attr($t->name).'">+ Underkategori</button></div></div>';
  tpm_category_branch($t->term_id,$level+1);echo '</div>';
 }
 echo '</div>';
}
function tpm_categories(){
 if(!class_exists('WooCommerce'))return;
 tpm_admin_head('Kategorier','Byg webshoppen visuelt med hovedkategorier og underkategorier.');
 echo '<div class="tpm-cat-tools"><div class="tpm-cat-search"><span>⌕</span><input type="search" id="tpm-cat-search" placeholder="Søg i kategorier…"></div><button type="button" class="button tpm-cat-expand-all">Fold alle ud</button><button type="button" class="button tpm-cat-collapse-all">Fold alle sammen</button><span class="tpm-cat-save-state" aria-live="polite"></span></div>';
 echo '<div class="tpm-cat-layout"><section class="tpm-card"><div class="tpm-cat-toolbar"><div><h2>Kategoristruktur</h2><p>Træk en kategori op, ned eller ind under en anden kategori. Ændringer gemmes automatisk.</p></div><button type="button" class="button button-primary tpm-cat-new">+ Ny hovedkategori</button></div><div id="tpm-category-tree">';
 tpm_category_branch();echo '</div>';
 echo '<div class="tpm-mega-preview"><div class="tpm-mega-preview-head"><div><span class="tpm-eyebrow">Preview</span><h2>Mega-menu</h2></div><small>Kun kategorier markeret til mega-menu</small></div><div class="tpm-mega-preview-grid">';
 $uncategorized=(int)get_option('default_product_cat',0);$tops=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>0,'exclude'=>$uncategorized?[$uncategorized]:[],'orderby'=>'menu_order','order'=>'ASC']);
 if(!is_wp_error($tops))foreach($tops as$top){if(get_term_meta($top->term_id,'_tpm_show_mega',true)==='no')continue;echo '<div class="tpm-mega-preview-col"><strong>'.esc_html($top->name).'</strong>';$kids=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$top->term_id,'orderby'=>'menu_order','order'=>'ASC']);if(!is_wp_error($kids))foreach($kids as$kid){if(get_term_meta($kid->term_id,'_tpm_show_mega',true)!=='no')echo '<span>'.esc_html($kid->name).'</span>';}echo '</div>';}
 echo '</div></div></section>';
 echo '<aside class="tpm-card tpm-cat-editor"><h2 id="tpm-cat-editor-title">Ny kategori</h2><form id="tpm-cat-form"><input type="hidden" name="term_id" value="0"><input type="hidden" name="thumbnail_id" value="0" id="tpm-cat-thumb-id">';
 echo '<label>Navn<input name="name" required placeholder="Fx Netværk"></label><label>Slug<input name="slug" placeholder="Oprettes automatisk"></label><label>Forældrekategori<select name="parent"><option value="0">— Hovedkategori —</option>';
 $all=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'orderby'=>'name']);if(!is_wp_error($all))foreach($all as$t){if($uncategorized&&$t->term_id===$uncategorized)continue;echo '<option value="'.intval($t->term_id).'">'.esc_html($t->name).'</option>';}
 echo '</select></label><label>Kategoribillede</label><div class="tpm-cat-image"><div id="tpm-cat-image-preview"><span>Intet billede</span></div><div><button type="button" class="button tpm-cat-pick-image">Vælg billede</button><button type="button" class="button-link-delete tpm-cat-remove-image">Fjern</button></div></div>';
 echo '<label>Beskrivelse<textarea name="description" rows="4"></textarea></label><label class="tpm-checkline"><input type="checkbox" name="mega" value="1" checked> Vis i mega-menu</label><div class="tpm-cat-editor-actions"><button type="submit" class="button button-primary">Gem kategori</button><button type="button" class="button tpm-cat-reset">Nulstil</button></div><div class="tpm-cat-danger" hidden><hr><button type="button" class="button-link-delete tpm-cat-delete">Slet kategori</button><small>Produkter slettes ikke. De mister blot denne kategori.</small></div><p class="tpm-cat-message"></p></form></aside></div></div>';
}
function tpm_cat_save(){
 check_ajax_referer('tpm_categories','nonce');if(!current_user_can('manage_product_terms'))wp_send_json_error(['message'=>'Ingen adgang.'],403);
 $id=absint($_POST['term_id']??0);$name=sanitize_text_field(wp_unslash($_POST['name']??''));if(!$name)wp_send_json_error(['message'=>'Navn mangler.']);
 $parent=absint($_POST['parent']??0);if($id&&$parent===$id)wp_send_json_error(['message'=>'En kategori kan ikke være sin egen forælder.']);
 $args=['slug'=>sanitize_title(wp_unslash($_POST['slug']??'')),'parent'=>$parent,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??''))];if(!$args['slug'])unset($args['slug']);
 $r=$id?wp_update_term($id,'product_cat',$args+['name'=>$name]):wp_insert_term($name,'product_cat',$args);if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()]);
 $tid=$id?:intval($r['term_id']);update_term_meta($tid,'_tpm_show_mega',!empty($_POST['mega'])?'yes':'no');update_term_meta($tid,'thumbnail_id',absint($_POST['thumbnail_id']??0));
 wp_send_json_success(['message'=>'Kategorien er gemt.','id'=>$tid]);
}
add_action('wp_ajax_tpm_cat_save','tpm_cat_save');
function tpm_cat_get(){
 check_ajax_referer('tpm_categories','nonce');if(!current_user_can('manage_product_terms'))wp_send_json_error([],403);$id=absint($_POST['id']??0);$t=get_term($id,'product_cat');if(!$t||is_wp_error($t))wp_send_json_error(['message'=>'Kategorien blev ikke fundet.']);
 $thumb=(int)get_term_meta($id,'thumbnail_id',true);wp_send_json_success(['id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'parent'=>$t->parent,'description'=>$t->description,'mega'=>get_term_meta($t->term_id,'_tpm_show_mega',true)!=='no','thumbnail_id'=>$thumb,'thumbnail_url'=>$thumb?wp_get_attachment_image_url($thumb,'thumbnail'):'']);
}
add_action('wp_ajax_tpm_cat_get','tpm_cat_get');
function tpm_cat_structure(){
 check_ajax_referer('tpm_categories','nonce');if(!current_user_can('manage_product_terms'))wp_send_json_error([],403);
 $raw=isset($_POST['structure'])?json_decode(wp_unslash($_POST['structure']),true):[];if(!is_array($raw))wp_send_json_error(['message'=>'Ugyldig struktur.']);
 foreach($raw as$item){$id=absint($item['id']??0);$parent=absint($item['parent']??0);$order=absint($item['order']??0);if(!$id||$id===$parent)continue;$r=wp_update_term($id,'product_cat',['parent'=>$parent]);if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()]);update_term_meta($id,'order',$order);}
 wp_send_json_success(['message'=>'Strukturen er gemt.']);
}
add_action('wp_ajax_tpm_cat_structure','tpm_cat_structure');
function tpm_cat_delete(){
 check_ajax_referer('tpm_categories','nonce');if(!current_user_can('delete_product_terms'))wp_send_json_error(['message'=>'Ingen adgang.'],403);
 $id=absint($_POST['id']??0);$uncategorized=(int)get_option('default_product_cat',0);if(!$id||$id===$uncategorized)wp_send_json_error(['message'=>'Denne kategori kan ikke slettes.']);
 $t=get_term($id,'product_cat');if(!$t||is_wp_error($t))wp_send_json_error(['message'=>'Kategorien blev ikke fundet.']);
 $children=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$id,'fields'=>'ids']);if(!is_wp_error($children))foreach($children as$child)wp_update_term($child,'product_cat',['parent'=>$t->parent]);
 $r=wp_delete_term($id,'product_cat');if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()]);wp_send_json_success(['message'=>'Kategorien er slettet.']);
}
add_action('wp_ajax_tpm_cat_delete','tpm_cat_delete');

function tpm_products(){
 tpm_admin_head('Produkter','Et enklere produktkatalog oven på WooCommerce.');
 $products=wc_get_products(['limit'=>100,'orderby'=>'date','order'=>'DESC','status'=>['publish','draft','private']]);
 echo '<section class="tpm-card tpm-table-card"><table class="tpm-table"><thead><tr><th>Produkt</th><th>SKU</th><th>Lager</th><th>Pris</th><th>Leverandør</th><th></th></tr></thead><tbody>';
 foreach($products as$p){$img=$p->get_image_id()?wp_get_attachment_image($p->get_image_id(),'thumbnail'):'';$sup=get_post_meta($p->get_id(),'_tpm_supplier',true);echo '<tr><td><div class="tpm-product-cell">'.$img.'<div><strong>'.esc_html($p->get_name()).'</strong><small>'.esc_html($p->get_status()).'</small></div></div></td><td>'.esc_html($p->get_sku()?:'—').'</td><td>'.esc_html($p->managing_stock()?($p->get_stock_quantity().' stk.'):$p->get_stock_status()).'</td><td>'.wp_kses_post($p->get_price_html()).'</td><td>'.esc_html($sup?:'—').'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=terttus-product-manager&product_id='.$p->get_id())).'">Redigér</a></td></tr>';}
 echo '</tbody></table></section></div>';
}
function tpm_orders(){
 tpm_admin_head('Ordrer','Fra ny ordre til pakket og afsluttet.');
 $orders=wc_get_orders(['limit'=>50,'orderby'=>'date','order'=>'DESC']);
 echo '<div class="tpm-order-lanes"><div><span>Behandler</span><strong>'.wc_orders_count('processing').'</strong></div><div><span>På hold</span><strong>'.wc_orders_count('on-hold').'</strong></div><div><span>Afsluttet</span><strong>'.wc_orders_count('completed').'</strong></div></div><section class="tpm-card tpm-table-card"><table class="tpm-table"><thead><tr><th>Ordre</th><th>Kunde</th><th>Dato</th><th>Status</th><th>Total</th><th></th></tr></thead><tbody>';
 foreach($orders as$o){echo '<tr><td><strong>#'.esc_html($o->get_order_number()).'</strong></td><td>'.esc_html(trim($o->get_billing_first_name().' '.$o->get_billing_last_name())?:'Gæst').'</td><td>'.esc_html($o->get_date_created()?$o->get_date_created()->date_i18n('d/m/Y H:i'):'—').'</td><td><span class="tpm-status">'.esc_html(wc_get_order_status_name($o->get_status())).'</span></td><td>'.wp_kses_post($o->get_formatted_order_total()).'</td><td><a class="button" href="'.esc_url($o->get_edit_order_url()).'">Åbn</a></td></tr>';}
 echo '</tbody></table></section></div>';
}
function tpm_stock(){
 tpm_admin_head('Lager','Se lager, kostpris og estimeret avance samlet.');
 $products=wc_get_products(['limit'=>200,'status'=>['publish','draft'],'orderby'=>'title','order'=>'ASC']);
 echo '<section class="tpm-card tpm-table-card"><table class="tpm-table"><thead><tr><th>Produkt</th><th>SKU</th><th>Status</th><th>Antal</th><th>Kostpris</th><th>Salgspris</th><th>Avance</th></tr></thead><tbody>';
 foreach($products as$p){$cost=(float)get_post_meta($p->get_id(),'_tpm_cost',true);$price=(float)$p->get_regular_price();$net=$price/1.25;$margin=$net>0?(($net-$cost)/$net*100):0;echo '<tr><td><a href="'.esc_url(admin_url('admin.php?page=terttus-product-manager&product_id='.$p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td>'.esc_html($p->get_sku()?:'—').'</td><td>'.esc_html(wc_get_product_stock_status_options()[$p->get_stock_status()]??$p->get_stock_status()).'</td><td>'.esc_html($p->managing_stock()?$p->get_stock_quantity():'—').'</td><td>'.wp_kses_post(tpm_money($cost)).'</td><td>'.wp_kses_post(tpm_money($price)).'</td><td>'.esc_html(number_format_i18n($margin,1)).'%</td></tr>';}
 echo '</tbody></table></section></div>';
}
function tpm_suppliers(){
 tpm_admin_head('Leverandører','Overblik over de leverandører, der er knyttet til dine varer.');
 $products=wc_get_products(['limit'=>-1,'status'=>['publish','draft']]);$sup=[];
 foreach($products as$p){$name=trim((string)get_post_meta($p->get_id(),'_tpm_supplier',true));if(!$name)continue;if(!isset($sup[$name]))$sup[$name]=['count'=>0,'value'=>0];$sup[$name]['count']++;$sup[$name]['value']+=(float)get_post_meta($p->get_id(),'_tpm_cost',true)*max(0,(int)$p->get_stock_quantity());}
 echo '<div class="tpm-supplier-grid">';if(!$sup)echo '<section class="tpm-card"><h2>Ingen leverandører endnu</h2><p>Tilføj fx DCS på dine produkter. Så samler vi automatisk overblikket her.</p></section>';foreach($sup as$name=>$d)echo '<section class="tpm-card"><span class="tpm-eyebrow">Leverandør</span><h2>'.esc_html($name).'</h2><p><strong>'.$d['count'].'</strong> produkter</p><p>Lagerets kostværdi: '.wp_kses_post(tpm_money($d['value'])).'</p></section>';echo '</div></div>';
}

function tpm_screen(){
 if(!class_exists('WooCommerce')){echo'<div class="notice notice-error"><p>WooCommerce skal være aktivt.</p></div>';return;}
 $id=absint($_GET['product_id']??0);$p=$id?wc_get_product($id):null;if($id&&!$p)wp_die('Produktet blev ikke fundet.');
 if(isset($_GET['saved']))echo'<div class="notice notice-success is-dismissible"><p>Produktet er gemt.</p></div>';
 $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);$selected=$p?$p->get_category_ids():[];$gallery=$p?$p->get_gallery_image_ids():[];
 ?><div class="wrap tpm"><div class="tpm-head"><div><h1><?php echo $p?'Redigér produkt':'Nyt produkt';?></h1><p>En enkel arbejdsgang, gemt direkte i WooCommerce.</p></div><div class="tpm-head-actions"><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=product'));?>">Alle produkter</a><?php if($p):?><a class="button" href="<?php echo esc_url(get_permalink($p->get_id()));?>" target="_blank">Se produkt</a><?php endif;?><span class="tpm-badge">Terttus</span></div></div>
 <section class="tpm-card tpm-dcs-importer"><div class="tpm-import-head"><div><span class="tpm-eyebrow">DCS import</span><h2>Importér direkte fra DCS</h2><p>Indsæt en offentlig DCS-produktadresse. Vi henter de produktdata, billeder og specifikationer som DCS gør offentligt tilgængelige. Kostpris indtastes manuelt bagefter.</p></div><button type="button" class="button button-primary tpm-fetch-dcs">Hent produkt</button></div><div class="tpm-url-row"><input type="url" id="tpm-dcs-url" placeholder="https://www.dcs.dk/da/p/..."><span class="spinner"></span></div><div id="tpm-dcs-result" class="tpm-import-preview" hidden></div></section><section class="tpm-card tpm-importer"><div class="tpm-import-head"><div><span class="tpm-eyebrow">Hurtig import</span><h2>Indsæt produktdata</h2><p>Indsæt kopieret tekst fra DCS eller en anden leverandør. Vi forsøger at finde navn, varenummer, EAN, mærke, priser og billedlinks.</p></div><button type="button" class="button button-primary tpm-parse-import">Analysér data</button></div><textarea id="tpm-import-text" rows="8" placeholder="Indsæt produktdata her..."></textarea><div id="tpm-import-preview" class="tpm-import-preview" hidden></div></section><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field('tpm_save');?><input type="hidden" name="action" value="tpm_save_product"><input type="hidden" name="product_id" value="<?php echo $id;?>">
 <div class="tpm-layout"><main>
 <section class="tpm-card"><h2>1. Grundinfo</h2><div class="tpm-grid"><label class="wide">Produktnavn<input required name="name" value="<?php echo esc_attr(tpm_val($p,'name'));?>"></label><label>SKU / varenummer<input name="sku" value="<?php echo esc_attr(tpm_val($p,'sku'));?>"></label><label>EAN / GTIN<input name="ean" value="<?php echo esc_attr(tpm_val($p,'ean'));?>"></label><label>Mærke<input name="brand" value="<?php echo esc_attr(tpm_val($p,'brand'));?>"></label><div class="wide tpm-category-field"><div class="tpm-category-heading"><strong>Kategorier</strong><span>Vælg én eller flere</span></div><div class="tpm-category-picker"><?php $uncategorized=(int)get_option('default_product_cat',0);foreach($cats as$c){if($uncategorized&&$c->term_id===$uncategorized)continue;$ancestors=array_reverse(get_ancestors($c->term_id,'product_cat'));$depth=count($ancestors);echo '<label class="tpm-category-option depth-'.intval(min($depth,4)).'"><input type="checkbox" name="categories[]" value="'.intval($c->term_id).'" '.checked(in_array($c->term_id,$selected),true,false).'><span class="tpm-category-box"></span><span class="tpm-category-name">'.($depth?'<span class="tpm-category-branch">↳</span>':'').esc_html($c->name).'</span></label>';}?></div><small class="description">Tilbud-kategorien styres automatisk ud fra tilbudsprisen.</small></div></div></section>
 <section class="tpm-card"><h2>2. Billeder</h2><div class="tpm-url-import"><label>Hovedbillede fra URL<input type="url" id="tpm-main-url" name="main_image_url" placeholder="https://leverandoer.dk/billede.jpg"></label><button type="button" class="button tpm-preview-url">Vis preview</button><p class="description">Når produktet gemmes, downloades billedet til WordPress mediebibliotek.</p><label>Galleri fra URL'er<textarea name="gallery_image_urls" rows="4" placeholder="Én billed-URL pr. linje"></textarea></label></div><input type="hidden" id="tpm-image-id" name="image_id" value="<?php echo intval(tpm_val($p,'image'));?>"><input type="hidden" id="tpm-gallery-ids" name="gallery_ids" value="<?php echo esc_attr(implode(',',$gallery));?>"><div class="tpm-media"><div id="tpm-main-preview" class="tpm-image-preview"><?php if(tpm_val($p,'image'))echo wp_get_attachment_image(tpm_val($p,'image'),'medium');?></div><div><button type="button" class="button tpm-pick-main">Vælg hovedbillede</button><button type="button" class="button tpm-pick-gallery">Vælg galleri</button><p class="description">Bruger WordPress' eget mediebibliotek.</p></div></div><div id="tpm-gallery-preview" class="tpm-gallery"><?php foreach($gallery as$g)echo wp_get_attachment_image($g,'thumbnail');?></div></section>
 <section class="tpm-card"><h2>3. Pris & tilbud</h2><div class="tpm-grid"><label>Indkøbspris ekskl. moms<input type="number" step=".01" min="0" name="cost" id="tpm-cost" value="<?php echo esc_attr(tpm_val($p,'cost'));?>"></label><label>Salgspris inkl. moms<input required type="number" step=".01" min="0" name="price" id="tpm-price" value="<?php echo esc_attr(tpm_val($p,'price'));?>"></label><label>Tilbudspris<input type="number" step=".01" min="0" name="sale" value="<?php echo esc_attr(tpm_val($p,'sale'));?>" placeholder="Tom = intet tilbud"></label><div class="tpm-metric"><span>Estimeret avance</span><strong id="tpm-margin">—</strong></div></div><div class="tpm-sale-schedule"><div><strong>Planlæg tilbud</strong><p>Valgfrit. Uden datoer gælder tilbudsprisen med det samme og indtil du fjerner den.</p></div><label>Starter<input type="datetime-local" name="sale_from" value="<?php $d=tpm_val($p,'sale_from');echo esc_attr($d instanceof WC_DateTime?$d->date('Y-m-d\\TH:i'):'');?>"></label><label>Slutter<input type="datetime-local" name="sale_to" value="<?php $d=tpm_val($p,'sale_to');echo esc_attr($d instanceof WC_DateTime?$d->date('Y-m-d\\TH:i'):'');?>"></label><?php if($p&&$p->get_sale_price()!==''):$sf=$p->get_date_on_sale_from();$st=$p->get_date_on_sale_to();$now=time();$sale_active=(!$sf||$sf->getTimestamp()<=$now)&&(!$st||$st->getTimestamp()>=$now);$sale_future=$sf&&$sf->getTimestamp()>$now;?><span class="tpm-sale-status <?php echo $sale_active?'is-active':($sale_future?'is-planned':'is-expired');?>"><?php echo $sale_active?'Tilbud aktivt':($sale_future?'Tilbud planlagt':'Tilbud udløbet');?></span><?php endif;?><?php if($p&&$p->get_sale_price()!==''):?><small class="tpm-sale-debug"><?php echo esc_html(tpm_sale_debug_text($p));?></small><?php endif;?></div></section>
 <section class="tpm-card"><h2>4. Lager & leverandør</h2><div class="tpm-grid"><label>Lagerstatus<select name="stock_status"><?php foreach(['instock'=>'På lager','onbackorder'=>'Bestillingsvare','outofstock'=>'Udsolgt']as$v=>$l)echo'<option value="'.$v.'" '.selected(tpm_val($p,'stock_status','instock'),$v,false).'>'.$l.'</option>';?></select></label><label>Antal på lager<input type="number" min="0" name="stock" value="<?php echo esc_attr(tpm_val($p,'stock'));?>"></label><label>Leverandør<input name="supplier" value="<?php echo esc_attr(tpm_val($p,'supplier'));?>" placeholder="fx DCS"></label><label>Leverandørvarenr.<input name="supplier_sku" value="<?php echo esc_attr(tpm_val($p,'supplier_sku'));?>"></label><label class="wide">Leverandør-URL<input type="url" name="supplier_url" value="<?php echo esc_attr(tpm_val($p,'supplier_url'));?>"></label></div></section>
 <section class="tpm-card"><h2>5. Beskrivelse</h2><label>Kort salgstekst<textarea name="short_description" rows="4"><?php echo esc_textarea(tpm_val($p,'short_description'));?></textarea></label><label>Lang beskrivelse<textarea name="description" rows="9"><?php echo esc_textarea(tpm_val($p,'description'));?></textarea></label></section>
 <section class="tpm-card"><h2>6. Specifikationer</h2><div id="tpm-specs"><?php $specs=$p?get_post_meta($p->get_id(),'_tpm_specs',true):[];if(!is_array($specs))$specs=[];foreach($specs as$s):?><div class="tpm-spec"><input name="spec_name[]" value="<?php echo esc_attr($s['name']);?>" placeholder="Egenskab"><input name="spec_value[]" value="<?php echo esc_attr($s['value']);?>" placeholder="Værdi"><button type="button" class="button tpm-remove-spec">×</button></div><?php endforeach;?></div><button type="button" class="button tpm-add-spec">+ Tilføj specifikation</button></section>
 </main><aside><section class="tpm-card tpm-sticky"><h2>Publicering</h2><label>Status<select name="status"><option value="draft" <?php selected($p?$p->get_status():'draft','draft');?>>Kladde</option><option value="publish" <?php selected($p?$p->get_status():'draft','publish');?>>Udgivet</option></select></label><button class="button button-primary button-hero tpm-save">Gem produkt</button><?php if($p):?><a class="button tpm-duplicate" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tpm_duplicate_product&product_id='.$p->get_id()),'tpm_duplicate_'.$p->get_id()));?>">Duplikér produkt</a><?php endif;?><div class="tpm-check"><strong>Produkt-tjek</strong><ul id="tpm-checklist"><li data-check="name">Produktnavn</li><li data-check="price">Pris</li><li data-check="image">Hovedbillede</li><li data-check="category">Kategori</li></ul></div></section></aside></div></form></div><?php
}
function tpm_dcs_text($xpath,$query){$n=$xpath->query($query);return $n&&$n->length?trim(preg_replace('/\\s+/u',' ',$n->item(0)->textContent)):'';}
function tpm_dcs_import(){if(!current_user_can('edit_products'))wp_send_json_error(['message'=>'Ingen adgang.'],403);check_ajax_referer('tpm_dcs_import','nonce');$url=esc_url_raw($_POST['url']??'');$host=strtolower((string)parse_url($url,PHP_URL_HOST));if(!$url||!preg_match('/(^|\\.)dcs\\.dk$/',$host))wp_send_json_error(['message'=>'Indsæt en gyldig dcs.dk produktadresse.']);$r=wp_safe_remote_get($url,['timeout'=>20,'redirection'=>3,'user-agent'=>'Mozilla/5.0 TerttusCommerce/'.TPM_VERSION]);if(is_wp_error($r))wp_send_json_error(['message'=>'DCS-siden kunne ikke hentes: '.$r->get_error_message()]);if(wp_remote_retrieve_response_code($r)!==200)wp_send_json_error(['message'=>'DCS returnerede HTTP '.wp_remote_retrieve_response_code($r).'.']);$html=wp_remote_retrieve_body($r);if(!$html)wp_send_json_error(['message'=>'DCS-siden var tom.']);libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);$data=['supplier'=>'DCS','supplier_url'=>$url,'name'=>'','sku'=>'','ean'=>'','brand'=>'','model'=>'','description'=>'','images'=>[],'specs'=>[]];$json=$xp->query('//script[@type="application/ld+json"]');foreach($json as$j){$o=json_decode($j->textContent,true);$items=isset($o['@graph'])?$o['@graph']:[$o];foreach((array)$items as$x){if(!is_array($x))continue;$type=$x['@type']??'';if($type==='Product'||(is_array($type)&&in_array('Product',$type,true))){$data['name']=$x['name']??$data['name'];$data['description']=$x['description']??$data['description'];$data['sku']=$x['sku']??$data['sku'];$data['ean']=$x['gtin13']??($x['gtin']??$data['ean']);$b=$x['brand']??'';$data['brand']=is_array($b)?($b['name']??''):$b;$ims=$x['image']??[];foreach((array)$ims as$im){if(is_array($im))$im=$im['url']??'';if($im){if(strpos($im,'//')===0)$im='https:'.$im;elseif(strpos($im,'/')===0)$im='https://'.$host.$im;if(filter_var($im,FILTER_VALIDATE_URL))$data['images'][]=$im;}}}}}$body=trim(preg_replace('/\\s+/u',' ',strip_tags($html)));foreach([['sku','VARENUMMER'],['ean','EAN'],['model','MODEL'],['brand','PRODUCENT']]as$map){if(!$data[$map[0]]&&preg_match('/'.$map[1].'\\s*:?\\s*([^<|]{2,100}?)(?=\\s+(?:MODEL|EAN|PRODUCENT|VÆGT|VARENUMMER)\\s*:|$)/iu',$body,$m))$data[$map[0]]=trim($m[1]);}/* Product images: prefer DCS product-gallery links and structured Product JSON.
   Do not scrape every <img> on the page (logos/icons/recommendations). */
$gallery_images=[];
$gallery_nodes=$xp->query('//a[@href][.//img][contains(@class,"gallery") or contains(@class,"product") or contains(@data-fancybox,"product") or contains(@data-lightbox,"product")] | //*[(contains(@class,"product") and contains(@class,"gallery")) or contains(@class,"product-gallery") or contains(@class,"product-images")]//a[@href]');
foreach($gallery_nodes as$node){
 $src=$node->getAttribute('href');
 if(!$src)continue;
 if(strpos($src,'//')===0)$src='https:'.$src;elseif(strpos($src,'/')===0)$src='https://'.$host.$src;
 if(filter_var($src,FILTER_VALIDATE_URL)&&preg_match('/\\.(jpe?g|png|webp)(\\?|$)/i',$src))$gallery_images[]=$src;
}
if($gallery_images)$data['images']=$gallery_images;
$data['images']=array_values(array_unique(array_filter($data['images'])));
$rows=$xp->query('//table//tr');foreach($rows as$row){$cells=(new DOMXPath($dom))->query('./th|./td',$row);if($cells->length>=2){$n=trim(preg_replace('/\\s+/u',' ',$cells->item(0)->textContent));$v=trim(preg_replace('/\\s+/u',' ',$cells->item(1)->textContent));if($n&&$v)$data['specs'][]=['name'=>$n,'value'=>$v];}}if(!$data['name'])$data['name']=tpm_dcs_text($xp,'//h1');wp_send_json_success($data);}add_action('wp_ajax_tpm_dcs_import','tpm_dcs_import');
function tpm_import_image_url($url,$post_id=0){$url=esc_url_raw(trim($url));if(!$url||!wp_http_validate_url($url))return 0;require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';$tmp=download_url($url,20);if(is_wp_error($tmp))return 0;$path=(string)parse_url($url,PHP_URL_PATH);$name=sanitize_file_name(basename($path));if(!$name||strpos($name,'.')===false)$name='product-image.jpg';$file=['name'=>$name,'tmp_name'=>$tmp];$id=media_handle_sideload($file,$post_id);if(is_wp_error($id)){@unlink($tmp);return 0;}return (int)$id;}
function tpm_store_timezone(){return new DateTimeZone('Europe/Copenhagen');}
function tpm_sale_term_id(){
 $term=get_term_by('slug','tilbud','product_cat');if($term&&!is_wp_error($term))return (int)$term->term_id;
 $r=wp_insert_term('Tilbud','product_cat',['slug'=>'tilbud']);return is_wp_error($r)?0:(int)$r['term_id'];
}
function tpm_sync_sale_category($product_or_id){
 $p=is_object($product_or_id)?$product_or_id:wc_get_product($product_or_id);if(!$p)return;
 $sale_id=tpm_sale_term_id();if(!$sale_id)return;$ids=array_map('intval',$p->get_category_ids());$now=time();$from=$p->get_date_on_sale_from();$to=$p->get_date_on_sale_to();$active=$p->get_sale_price()!==''&&(!$from||$from->getTimestamp()<=$now)&&(!$to||$to->getTimestamp()>=$now);
 if($active&&!in_array($sale_id,$ids,true)){$ids[]=$sale_id;$p->set_category_ids(array_values(array_unique($ids)));$p->save();}
 elseif(!$active&&in_array($sale_id,$ids,true)){$p->set_category_ids(array_values(array_diff($ids,[$sale_id])));$p->save();}
}
function tpm_sale_debug_text($p){if(!$p||$p->get_sale_price()==='')return '';$sf=$p->get_date_on_sale_from();$st=$p->get_date_on_sale_to();$tz=tpm_store_timezone();$now=new DateTimeImmutable('now',$tz);return 'Nu: '.$now->format('d-m-Y H:i').' · Gemt start: '.($sf?$sf->setTimezone($tz)->date('d-m-Y H:i'):'straks').' · Gemt slut: '.($st?$st->setTimezone($tz)->date('d-m-Y H:i'):'ingen').' · Tidszone: '.$tz->getName();}
function tpm_sync_all_sale_categories(){
 if(!function_exists('wc_get_products'))return;$ids=wc_get_products(['limit'=>-1,'status'=>['publish','draft','private'],'return'=>'ids']);foreach($ids as$id)tpm_sync_sale_category($id);
}
add_action('woocommerce_scheduled_sales','tpm_sync_all_sale_categories',20);
add_action('save_post_product',function($post_id,$post,$update){if(wp_is_post_revision($post_id)||defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;if(function_exists('wc_get_product'))tpm_sync_sale_category($post_id);},30,3);
function tpm_save_product(){if(!current_user_can('edit_products')||!check_admin_referer('tpm_save'))wp_die('Ingen adgang.');$id=absint($_POST['product_id']??0);$p=$id?wc_get_product($id):new WC_Product_Simple();if(!$p)wp_die('Produkt ikke fundet.');$name=sanitize_text_field($_POST['name']??'');if(!$name)wp_die('Produktnavn mangler.');$p->set_name($name);$p->set_status(in_array($_POST['status']??'draft',['draft','publish'],true)?$_POST['status']:'draft');$p->set_sku(sanitize_text_field($_POST['sku']??''));$p->set_regular_price(wc_format_decimal($_POST['price']??''));$sale=wc_format_decimal($_POST['sale']??'');$p->set_sale_price($sale);$from=sanitize_text_field($_POST['sale_from']??'');$to=sanitize_text_field($_POST['sale_to']??'');try{$tz=tpm_store_timezone();$from_dt=$sale&&$from?DateTimeImmutable::createFromFormat('Y-m-d\\TH:i',$from,$tz):null;$to_dt=$sale&&$to?DateTimeImmutable::createFromFormat('Y-m-d\\TH:i',$to,$tz):null;$p->set_date_on_sale_from($from_dt?$from_dt->getTimestamp():null);$p->set_date_on_sale_to($to_dt?$to_dt->getTimestamp():null);}catch(Exception $e){$p->set_date_on_sale_from(null);$p->set_date_on_sale_to(null);}$p->set_short_description(wp_kses_post($_POST['short_description']??''));$p->set_description(wp_kses_post($_POST['description']??''));$p->set_stock_status(in_array($_POST['stock_status']??'instock',['instock','outofstock','onbackorder'],true)?$_POST['stock_status']:'instock');if(($_POST['stock']??'')!==''){$p->set_manage_stock(true);$p->set_stock_quantity(max(0,intval($_POST['stock'])));}else{$p->set_manage_stock(false);}$cats_in=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['categories']??[])))));$existing_sale=tpm_sale_term_id();$keep=[];if($existing_sale&&in_array($existing_sale,array_map('intval',$p->get_category_ids()),true))$keep[]=$existing_sale;$cats_in=array_values(array_diff($cats_in,$existing_sale?[$existing_sale]:[]));$p->set_category_ids(array_values(array_unique(array_merge($cats_in,$keep))));$p->set_image_id(absint($_POST['image_id']??0));$gallery=array_filter(array_map('absint',explode(',',sanitize_text_field($_POST['gallery_ids']??''))));$p->set_gallery_image_ids($gallery);$id=$p->save();wc_delete_product_transients($id);$p=wc_get_product($id);$main_url=esc_url_raw($_POST['main_image_url']??'');if($main_url){$img=tpm_import_image_url($main_url,$id);if($img)$p->set_image_id($img);}$raw_urls=sanitize_textarea_field($_POST['gallery_image_urls']??'');if($raw_urls){$urls=preg_split('/\\r\\n|\\r|\\n/',$raw_urls);foreach((array)$urls as$url){$img=tpm_import_image_url($url,$id);if($img)$gallery[]=$img;}}$p->set_gallery_image_ids(array_values(array_unique(array_filter($gallery))));$p->save();wc_delete_product_transients($id);$p=wc_get_product($id);tpm_sync_sale_category($p);foreach(['ean','brand','cost','supplier','supplier_sku','supplier_url']as$k)update_post_meta($id,'_tpm_'.$k,sanitize_text_field($_POST[$k]??''));$names=(array)($_POST['spec_name']??[]);$vals=(array)($_POST['spec_value']??[]);$specs=[];foreach($names as$i=>$n){$n=sanitize_text_field($n);$v=sanitize_text_field($vals[$i]??'');if($n||$v)$specs[]=['name'=>$n,'value'=>$v];}update_post_meta($id,'_tpm_specs',$specs);wp_safe_redirect(add_query_arg(['page'=>'terttus-product-manager','saved'=>1,'product_id'=>$id],admin_url('admin.php')));exit;}add_action('admin_post_tpm_save_product','tpm_save_product');
function tpm_duplicate(){if(!current_user_can('edit_products'))wp_die('Ingen adgang.');$id=absint($_GET['product_id']??0);check_admin_referer('tpm_duplicate_'.$id);$src=wc_get_product($id);if(!$src)wp_die('Produkt ikke fundet.');$copy=clone $src;$copy->set_id(0);$copy->set_name($src->get_name().' – kopi');$copy->set_status('draft');$copy->set_sku('');$new=$copy->save();foreach(['ean','brand','cost','supplier','supplier_sku','supplier_url','specs']as$k){$v=get_post_meta($id,'_tpm_'.$k,true);if($v!=='')update_post_meta($new,'_tpm_'.$k,$v);}wp_safe_redirect(add_query_arg(['page'=>'terttus-product-manager','product_id'=>$new],admin_url('admin.php')));exit;}add_action('admin_post_tpm_duplicate_product','tpm_duplicate');
