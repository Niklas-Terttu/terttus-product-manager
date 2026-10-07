jQuery(function($){
  function esc(v){ return $('<div>').text(v == null ? '' : String(v)).html(); }
  function calc(){
    let c=parseFloat($('#tpm-cost').val()),p=parseFloat($('#tpm-price').val());
    if(!isFinite(c)||!isFinite(p)||p<=0){$('#tpm-margin').text('—');return;}
    let net=p/1.25,profit=net-c,margin=profit/net*100;
    $('#tpm-margin').text(profit.toFixed(2)+' kr. · '+margin.toFixed(1)+'%');
  }
  function check(){
    let x={name:$('[name=name]').val().trim(),price:parseFloat($('#tpm-price').val())>0,image:parseInt($('#tpm-image-id').val())>0||$('#tpm-main-url').val().trim()!=='',category:$('[name=category]').val()};
    Object.keys(x).forEach(k=>$('[data-check='+k+']').toggleClass('ok',!!x[k]));
  }

  $('.tpm-fetch-dcs').on('click',function(){
    let url=$('#tpm-dcs-url').val().trim(),$b=$(this),$r=$('#tpm-dcs-result'),$s=$('.tpm-dcs-importer .spinner');
    if(!url){$r.prop('hidden',false).html('<p class="tpm-error">Indsæt først et DCS-link.</p>');return;}
    if(typeof TPM==='undefined'||!TPM.ajax){$r.prop('hidden',false).html('<p class="tpm-error">Import-scriptet mangler WordPress AJAX-konfiguration.</p>');return;}
    $b.prop('disabled',true);$s.addClass('is-active');$r.prop('hidden',false).html('<p>Henter produktdata fra DCS…</p>');
    $.ajax({url:TPM.ajax,method:'POST',dataType:'json',data:{action:'tpm_dcs_import',nonce:TPM.nonce,url:url}})
      .done(function(res){
        if(!res||!res.success){let m=res&&res.data&&res.data.message?res.data.message:'Importen mislykkedes.';$r.html('<p class="tpm-error">'+esc(m)+'</p>');return;}
        let d=res.data||{},rows=[];
        [['name','Produkt'],['sku','Varenummer'],['ean','EAN'],['brand','Producent'],['model','Model']].forEach(x=>{if(d[x[0]])rows.push('<div><span>'+x[1]+'</span><strong>'+esc(d[x[0]])+'</strong></div>');});
        rows.push('<div><span>Billeder</span><strong>'+((d.images||[]).length)+' fundet</strong></div>');
        rows.push('<div><span>Specifikationer</span><strong>'+((d.specs||[]).length)+' fundet</strong></div>');
        $r.html('<div class="tpm-import-found">'+rows.join('')+'</div><button type="button" class="button button-primary tpm-apply-dcs">Brug DCS-data</button><p class="description">Kostpris hentes ikke og skal indtastes manuelt.</p>').data('dcs',d);
      })
      .fail(function(xhr){
        let m='Kunne ikke kontakte importfunktionen.';
        if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)m=xhr.responseJSON.data.message;
        else if(xhr.status)m+=' HTTP '+xhr.status+'.';
        $r.html('<p class="tpm-error">'+esc(m)+'</p>');
      })
      .always(function(){$b.prop('disabled',false);$s.removeClass('is-active');});
  });

  $(document).on('click','.tpm-apply-dcs',function(){
    let d=$('#tpm-dcs-result').data('dcs')||{};
    if(d.name)$('[name=name]').val(d.name);
    if(d.sku)$('[name=sku]').val(d.sku);
    if(d.ean)$('[name=ean]').val(d.ean);
    if(d.brand)$('[name=brand]').val(d.brand);
    $('[name=supplier]').val('DCS');
    if(d.sku)$('[name=supplier_sku]').val(d.sku);
    if(d.supplier_url)$('[name=supplier_url]').val(d.supplier_url);
    if(d.description)$('[name=description]').val(d.description);
    if(d.images&&d.images.length){$('#tpm-main-url').val(d.images[0]);$('[name=gallery_image_urls]').val(d.images.slice(1).join('\n'));$('.tpm-preview-url').trigger('click');}
    if(d.specs&&d.specs.length){$('#tpm-specs').empty();d.specs.forEach(s=>$('#tpm-specs').append('<div class="tpm-spec"><input name="spec_name[]" value="'+esc(s.name)+'"><input name="spec_value[]" value="'+esc(s.value)+'"><button type="button" class="button tpm-remove-spec">×</button></div>'));}
    check();$('html,body').animate({scrollTop:$('.tpm-layout').offset().top-50},250);
  });

  function parseImport(raw){
    let lines=raw.split(/\r?\n/).map(x=>x.trim()).filter(Boolean),d={};
    let val=(keys)=>{for(let l of lines){for(let k of keys){let r=new RegExp('^'+k+'\\s*[:\\-]?\\s*(.+)$','i'),m=l.match(r);if(m)return m[1].trim();}}return'';};
    d.sku=val(['SKU','Varenr\\.?','Varenummer','Item no\\.?','Part no\\.?']);
    d.ean=val(['EAN(?:\\s*\\/\\s*GTIN)?','GTIN','Stregkode']);
    d.brand=val(['Mærke','Brand','Producent','Manufacturer']);
    d.cost=val(['Indkøbspris(?: ekskl\\.? moms)?','Kostpris','Cost price','Dealer price']);
    d.price=val(['Salgspris(?: inkl\\.? moms)?','Vejl\\.? pris','Retail price','Pris']);
    d.name=val(['Produktnavn','Produkt','Navn','Title']);
    if(!d.name){let candidate=lines.find(l=>!/:/.test(l)&&l.length>5&&!/^https?:/i.test(l));if(candidate)d.name=candidate;}
    let urls=raw.match(/https?:\/\/[^\s"'<>]+/gi)||[];
    d.images=urls.filter(u=>/\.(jpe?g|png|webp)(\?.*)?$/i.test(u));
    return d;
  }
  $('.tpm-parse-import').on('click',function(){
    let d=parseImport($('#tpm-import-text').val()),found=[];
    [['name','Produktnavn'],['sku','SKU'],['ean','EAN'],['brand','Mærke'],['cost','Indkøbspris'],['price','Salgspris']].forEach(x=>{if(d[x[0]])found.push('<div><span>'+x[1]+'</span><strong>'+esc(d[x[0]])+'</strong></div>');});
    if(d.images.length)found.push('<div><span>Billeder</span><strong>'+d.images.length+' fundet</strong></div>');
    $('#tpm-import-preview').prop('hidden',false).html('<div class="tpm-import-found">'+(found.join('')||'<p>Ingen sikre felter fundet. Du kan stadig kopiere oplysningerne manuelt.</p>')+'</div><button type="button" class="button button-primary tpm-apply-import">Brug data</button>').data('parsed',d);
  });
  $(document).on('click','.tpm-apply-import',function(){
    let d=$('#tpm-import-preview').data('parsed')||{};
    if(d.name)$('[name=name]').val(d.name);if(d.sku)$('[name=sku]').val(d.sku);if(d.ean)$('[name=ean]').val(d.ean);if(d.brand)$('[name=brand]').val(d.brand);
    if(d.cost)$('#tpm-cost').val(String(d.cost).replace(/[^0-9,.-]/g,'').replace(',','.'));
    if(d.price)$('#tpm-price').val(String(d.price).replace(/[^0-9,.-]/g,'').replace(',','.'));
    if(d.images&&d.images.length){$('#tpm-main-url').val(d.images[0]);$('[name=gallery_image_urls]').val(d.images.slice(1).join('\n'));$('.tpm-preview-url').trigger('click');}
    calc();check();$('html,body').animate({scrollTop:$('.tpm-layout').offset().top-50},250);
  });

  $('#tpm-cost,#tpm-price').on('input',function(){calc();check();});
  $('[name=name],[name=category],#tpm-main-url').on('input change',check);
  $('.tpm-preview-url').on('click',function(){let u=$('#tpm-main-url').val().trim();if(u)$('#tpm-main-preview').html('<img src="'+esc(u)+'" alt="">');check();});
  $('.tpm-pick-main').on('click',function(){let f=wp.media({title:'Vælg hovedbillede',multiple:false,library:{type:'image'}});f.on('select',function(){let a=f.state().get('selection').first().toJSON();$('#tpm-image-id').val(a.id);$('#tpm-main-preview').html('<img src="'+(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url)+'">');check();});f.open();});
  $('.tpm-pick-gallery').on('click',function(){let f=wp.media({title:'Vælg produktgalleri',multiple:true,library:{type:'image'}});f.on('select',function(){let a=f.state().get('selection').toJSON();$('#tpm-gallery-ids').val(a.map(x=>x.id).join(','));$('#tpm-gallery-preview').html(a.map(x=>'<img src="'+(x.sizes&&x.sizes.thumbnail?x.sizes.thumbnail.url:x.url)+'">').join(''));});f.open();});
  $('.tpm-add-spec').on('click',function(){$('#tpm-specs').append('<div class="tpm-spec"><input name="spec_name[]" placeholder="Egenskab"><input name="spec_value[]" placeholder="Værdi"><button type="button" class="button tpm-remove-spec">×</button></div>');});
  $(document).on('click','.tpm-remove-spec',function(){$(this).closest('.tpm-spec').remove();});
  calc();check();
});

jQuery(function($){
 if(!$('#tpm-category-tree').length)return;
 const $form=$('#tpm-cat-form');
 function resetForm(){
  if(!$form.length)return;$form[0].reset();$form.find('[name=term_id]').val(0);$form.find('[name=parent]').val(0);$form.find('[name=mega]').prop('checked',true);$form.find('[name=thumbnail_id]').val(0);
  $('#tpm-cat-image-preview').html('<span>Intet billede</span>');$('#tpm-cat-editor-title').text('Ny kategori');$('.tpm-cat-danger').prop('hidden',true);$('.tpm-cat-message').text('');
 }
 function serializeTree(){
  let out=[];$('#tpm-category-tree .tpm-cat-level').each(function(){let parent=parseInt($(this).attr('data-parent'),10)||0;$(this).children('.tpm-cat-node').each(function(i){out.push({id:parseInt($(this).data('id'),10),parent:parent,order:i});});});return out;
 }
 let saveTimer;
 function saveStructure(){
  clearTimeout(saveTimer);$('.tpm-cat-save-state').text('Gemmer…');
  saveTimer=setTimeout(function(){$.post(TPM.ajax,{action:'tpm_cat_structure',nonce:TPM.catNonce,structure:JSON.stringify(serializeTree())},function(r){$('.tpm-cat-save-state').text(r.success?'Gemt ✓':'Kunne ikke gemme');if(!r.success&&r.data&&r.data.message)alert(r.data.message);});},180);
 }
 function initSortable(){
  if(!$.fn.sortable)return;
  $('.tpm-cat-level').sortable('destroy').sortable({
   connectWith:'.tpm-cat-level',items:'>.tpm-cat-node',handle:'.tpm-cat-handle',placeholder:'tpm-cat-placeholder',tolerance:'pointer',
   start:function(e,ui){ui.item.addClass('is-dragging');},
   stop:function(e,ui){ui.item.removeClass('is-dragging');$('.tpm-cat-level').each(function(){let $l=$(this),$owner=$l.closest('.tpm-cat-node');$l.attr('data-parent',$owner.length?$owner.data('id'):0);});saveStructure();}
  });
 }
 $(document).on('click','.tpm-cat-new,.tpm-cat-reset',resetForm);
 $(document).on('click','.tpm-cat-add-child',function(){resetForm();$form.find('[name=parent]').val($(this).data('id'));$('#tpm-cat-editor-title').text('Ny underkategori under '+$(this).data('name'));});
 $(document).on('click','.tpm-cat-edit',function(){
  $.post(TPM.ajax,{action:'tpm_cat_get',nonce:TPM.catNonce,id:$(this).data('id')},function(r){if(!r.success)return;let d=r.data;$form.find('[name=term_id]').val(d.id);$form.find('[name=name]').val(d.name);$form.find('[name=slug]').val(d.slug);$form.find('[name=parent]').val(d.parent);$form.find('[name=description]').val(d.description);$form.find('[name=mega]').prop('checked',!!d.mega);$form.find('[name=thumbnail_id]').val(d.thumbnail_id||0);$('#tpm-cat-image-preview').html(d.thumbnail_url?'<img src="'+d.thumbnail_url+'" alt="">':'<span>Intet billede</span>');$('#tpm-cat-editor-title').text('Redigér '+d.name);$('.tpm-cat-danger').prop('hidden',false);$('.tpm-cat-message').text('');});
 });
 $(document).on('submit','#tpm-cat-form',function(e){
  e.preventDefault();let data=$form.serializeArray();data.push({name:'action',value:'tpm_cat_save'},{name:'nonce',value:TPM.catNonce});$form.find('button[type=submit]').prop('disabled',true);
  $.post(TPM.ajax,$.param(data),function(r){if(r.success){$('.tpm-cat-message').text(r.data.message+' Genindlæser…');location.reload();}else $('.tpm-cat-message').text(r.data&&r.data.message?r.data.message:'Kunne ikke gemme.');}).always(function(){$form.find('button[type=submit]').prop('disabled',false);});
 });
 $(document).on('click','.tpm-cat-pick-image',function(){let frame=wp.media({title:'Vælg kategoribillede',multiple:false,library:{type:'image'}});frame.on('select',function(){let a=frame.state().get('selection').first().toJSON(),u=a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url;$form.find('[name=thumbnail_id]').val(a.id);$('#tpm-cat-image-preview').html('<img src="'+u+'" alt="">');});frame.open();});
 $(document).on('click','.tpm-cat-remove-image',function(){$form.find('[name=thumbnail_id]').val(0);$('#tpm-cat-image-preview').html('<span>Intet billede</span>');});
 $(document).on('click','.tpm-cat-delete',function(){let id=parseInt($form.find('[name=term_id]').val(),10)||0,name=$form.find('[name=name]').val();if(!id||!confirm('Slet kategorien “'+name+'”? Produkterne bliver ikke slettet.'))return;$.post(TPM.ajax,{action:'tpm_cat_delete',nonce:TPM.catNonce,id:id},function(r){if(r.success)location.reload();else alert(r.data&&r.data.message?r.data.message:'Kunne ikke slette kategorien.');});});
 $(document).on('click','.tpm-cat-collapse',function(){let $n=$(this).closest('.tpm-cat-node'),$l=$n.children('.tpm-cat-level');if(!$l.children().length)return;$l.toggleClass('is-collapsed');$(this).text($l.hasClass('is-collapsed')?'▸':'▾');});
 $('.tpm-cat-collapse-all').on('click',function(){$('#tpm-category-tree .tpm-cat-node>.tpm-cat-level').addClass('is-collapsed');$('#tpm-category-tree .tpm-cat-collapse:not(.is-empty)').text('▸');});
 $('.tpm-cat-expand-all').on('click',function(){$('#tpm-category-tree .tpm-cat-level').removeClass('is-collapsed');$('#tpm-category-tree .tpm-cat-collapse:not(.is-empty)').text('▾');});
 $('#tpm-cat-search').on('input',function(){let q=$(this).val().trim().toLowerCase();$('#tpm-category-tree .tpm-cat-node').each(function(){let $n=$(this),hit=!q||String($n.data('name')).toLowerCase().includes(q)||$n.find('>.tpm-cat-level>.tpm-cat-node').filter(function(){return String($(this).data('name')).toLowerCase().includes(q);}).length;$n.toggleClass('is-search-hidden',!hit);});if(q)$('.tpm-cat-level').removeClass('is-collapsed');});
 initSortable();
});
