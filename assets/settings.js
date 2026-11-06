(() => {
  'use strict';
  const copy=document.getElementById('sdr-copy-report');
  if(copy)copy.onclick=async()=>{
    const report=document.getElementById('sdr-setup-report'),status=document.getElementById('sdr-copy-status');
    try{if(!navigator.clipboard?.writeText)throw new Error();await navigator.clipboard.writeText(report.value);status.textContent='Rapport copié. Colle-le dans ton signalement.';}
    catch{report.focus();report.select();status.textContent='Rapport sélectionné. Utilise Copier (Ctrl+C ou ⌘C), puis colle-le dans ton signalement.';}
  };
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
