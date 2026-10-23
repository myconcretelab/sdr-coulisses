(function(wp){
  'use strict';
  const cfg=window.SDREmailEditor;if(!cfg)return;
  const h=wp.element.createElement,{Fragment,useState,useEffect}=wp.element,{registerBlockType,createBlock,parse}=wp.blocks;
  const {InspectorControls,InnerBlocks,RichText,MediaUpload,MediaUploadCheck,useBlockProps}=wp.blockEditor;
  const {PanelBody,TextControl,TextareaControl,SelectControl,RangeControl,ToggleControl,CheckboxControl,ColorPalette,Button,Notice,Modal,Spinner,ComboboxControl}=wp.components;
  const {PluginDocumentSettingPanel}=wp.editor;
  const common={__next40pxDefaultSize:true,__nextHasNoMarginBottom:true};
  const labels={text:'Texte email',heading:'Titre email',section:'Section email',columns:'Colonnes email',column:'Colonne email',condition:'Section conditionnelle',image:'Image email',button:'Bouton email',event:'Informations du spectacle',needs:'Places encore recherchées',message:'Message libre', 'reply-note':'Note des liens de réponse',divider:'Séparateur email',spacer:'Espace email'};
  const icons={text:'editor-paragraph',heading:'heading',section:'layout',columns:'columns',column:'columns',condition:'filter',image:'format-image',button:'button',event:'calendar-alt',needs:'groups',message:'email', 'reply-note':'info',divider:'minus',spacer:'image-flip-vertical'};
  const containers=['section','column','columns','condition'];
  const allowed=Object.keys(cfg.schemas).filter(name=>name!=='column').map(name=>'sdr-email/'+name);
  const colors=[{name:'Encre',color:'#302e28'},{name:'Papier',color:'#fffdf6'},{name:'Mauve',color:'#79608f'},{name:'Mauve clair',color:'#eee7f4'},{name:'Vert',color:'#dce99f'},{name:'Vert clair',color:'#edf0da'},{name:'Pêche',color:'#f6e0d2'},{name:'Sable',color:'#f1eddf'}];
  const textControl=(label,value,onChange,extra={})=>h(TextControl,{...common,label,value:value??'',onChange,...extra});
  const selectControl=(label,value,options,onChange)=>h(SelectControl,{...common,label,value,options,onChange});
  function Color({label,value,onChange,transparent=false}){
    return h('div',{className:'sdr-email-color'},h('p',{},label),h(ColorPalette,{colors:transparent?[...colors,{name:'Transparent',color:'transparent'}]:colors,value,onChange:color=>color&&onChange(color),clearable:false,enableAlpha:false}));
  }
  function BlockEdit({name,attributes:a,setAttributes:set,clientId}){
    const count=wp.data.useSelect(select=>select('core/block-editor').getBlock(clientId)?.innerBlocks?.length||0,[clientId]);
    const controls=[];
    if(['text','heading'].includes(name)){
      controls.push(h(RangeControl,{...common,key:'size',label:'Taille du texte',value:a.fontSize,min:10,max:48,onChange:v=>set({fontSize:v})}));
      controls.push(h(Color,{key:'color',label:'Couleur du texte (héritée si non choisie)',value:a.color,onChange:v=>set({color:v})}));
      if(a.color)controls.push(h(Button,{key:'inherit',variant:'tertiary',onClick:()=>set({color:''})},'Utiliser la couleur générale'));
      controls.push(selectControl('Alignement',a.align,['left','center','right'].map((value,i)=>({value,label:['Gauche','Centre','Droite'][i]})),v=>set({align:v})));
      if(name==='heading')controls.push(selectControl('Niveau du titre',String(a.level),[1,2,3].map(n=>({value:String(n),label:'Titre '+n})),v=>set({level:Number(v)})));
    }
    if(['section','column'].includes(name)){
      controls.push(h(Color,{key:'bg',label:'Fond',value:a.background,onChange:v=>set({background:v}),transparent:true}));
      controls.push(h(RangeControl,{...common,key:'padding',label:'Espacement intérieur',value:a.padding,min:0,max:48,onChange:v=>set({padding:v})}));
      if(name==='section')controls.push(h(Fragment,{key:'border'},h(RangeControl,{...common,label:'Épaisseur de bordure',value:a.borderWidth,min:0,max:6,onChange:v=>set({borderWidth:v})}),h(Color,{label:'Couleur de bordure',value:a.borderColor,onChange:v=>set({borderColor:v}),transparent:true}),h(RangeControl,{...common,label:'Arrondi',value:a.radius,min:0,max:30,onChange:v=>set({radius:v})})));
    }
    if(name==='condition')controls.push(h(Fragment,{key:'rules'},h('p',{},'Ces conditions sont évaluées pour chaque destinataire au moment de l’envoi.'),selectControl('Correspondance',a.mode,[{value:'all',label:'Toutes les conditions (ET)'},{value:'any',label:'Au moins une condition (OU)'}],v=>set({mode:v})),h('div',{className:'sdr-email-rule-list'},...Object.entries(cfg.conditions).map(([value,label])=>h(CheckboxControl,{key:value,label,checked:(a.rules||[]).includes(value),onChange:checked=>set({rules:checked?[...a.rules,value]:a.rules.filter(r=>r!==value)})}))),h(ToggleControl,{...common,label:'Inverser le résultat',checked:a.invert,onChange:v=>set({invert:v})})));
    if(name==='columns')controls.push(h(Fragment,{key:'cols'},h('p',{},'Une à trois colonnes. Elles s’empilent sur les petits écrans dans les messageries compatibles.'),h(RangeControl,{...common,label:'Espacement des colonnes',value:a.gap,min:0,max:30,onChange:v=>set({gap:v})})));
    if(name==='button')controls.push(h(Fragment,{key:'button'},textControl('Texte du bouton',a.label,v=>set({label:v})),selectControl('Destination',a.target,[['reply_yes','Réponse : je me lance'],['reply_no','Réponse : pas disponible'],['coulisses','Coulisses du spectacle'],['profile','Profil du membre'],['event','Fiche publique du spectacle'],['booking','Billetterie'],['custom','Adresse personnalisée']].map(([value,label])=>({value,label})),v=>set({target:v})),a.target==='custom'?textControl('Adresse (variables acceptées)',a.url,v=>set({url:v})):null,h(Color,{label:'Fond du bouton',value:a.background,onChange:v=>set({background:v})}),h(Color,{label:'Couleur du texte (héritée si non choisie)',value:a.color,onChange:v=>set({color:v})}),h(RangeControl,{...common,label:'Taille du texte',value:a.fontSize,min:10,max:48,onChange:v=>set({fontSize:v})}),h(RangeControl,{...common,label:'Arrondi',value:a.radius,min:0,max:30,onChange:v=>set({radius:v})})));
    if(name==='button')controls.push(selectControl('Alignement',a.align,[{value:'left',label:'Gauche'},{value:'center',label:'Centre'},{value:'right',label:'Droite'}],v=>set({align:v})));
    if(name==='image')controls.push(h(Fragment,{key:'image'},h(MediaUploadCheck,{},h(MediaUpload,{allowedTypes:['image'],value:a.id,onSelect:media=>set({id:media.id,url:media.url,alt:media.alt||''}),render:({open})=>h(Button,{variant:'secondary',onClick:open},'Choisir une image')})),textControl('Adresse de l’image',a.url,v=>set({url:v,id:0})),textControl('Texte alternatif',a.alt,v=>set({alt:v})),h(RangeControl,{...common,label:'Largeur maximale',value:a.width,min:50,max:800,onChange:v=>set({width:v})}),selectControl('Alignement',a.align,[{value:'left',label:'Gauche'},{value:'center',label:'Centre'},{value:'right',label:'Droite'}],v=>set({align:v}))));
    if(name==='event')controls.push(h(Fragment,{key:'event'},...['spectacle','date','lieu','adresse','ville','heure','rdv','format','troupe'].map(field=>h(CheckboxControl,{key:field,label:cfg.variables[field],checked:a.fields.includes(field),onChange:checked=>set({fields:checked?[...a.fields,field]:a.fields.filter(f=>f!==field)})})),h(Color,{label:'Fond du rendez-vous',value:a.background,onChange:v=>set({background:v})})));
    if(name==='needs')controls.push(h(Fragment,{key:'needs'},selectControl('Besoins affichés',a.scope,[{value:'recipient',label:'Rôles proposés à cette personne'},{value:'all',label:'Tous les rôles encore recherchés'}],v=>set({scope:v})),h(Color,{label:'Fond',value:a.background,onChange:v=>set({background:v})})));
    if(name==='divider')controls.push(h(Color,{key:'line',label:'Couleur',value:a.color,onChange:v=>set({color:v})}));
    if(name==='spacer')controls.push(h(RangeControl,{...common,key:'height',label:'Hauteur',value:a.height,min:0,max:100,onChange:v=>set({height:v})}));
    const props=useBlockProps({className:'sdr-email-block sdr-email-'+name,style:['section','column'].includes(name)?{backgroundColor:a.background,padding:a.padding,border:name==='section'?a.borderWidth+'px solid '+a.borderColor:undefined,borderRadius:a.radius}:undefined});
    let content;
    if(['text','heading'].includes(name))content=h(RichText,{tagName:name==='heading'?'h'+a.level:'p',value:a.content,placeholder:name==='heading'?'Un titre, avec {{variables}}…':'Ton texte, avec {{prenom}} ou {{spectacle}}…',onChange:v=>set({content:v}),allowedFormats:['core/bold','core/italic','core/link','core/underline','core/code'],style:{color:a.color,fontSize:a.fontSize,textAlign:a.align},onSplit:value=>createBlock('sdr-email/text',{content:value})});
    else if(containers.includes(name)){
      const list=name==='columns'?['sdr-email/column']:allowed;
      const inner=h(InnerBlocks,{allowedBlocks:list,template:name==='columns'?[['sdr-email/column'],['sdr-email/column']]:undefined,renderAppender:name==='columns'&&count>=3?false:InnerBlocks.ButtonBlockAppender});
      content=h(Fragment,{},h('div',{className:'sdr-email-block-label'},name==='condition'?(a.invert?'Sauf si ':'Si ')+(a.rules||[]).map(key=>cfg.conditions[key]||key).join(a.mode==='all'?' ET ':' OU '):labels[name]),h('div',{className:name==='columns'?'sdr-email-columns-inner':'sdr-email-children',style:name==='columns'?{'--email-columns':Math.max(1,count),gap:a.gap}:undefined},inner));
    }else if(name==='button')content=h('div',{className:'sdr-email-button-preview',style:{background:a.background,color:a.color,borderRadius:a.radius,fontSize:a.fontSize,textAlign:a.align}},a.label||'Texte du bouton');
    else if(name==='image')content=a.url?h('div',{style:{textAlign:a.align}},h('img',{src:a.url,alt:a.alt,style:{width:'100%',maxWidth:a.width,height:'auto'}})):h('div',{className:'sdr-email-placeholder'},'Choisis une image dans les réglages du bloc.');
    else if(name==='event')content=h('div',{className:'sdr-email-placeholder',style:{background:a.background}},h('strong',{},'LE RENDEZ-VOUS'),h('p',{},a.fields.map(field=>'{{'+field+'}}').join(' · ')));
    else if(name==='needs')content=h('div',{className:'sdr-email-placeholder',style:{background:a.background}},'Encore à trouver : ',h('strong',{},a.scope==='all'?'{{besoins_manquants}}':'{{besoins_cibles}}'));
    else if(name==='message')content=h('div',{className:'sdr-email-placeholder'},h('strong',{},'Message libre personnalisé'),h('p',{},'Le texte rédigé dans « Relances utilisateurs » sera inséré ici pour chaque destinataire.'));
    else if(name==='reply-note')content=h('div',{className:'sdr-email-functional-note'},'Note fonctionnelle : confirmation nécessaire, lien personnel, date d’expiration et choix « OK pour la scène ». Elle s’affiche seulement lorsque les réponses sont possibles.');
    else if(name==='divider')content=h('hr',{style:{border:0,borderBottom:'1px dashed '+a.color}});
    else if(name==='spacer')content=h('div',{className:'sdr-email-spacer-preview',style:{height:a.height}},a.height+' px');
    return h(Fragment,{},controls.length?h(InspectorControls,{},h(PanelBody,{title:labels[name],initialOpen:true},...controls)):null,h('div',props,content));
  }
  Object.entries(cfg.schemas).forEach(([name,attributes])=>registerBlockType('sdr-email/'+name,{apiVersion:3,title:labels[name],description:'Bloc de mise en page compatible avec les emails SDR.',icon:icons[name],category:'design',attributes,parent:name==='column'?['sdr-email/columns']:undefined,supports:{html:false,reusable:false,customClassName:false,anchor:false},edit:props=>h(BlockEdit,{...props,name}),save:()=>containers.includes(name)?h(InnerBlocks.Content):null,transforms:['text','heading'].includes(name)?{from:[{type:'block',blocks:[name==='text'?'core/paragraph':'core/heading'],transform:attrs=>createBlock('sdr-email/'+name,{content:attrs.content||'',...(name==='heading'?{level:Math.min(3,attrs.level||2)}:{})})}]}:undefined}));
  if(wp.blocks.setDefaultBlockName)wp.blocks.setDefaultBlockName('sdr-email/text');

  function EmailPanels(){
    const editor=wp.data.useSelect(select=>{const s=select('core/editor');return {id:s.getCurrentPostId(),meta:s.getEditedPostAttribute('meta')||{},content:s.getEditedPostContent(),status:s.getEditedPostAttribute('status'),dirty:s.isEditedPostDirty()};},[]);
    const editMeta=patch=>wp.data.dispatch('core/editor').editPost({meta:{...editor.meta,...patch}});
    const kind=editor.meta._sdr_email_kind||'reminder',style={background:'#eee9de',surface:'#fffdf6',color:'#302e28',font:'Arial',width:600,padding:24,...editor.meta._sdr_email_style};
    const [event,setEvent]=useState('0'),[user,setUser]=useState('0'),[scenario,setScenario]=useState('missing_mc'),[busy,setBusy]=useState(false),[preview,setPreview]=useState(null),[previewHash,setPreviewHash]=useState(''),[modal,setModal]=useState(false),[mobile,setMobile]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState(''),[sent,setSent]=useState(false),[testId,setTestId]=useState(''),[active,setActive]=useState(Number(cfg.active[kind])===editor.id);
    const payload={template:editor.id,kind,content:editor.content,subject:editor.meta._sdr_email_subject||'',preheader:editor.meta._sdr_email_preheader||'',style,event:Number(event),user:Number(user),scenario};
    const hash=JSON.stringify(payload),fresh=preview&&previewHash===hash;
    useEffect(()=>{setActive(Number(cfg.active[kind])===editor.id);},[kind,editor.id]);
    const setStyle=patch=>editMeta({_sdr_email_style:{...style,...patch}});
    const runPreview=async()=>{setBusy(true);setError('');setMessage('');try{const result=await wp.apiFetch({path:'/sdr/v1/email-templates/preview',method:'POST',data:payload});setPreview(result);setPreviewHash(hash);setTestId('email-preview-'+Date.now()+'-'+Math.random().toString(36).slice(2));setSent(false);setModal(true);}catch(e){setError(e.message||'Aperçu indisponible.');}finally{setBusy(false);}};
    const sendTest=async()=>{if(!fresh||busy||sent)return;setBusy(true);setError('');try{const result=await wp.apiFetch({path:'/sdr/v1/email-templates/test',method:'POST',data:{...payload,requestId:testId}});setMessage(result.message);setSent(true);}catch(e){setError(e.message||'Envoi de l’aperçu impossible.');}finally{setBusy(false);}};
    const activate=async()=>{setBusy(true);setError('');try{const result=await wp.apiFetch({path:'/sdr/v1/email-templates/activate',method:'POST',data:{template:editor.id}});cfg.active[kind]=editor.id;setActive(true);setMessage(result.message);}catch(e){setError(e.message||'Activation impossible.');}finally{setBusy(false);}};
    const reset=()=>{if(!window.confirm('Remplacer les blocs, l’objet et la présentation par le modèle de départ de ce type ? Tu pourras annuler dans l’éditeur.'))return;const base=cfg.defaults[kind],blocks=wp.data.select('core/block-editor').getBlocks();if(blocks.length)wp.data.dispatch('core/block-editor').replaceBlocks(blocks.map(b=>b.clientId),parse(base.content));else wp.data.dispatch('core/block-editor').insertBlocks(parse(base.content));editMeta({_sdr_email_subject:base.subject,_sdr_email_preheader:base.preheader,_sdr_email_style:base.style});setPreview(null);setMessage('Modèle de départ chargé. Enregistre tes modifications avant de l’utiliser.');};
    const notices=h(Fragment,{},error?h(Notice,{status:'error',isDismissible:false},error):null,message?h(Notice,{status:'success',isDismissible:false},message):null);
    return h(Fragment,{},h(PluginDocumentSettingPanel,{name:'sdr-email-content',title:'Email · Contenu et usage',className:'sdr-email-panel'},
      selectControl('Type de message',kind,Object.entries(cfg.kinds).map(([value,label])=>({value,label})),v=>editMeta({_sdr_email_kind:v})),
      textControl('Objet de l’email',editor.meta._sdr_email_subject,v=>editMeta({_sdr_email_subject:v})),
      h(TextareaControl,{...common,label:'Aperçu dans la boîte de réception',value:editor.meta._sdr_email_preheader||'',onChange:v=>editMeta({_sdr_email_preheader:v}),rows:3}),
      h('p',{className:'sdr-email-help'},'Publier ce modèle n’envoie aucun message. Les modèles globaux sont réservés aux administrateurs. Les campagnes en cours conservent leur version.'),
      h(Button,{variant:'secondary',onClick:reset,disabled:busy},'Charger le modèle de départ'),
      h(Button,{variant:'primary',onClick:activate,disabled:busy||editor.status!=='publish'||editor.dirty||active},active?'Modèle utilisé':'Utiliser pour les nouveaux envois'),
      editor.dirty||editor.status!=='publish'?h('p',{className:'sdr-email-help'},'Enregistre et publie un modèle valide pour pouvoir l’utiliser.'):null,
      h('a',{href:cfg.settingsUrl},'Voir les modèles utilisés ↗')),
      h(PluginDocumentSettingPanel,{name:'sdr-email-style',title:'Email · Présentation'},
        h(Color,{label:'Fond extérieur',value:style.background,onChange:v=>setStyle({background:v})}),h(Color,{label:'Papier du message',value:style.surface,onChange:v=>setStyle({surface:v})}),h(Color,{label:'Texte courant',value:style.color,onChange:v=>setStyle({color:v})}),
        selectControl('Police du message',style.font,['Arial','Georgia','Verdana','Tahoma'].map(value=>({value,label:value})),v=>setStyle({font:v})),
        h(RangeControl,{...common,label:'Largeur maximale',value:style.width,min:320,max:800,onChange:v=>setStyle({width:v})}),h(RangeControl,{...common,label:'Marges intérieures',value:style.padding,min:0,max:48,onChange:v=>setStyle({padding:v})})),
      h(PluginDocumentSettingPanel,{name:'sdr-email-variables',title:'Email · Variables disponibles'},h('p',{},'Écris {{variable}} dans les textes, titres ou l’objet. Une valeur de secours s’écrit {{lieu|Lieu à préciser}}.'),h('div',{className:'sdr-email-variable-list'},...Object.entries(cfg.variables).map(([key,label])=>h('div',{key},h('code',{},'{{'+key+'}}'),h('span',{},label))))),
      h(PluginDocumentSettingPanel,{name:'sdr-email-preview',title:'Email · Aperçu et essais'},
        h(ComboboxControl,{...common,label:'Spectacle',value:event,options:[{value:'0',label:'Données fictives'},...cfg.events.map(e=>({value:String(e.id),label:e.label}))],onChange:v=>setEvent(v||'0')}),
        event==='0'?selectControl('Situation fictive',scenario,Object.entries(cfg.scenarios).map(([value,label])=>({value,label})),setScenario):null,
        h(ComboboxControl,{...common,label:'Personne de l’aperçu',value:user,options:[{value:'0',label:'Camille Exemple'},...cfg.users.map(u=>({value:String(u.id),label:u.name}))],onChange:v=>setUser(v||'0')}),
        h(Button,{variant:'secondary',onClick:runPreview,disabled:busy},busy?h(Spinner):'Prévisualiser cet email'),
        preview&&!fresh?h('p',{className:'sdr-email-help'},'Le contenu a changé : actualise l’aperçu.'):null,
        h(Button,{variant:'secondary',onClick:sendTest,disabled:busy||!fresh||sent},sent?'Aperçu transmis':'M’envoyer cet aperçu'),
        fresh?h('p',{className:'sdr-email-help'},'Uniquement à '+preview.testRecipient+'. Les boutons de réponse sont inactifs.'):null,notices),
      modal&&preview?h(Modal,{title:'Aperçu de l’email',onRequestClose:()=>setModal(false),className:'sdr-email-preview-modal'},h('div',{className:'sdr-email-preview-tools'},h(Button,{variant:mobile?'secondary':'primary',onClick:()=>setMobile(false)},'Ordinateur'),h(Button,{variant:mobile?'primary':'secondary',onClick:()=>setMobile(true)},'Mobile'),h(Button,{variant:'secondary',onClick:sendTest,disabled:busy||!fresh||sent},sent?'Aperçu transmis':'M’envoyer cet aperçu')),h('p',{},h('strong',{},'Objet : '),preview.subject),...preview.warnings.map((warning,i)=>h(Notice,{key:i,status:'warning',isDismissible:false},warning)),!fresh?h(Notice,{status:'warning',isDismissible:false},'Cet aperçu doit être actualisé.'):null,notices,h('div',{className:'sdr-email-preview-scroll'},h('iframe',{title:'Email rendu',sandbox:'',referrerPolicy:'no-referrer',srcDoc:preview.html,style:{width:mobile?375:Math.min(850,style.width+60),maxWidth:'100%',height:'65vh',border:0}})),h('details',{},h('summary',{},'Version texte'),h('pre',{className:'sdr-email-plain'},preview.plain))):null);
  }
  wp.plugins.registerPlugin('sdr-email-settings',{render:EmailPanels,icon:'email-alt'});
})(window.wp);
