(() => {
  'use strict';
  const choose=document.getElementById('sdr-logo-choose');if(!choose)return;
  const input=document.getElementById('sdr-logo-id'),preview=document.getElementById('sdr-logo-preview');
  let picker;
  choose.onclick=()=>{
    if(!picker){
      picker=wp.media({title:'Logo de la troupe',button:{text:'Utiliser ce logo'},library:{type:'image'},multiple:false});
      picker.on('select',()=>{const image=picker.state().get('selection').first().toJSON();input.value=image.id;preview.src=image.sizes?.medium?.url||image.url;preview.style.display='block';});
    }
    picker.open();
  };
  document.getElementById('sdr-logo-remove').onclick=()=>{input.value=-1;preview.removeAttribute('src');preview.style.display='none';};
})();
