<?php
/*
Plugin Name: Terttus Product Manager
Description: En enkel og moderne produktoprettelse oven på WooCommerce.
Version: 0.1.0
Author: Terttus
Requires PHP: 7.4
*/
if(!defined('ABSPATH'))exit;
define('TPM_VERSION','0.1.0');
function tpm_menu(){add_submenu_page('woocommerce','Terttus Product Manager','Produkt Manager','edit_products','terttus-product-manager','tpm_screen');}
add_action('admin_menu','tpm_menu',30);
function tpm_assets($hook){if(strpos($hook,'terttus-product-manager')===false)return;wp_enqueue_media();wp_enqueue_style('tpm',plugins_url('assets/admin.css',__FILE__),[],TPM_VERSION);wp_enqueue_script('tpm',plugins_url('assets/admin.js',__FILE__),['jquery'],TPM_VERSION,true);}
add_action('admin_enqueue_scripts','tpm_assets');
function tpm_screen(){
 if(!class_exists('WooCommerce')){echo '<div class="notice notice-error"><p>WooCommerce skal være aktivt.</p></div>';return;}
 if(isset($_GET['saved']))echo '<div class="notice notice-success is-dismissible"><p>Produktet er gemt.</p></div>';
 $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
 ?>
 <div class="wrap tpm"><div class="tpm-head"><div><h1>Nyt produkt</h1><p>Opret Terttus-produkter uden WooCommerce-rod.</p></div><span class="tpm-badge">Terttus Product Manager</span></div>
 <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field('tpm_save');?><input type="hidden" name="action" value="tpm_save_product">
 <div class="tpm-layout"><main>
 <section class="tpm-card"><h2>1. Grundinfo</h2><div class="tpm-grid"><label class="wide">Produktnavn<input required name="name"></label><label>SKU / varenummer<input name="sku"></label><label>EAN / GTIN<input name="ean"></label><label>Mærke<input name="brand"></label><label>Kategori<select name="category"><option value="">Vælg kategori</option><?php foreach($cats as$c)echo '<option value="'.intval($c->term_id).'">'.esc_html($c->name).'</option>';?></select></label></div></section>
 <section class="tpm-card"><h2>2. Pris & avance</h2><div class="tpm-grid"><label>Indkøbspris ekskl. moms<input type="number" step="0.01" min="0" name="cost" id="tpm-cost"></label><label>Salgspris inkl. moms<input required type="number" step="0.01" min="0" name="price" id="tpm-price"></label><label>Tilbudspris<input type="number" step="0.01" min="0" name="sale"></label><div class="tpm-metric"><span>Estimeret avance</span><strong id="tpm-margin">—</strong></div></div></section>
 <section class="tpm-card"><h2>3. Lager & levering</h2><div class="tpm-grid"><label>Lagerstatus<select name="stock_status"><option value="instock">På lager</option><option value="onbackorder">Bestillingsvare</option><option value="outofstock">Udsolgt</option></select></label><label>Antal på lager<input type="number" min="0" name="stock"></label><label>Leverandør<input name="supplier" placeholder="fx DCS"></label><label>Leverandørvarenr.<input name="supplier_sku"></label><label class="wide">Leverandør-URL<input type="url" name="supplier_url"></label></div></section>
 <section class="tpm-card"><h2>4. Beskrivelse</h2><label>Kort salgstekst<textarea name="short_description" rows="4"></textarea></label><label>Lang beskrivelse<textarea name="description" rows="9"></textarea></label></section>
 </main><aside>
 <section class="tpm-card tpm-sticky"><h2>Publicering</h2><label>Status<select name="status"><option value="draft">Gem som kladde</option><option value="publish">Udgiv nu</option></select></label><button class="button button-primary button-hero tpm-save">Gem produkt</button><p class="description">Du kan altid redigere produktet i WooCommerce bagefter.</p></section>
 </aside></div></form></div><?php
}
function tpm_save_product(){if(!current_user_can('edit_products')||!check_admin_referer('tpm_save'))wp_die('Ingen adgang.');if(!class_exists('WC_Product_Simple'))wp_die('WooCommerce mangler.');
 $name=sanitize_text_field($_POST['name']??'');if(!$name)wp_die('Produktnavn mangler.');
 $p=new WC_Product_Simple();$p->set_name($name);$p->set_status(in_array($_POST['status']??'draft',['draft','publish'],true)?$_POST['status']:'draft');
 $p->set_sku(sanitize_text_field($_POST['sku']??''));$p->set_regular_price(wc_format_decimal($_POST['price']??''));$sale=wc_format_decimal($_POST['sale']??'');if($sale!=='')$p->set_sale_price($sale);
 $p->set_short_description(wp_kses_post($_POST['short_description']??''));$p->set_description(wp_kses_post($_POST['description']??''));
 $stock_status=in_array($_POST['stock_status']??'instock',['instock','outofstock','onbackorder'],true)?$_POST['stock_status']:'instock';$p->set_stock_status($stock_status);
 if(isset($_POST['stock'])&&$_POST['stock']!==''){$p->set_manage_stock(true);$p->set_stock_quantity(max(0,intval($_POST['stock'])));}
 $cat=intval($_POST['category']??0);if($cat)$p->set_category_ids([$cat]);$id=$p->save();
 foreach(['ean','brand','cost','supplier','supplier_sku','supplier_url'] as$k)update_post_meta($id,'_tpm_'.$k,sanitize_text_field($_POST[$k]??''));
 wp_safe_redirect(add_query_arg(['page'=>'terttus-product-manager','saved'=>1,'product_id'=>$id],admin_url('admin.php')));exit;
}
add_action('admin_post_tpm_save_product','tpm_save_product');
