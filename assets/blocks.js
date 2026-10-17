(function(wp){
  const {registerBlockType}=wp.blocks, {createElement:h}=wp.element;
  const {InspectorControls,useBlockProps}=wp.blockEditor;
  const {PanelBody,TextControl,SelectControl,RangeControl,ToggleControl}=wp.components;
  registerBlockType('sdr/agenda',{edit:function(){return h('div',useBlockProps({className:'sdr-block-preview'}),h('h3',{},'✳ Les prochaines sorties'),h('p',{},'Les spectacles à venir s’afficheront ici automatiquement.'));},save:function(){return null;}});
  registerBlockType('sdr/coulisses',{edit:function(){return h('div',useBlockProps({className:'sdr-block-preview'}),h('h3',{},'✳ Les coulisses de la troupe'),h('p',{},'Les membres connectés retrouveront ici leurs disponibilités, leur équipe et leur profil.'));},save:function(){return null;}});
  registerBlockType('sdr/trombinoscope',{
    edit:function({attributes:a,setAttributes}){
      const controls=h(InspectorControls,{},h(PanelBody,{title:'Présentation du Trombinoscope',initialOpen:true},
        h(TextControl,{label:'Titre',value:a.title,onChange:v=>setAttributes({title:v})}),
        h(SelectControl,{label:'Style des portraits',value:a.layout,options:[{label:'Cartes de troupe',value:'cards'},{label:'Polaroids décalés',value:'polaroid'}],onChange:v=>setAttributes({layout:v})}),
        h(RangeControl,{label:'Colonnes sur grand écran',value:a.columns,min:2,max:5,onChange:v=>setAttributes({columns:v})}),
        h(SelectControl,{label:'Ordre des membres',value:a.order,options:[{label:'Alphabétique',value:'name'},{label:'Mélangé à chaque visite',value:'random'}],onChange:v=>setAttributes({order:v})}),
        h(ToggleControl,{label:'Afficher les descriptions',checked:a.showDescription,onChange:v=>setAttributes({showDescription:v})}),
        h(ToggleControl,{label:'Afficher les « J’aime »',checked:a.showLikes,onChange:v=>setAttributes({showLikes:v})}),
        h(ToggleControl,{label:'Afficher les « J’aime pas »',checked:a.showDislikes,onChange:v=>setAttributes({showDislikes:v})}),
        h(ToggleControl,{label:'Inclure les organisateurs',checked:a.includeOrganizers,onChange:v=>setAttributes({includeOrganizers:v})})
      ));
      const props=useBlockProps({className:'sdr-roster-editor sdr-roster--'+a.layout,style:{'--sdr-roster-columns':a.columns}});
      const cards=['Un nom de scène','La personne aux accessoires','Le roi du retard'].map((name,i)=>{
        const details=[h('div',{className:'sdr-roster-kicker'},i===1?'ORGANISATION':'DANS LA TROUPE'),h('h3',{},name),h('p',{className:'sdr-roster-roles'},'Joueur·se · MC')];
        if(a.showDescription)details.push(h('p',{className:'sdr-roster-description'},'La bio de cette personne apparaîtra ici.'));
        if(a.showLikes)details.push(h('div',{className:'sdr-roster-tags sdr-roster-likes'},h('b',{},'J’aime'),'Les cafés, les rappels'));
        if(a.showDislikes)details.push(h('div',{className:'sdr-roster-tags sdr-roster-dislikes'},h('b',{},'J’aime pas'),'Les fins de spectacle'));
        return h('article',{className:'sdr-roster-card',key:name},h('div',{className:'sdr-roster-image'},h('span',{className:'sdr-roster-initial'},['A','B','C'][i]),h('span',{className:'sdr-roster-doodle'},['✳','〰','♫'][i])),h('div',{className:'sdr-roster-copy'},...details));
      });
      return h(wp.element.Fragment,{},controls,h('div',props,h('div',{className:'sdr-roster-editor-label'},'✳ TROMBINOSCOPE · APERÇU'),a.title?h('h2',{className:'sdr-roster-title'},a.title):null,h('div',{className:'sdr-roster-grid'},...cards)));
    },
    save:function(){return null;}
  });
})(window.wp);
