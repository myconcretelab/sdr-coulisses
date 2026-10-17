(function(wp){
    'use strict';
    const el=wp.element.createElement, {useState,useEffect,Fragment}=wp.element;
    const {PanelBody,TextControl,TextareaControl,ComboboxControl,ToggleControl,Button,Notice,Spinner}=wp.components;
    const {PluginSidebar,PluginSidebarMoreMenuItem,PluginDocumentSettingPanel}=wp.editor;
    const names={sdr_event:'Spectacle',sdr_venue:'Lieu',sdr_team:'Troupe invitée',sdr_format:'Format'};
    const roleNames={joueur:'Joueur·se',mc:'MC',dj:'DJ'};
    const common={__next40pxDefaultSize:true,__nextHasNoMarginBottom:true};

    function Relation({label,type,value,onChange}){
        const [options,setOptions]=useState([]),[loading,setLoading]=useState(true),[error,setError]=useState(''),[refresh,setRefresh]=useState(0);
        useEffect(()=>{
            let cancelled=false;
            setLoading(true);setError('');
            (async()=>{
                if(!SDREditor.manageRelations){
                    const result=await wp.apiFetch({path:'/sdr/v1/event-options'});
                    const key={sdr_venue:'venues',sdr_team:'teams',sdr_format:'formats'}[type];
                    if(!cancelled)setOptions(result[key].map(row=>({value:String(row.id),label:row.name})));
                    return;
                }
                let all=[],page=1;
                while(true){
                    const response=await wp.apiFetch({path:'/wp/v2/'+type+'?context=edit&status=publish,draft,pending,future,private&per_page=100&page='+page+'&orderby=title&order=asc&_fields=id,title,status',parse:false});
                    const rows=await response.json();all=all.concat(rows);
                    if(page>=Number(response.headers.get('X-WP-TotalPages')||1))break;
                    page++;
                }
                if(!cancelled)setOptions(all.map(row=>({value:String(row.id),label:wp.htmlEntities.decodeEntities(row.title.raw||row.title.rendered||'Sans titre')+(row.status==='publish'?'':' · non publié')})));
            })().catch(()=>{if(!cancelled)setError('Impossible de charger les fiches. Réessaie.');}).finally(()=>{if(!cancelled)setLoading(false);});
            return ()=>{cancelled=true;};
        },[type,refresh]);
        const selected=String(value||0);
        const choices=[{value:'0',label:'À préciser'},...options];
        if(value&&!options.some(option=>option.value===selected))choices.push({value:selected,label:'Fiche liée n°'+value+(loading?' · chargement…':' · indisponible')});
        return el('div',{className:'sdr-editor-relation'},
            el(ComboboxControl,{...common,label,value:selected,options:choices,onChange:v=>onChange(Number(v)||0),help:loading?'Chargement des fiches…':undefined}),
            loading&&el(Spinner),
            error&&el(Notice,{status:'warning',isDismissible:false},error),
            el('div',{className:'sdr-editor-links'},
                SDREditor.manageRelations&&el(Button,{variant:'link',href:SDREditor.admin+'post-new.php?post_type='+type,target:'_blank',rel:'noopener noreferrer'},'Créer une fiche ↗'),
                el(Button,{variant:'link',onClick:()=>setRefresh(n=>n+1),disabled:loading},'Actualiser')
            )
        );
    }

    function Settings(){
        const {type,id,status,meta,excerpt}=wp.data.useSelect(select=>{
            const editor=select('core/editor');
            return {type:editor.getCurrentPostType(),id:editor.getCurrentPostId(),status:editor.getEditedPostAttribute('status'),meta:editor.getEditedPostAttribute('meta')||{},excerpt:editor.getEditedPostAttribute('excerpt')||''};
        },[]);
        const {editPost}=wp.data.useDispatch('core/editor');
        if(!names[type])return null;
        const get=key=>meta['_sdr_'+key]??'';
        const set=(key,value)=>editPost({meta:{...(wp.data.select('core/editor').getEditedPostAttribute('meta')||{}),['_sdr_'+key]:value}});
        const text=(key,label,kind='text',help)=>el(TextControl,{...common,key,label,type:kind,value:get(key),help,onChange:value=>set(key,value)});
        const group=(title,children,open=true)=>el(PanelBody,{title,initialOpen:open},children);
        const relation=(key,label,postType)=>el(Relation,{key,label,type:postType,value:get(key),onChange:value=>set(key,value)});
        const openSidebar=()=>wp.data.dispatch('core/interface').enableComplementaryArea('core','sdr-editor/sdr-settings');
        const panels=[];
        if(type==='sdr_event'){
            panels.push(group('Date et horaires',[
                text('date','Date du spectacle','date','La date de la représentation, indépendante de la date de publication WordPress.'),
                text('time','Début du spectacle','time'),
                text('call_time','Rendez-vous des membres','time'),
                el(ToggleControl,{...common,key:'intermission',label:'Avec entracte',checked:!!get('intermission'),onChange:value=>set('intermission',value),help:'Indique au public si une pause est prévue pendant le spectacle.'})
            ]));
            panels.push(group('Lieu, troupe et format',[
                relation('venue','Lieu','sdr_venue'),relation('team','Troupe invitée','sdr_team'),relation('format','Format','sdr_format')
            ]));
            panels.push(group('Équipe et inscriptions',[
                el('p',{key:'help',className:'sdr-editor-help'},'Les places concernent les SDR, hors troupe invitée.'),
                ...Object.entries(roleNames).map(([key,label])=>{
                    const count=SDREditor.selected[key]||0,value=Number(get('capacity_'+key))||0;
                    return el('div',{key,className:'sdr-editor-capacity'},
                        el(TextControl,{...common,label,type:'number',min:count,max:50,value,help:count+' déjà retenu'+(count>1?'·es':'·e'),onChange:next=>set('capacity_'+key,Math.max(count,Math.min(50,Math.trunc(Number(next)||0))))}),
                        el('div',{className:'sdr-editor-stepper'},
                            el(Button,{variant:'secondary',disabled:value<=count,onClick:()=>set('capacity_'+key,value-1),'aria-label':'Retirer une place '+label},'−'),
                            el(Button,{variant:'secondary',disabled:value>=50,onClick:()=>set('capacity_'+key,value+1),'aria-label':'Ajouter une place '+label},'+')
                        )
                    );
                }),
                el(ToggleControl,{...common,key:'closed',label:'Fermer les réponses',checked:!!get('closed'),onChange:value=>set('closed',value),help:'Empêche les membres de modifier leur disponibilité.'}),
                id&&status==='publish'&&el(Button,{key:'compose',variant:'secondary',href:SDREditor.coulisses+'&spectacle='+id,target:'_blank',rel:'noopener noreferrer'},'Composer l’équipe ↗')
            ],false));
            panels.push(group('Billetterie',[text('booking_url','Lien de billetterie','url','Facultatif. Exemple : https://…')],false));
        }
        if(type==='sdr_venue'){
            panels.push(group('Adresse et informations publiques',[
                text('address','Adresse publique'),text('city','Commune'),text('website','Site internet','url')
            ]));
            panels.push(group('Contact privé',[
                el(Notice,{key:'private',status:'info',isDismissible:false},'Visible uniquement par les personnes autorisées à administrer cette fiche. Jamais affiché sur le site public.'),
                text('contact','Contact interne')
            ]));
        }
        if(type==='sdr_team'){
            panels.push(group('Informations de la troupe',[
                text('city','Ville'),text('website','Site internet','url')
            ]));
        }
        panels.push(group(type==='sdr_format'?'Présentation du format':'Présentation de la fiche',[
            el('p',{key:'content',className:'sdr-editor-help'},type==='sdr_format'?'Décris les règles et l’esprit de ce format dans le contenu de la page. Il pourra être choisi dans chaque spectacle.':'Rédige la description et ajoute les photos ou une galerie directement avec les blocs de l’éditeur.'),
            el(TextareaControl,{...common,key:'excerpt',label:'Résumé court',value:excerpt,onChange:value=>editPost({excerpt:value}),help:'Utilise le même résumé que le champ « Extrait » de WordPress.'}),
            el('p',{key:'image',className:'sdr-editor-help'},'L’image principale se choisit dans « Image mise en avant », dans les réglages de la fiche.')
        ],type==='sdr_format'));
        return el(Fragment,null,
            el(PluginDocumentSettingPanel,{name:'sdr-info',title:'Informations SDR',initialOpen:true},
                el('p',null,'Dates, liens et informations de cette fiche se règlent dans le panneau SDR.'),
                el(Button,{variant:'secondary',onClick:openSidebar},'Ouvrir les réglages SDR')
            ),
            el(PluginSidebarMoreMenuItem,{target:'sdr-settings',icon:'calendar-alt'},'Informations SDR'),
            el(PluginSidebar,{name:'sdr-settings',title:'SDR · '+names[type],icon:'calendar-alt',className:'sdr-editor-sidebar'},
                el('div',{className:'sdr-editor-intro'},el('strong',null,'✳ Les détails qui font la soirée'),el('p',null,'Ces champs sont enregistrés avec le bouton WordPress « Enregistrer » ou « Publier ».')),
                ...panels
            )
        );
    }
    wp.plugins.registerPlugin('sdr-editor',{render:Settings,icon:'calendar-alt'});
})(window.wp);
