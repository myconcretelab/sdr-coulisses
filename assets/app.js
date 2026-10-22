(() => {
  'use strict';
  const root = document.getElementById('sdr-app'); if (!root) return;
  const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const eventTitle = value => {const title=String(value??''),separator=' VS ',at=title.indexOf(separator);return at<0?h(title):`${h(title.slice(0,at))} <span class="event-vs" aria-label="versus">VS</span> ${h(title.slice(at+separator.length))}`;};
  const roleNames = {joueur:'Joueur·se',mc:'MC',dj:'DJ'};
  const statusNames = {yes:'Partant·e !',maybe:'Si besoin',no:'Pas dispo'};
  const icons = {calendar:'▦',profile:'◉',team:'♧',arrow:'↗'};
  let data, view=new URLSearchParams(location.search).get('vue')==='archives'?'archives':new URLSearchParams(location.search).get('vue')==='profil'?'profile':'planning', filter='all', query='', dialogEvent=null, busy=false;
  let archiveData=null,archiveLoading=false,archiveError='',archiveSeason='all',archiveQuery='';
  const dateParts = date => { const d=new Date(date+'T12:00:00'); return {day:d.getDate(),month:d.toLocaleDateString('fr-FR',{month:'short'}).replace('.',''),weekday:d.toLocaleDateString('fr-FR',{weekday:'long'}),full:d.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'})}; };
  async function api(path, body) { const r=await fetch(SDR.api+path,{method:body?'POST':'GET',credentials:'same-origin',headers:{'X-WP-Nonce':SDR.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})}); let result; try {result=await r.json();}catch(e){throw new Error('Le serveur ne répond pas correctement. Réessaie.');} if(!r.ok) throw new Error(result.message || 'Impossible d’enregistrer.'); return result; }
  function toast(message, error=false) { let el=document.getElementById('sdr-toast'); if(!el){el=document.createElement('div'); el.id='sdr-toast';el.setAttribute('role','status');el.setAttribute('aria-live','polite');document.body.append(el);} el.className='toast '+(error?'error':'');el.textContent=(error?'!  ':'✓  ')+message;clearTimeout(el._timeout);el._timeout=setTimeout(()=>el.remove(),4500); }
  const logo = `<a class="brand" href="${h(SDR.home)}" aria-label="Les Sorties de Route, accueil"><img class="backstage-logo" src="${h(SDR.logo)}" alt="Les Sorties de Route" width="448" height="446"></a>`;
  function render() {
    const replied=data.events.filter(e=>e.mine).length, selected=data.events.filter(e=>e.mine?.selectedRole).length;
    root.innerHTML=`<div class="app-shell"><aside class="app-sidebar">${logo}<div class="sidebar-label">DANS LES COULISSES</div><nav aria-label="Espace membres"><button data-view="planning" class="nav-item ${view==='planning'?'active':''}"><span>▦</span> Le planning <b>${data.events.length}</b></button><button data-view="answers" class="nav-item ${view==='answers'?'active':''}"><span>☑</span> Mes réponses</button><button data-view="archives" class="nav-item ${view==='archives'?'active':''}" aria-label="Spectacles passés" title="Spectacles passés"><span aria-hidden="true">↶</span> Spectacles passés</button><button data-view="profile" class="nav-item ${view==='profile'?'active':''}"><span>◉</span> Mon profil</button>${data.organizer?`<div class="sidebar-label org-label">CÔTÉ ORGANISATION</div><button data-new-event class="nav-item" title="Nouveau spectacle" aria-label="Nouveau spectacle"><span aria-hidden="true">＋</span> Nouveau spectacle</button>${SDR.relances?`<button data-relances class="nav-item" aria-haspopup="dialog"><span aria-hidden="true">✉</span> Relances utilisateurs</button>`:''}${data.administrator?`<a class="nav-item nav-item-secondary" href="${h(SDR.admin)}edit.php?post_type=sdr_event"><span aria-hidden="true">⚙</span> Gérer les spectacles</a>`:''}`:''}</nav><div class="sidebar-poster"><span class="poster-star">✳</span><strong>Le meilleur plan ?<br>Ne pas en avoir.</strong><span>Sur scène, en tout cas.</span></div><a href="${h(SDR.home)}" class="back-site">↗ Le site de la troupe</a><div class="sidebar-user"><span class="avatar">${h(data.user.name[0])}</span><div><strong>${h(data.user.name)}</strong><small>${data.administrator?'Administrateur':data.organizer?'Organisation':'Membre de la troupe'}</small></div><a href="${h(SDR.logout)}" aria-label="Se déconnecter" title="Se déconnecter">↪</a></div></aside><main class="app-main"><header class="app-topbar"><span><span class="green-dot"></span> L’ESPACE DE LA TROUPE</span><div class="topbar-actions"><a class="admin-access" href="${h(data.administrator?SDR.admin:SDR.adminLogin)}">${data.administrator?'Administration ↗':'Connexion administrateur ↗'}</a><span class="season-pill">SAISON ${h(data.season.label)}</span></div><span class="mobile-brand">SDR ✳</span></header><div class="app-content">${view==='profile'?profileView():view==='archives'?archiveView():planningView(replied,selected)}</div><footer class="app-footer"><span>On prépare les dates. Le reste s’improvise.</span><span>Les Sorties de Route <b>✳</b></span></footer></main></div><dialog id="event-dialog" class="event-dialog" aria-labelledby="dialog-title"></dialog><dialog id="new-event-dialog" class="event-dialog new-event-dialog" aria-labelledby="new-event-title"></dialog>`;
    root.querySelector('[data-new-event]')?.addEventListener('click',()=>openCreateEvent());
    root.querySelector('[data-relances]')?.addEventListener('click',openRelances);
    wireEditActions(root);
    const resultCount=root.querySelector('#result-count'); if(resultCount) resultCount.textContent=filtered().length+' date(s)';
    root.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>{view=b.dataset.view;filter='all';query='';render();if(view==='archives')loadArchives();window.scrollTo(0,0);});
    root.querySelectorAll('[data-filter]').forEach(b=>b.onclick=()=>{filter=b.dataset.filter;render();});
    const search=root.querySelector('#event-search'); if(search) search.oninput=()=>{query=search.value;renderCards();};
    root.querySelectorAll('[data-event]').forEach(b=>b.onclick=()=>openEvent(Number(b.dataset.event)));
    wireComposeActions(root);
    wireArchives();
    root.querySelector('[data-retry-archives]')?.addEventListener('click',loadArchives);
    const seasonSelect=root.querySelector('#archive-season');if(seasonSelect)seasonSelect.onchange=()=>{archiveSeason=seasonSelect.value;renderArchiveCards();};
    const archiveSearch=root.querySelector('#archive-search');if(archiveSearch)archiveSearch.oninput=()=>{archiveQuery=archiveSearch.value;renderArchiveCards();};
    const playerSearch=root.querySelector('#season-player-search');
    if(playerSearch) playerSearch.oninput=()=>{
      const term=playerSearch.value.trim().toLocaleLowerCase('fr');
      let visible=0;
      root.querySelectorAll('.season-player-row').forEach(row=>{row.hidden=!row.dataset.name.includes(term);if(!row.hidden) visible++;});
      root.querySelector('#season-no-results').hidden=visible>0;
      root.querySelector('#season-player-count').textContent=visible+' / '+data.seasonStats.players.length+' joueur·ses';
    };
    const form=root.querySelector('#profile-form'); if(form) form.onsubmit=saveProfile;
    const photoForm=root.querySelector('#profile-photo-form'); if(photoForm) photoForm.onsubmit=saveProfilePhoto;
    root.querySelectorAll('[data-add-chip]').forEach(button=>button.onclick=()=>addProfileChip(button.dataset.addChip));
    root.querySelectorAll('[data-chip-input]').forEach(input=>input.onkeydown=e=>{if(e.key==='Enter'||e.key===','){e.preventDefault();addProfileChip(input.dataset.chipInput);}});
    root.querySelectorAll('[data-remove-chip]').forEach(button=>button.onclick=()=>button.closest('.profile-chip').remove());
    const passwordForm=root.querySelector('#password-form'); if(passwordForm){passwordForm.onsubmit=savePassword;passwordForm.querySelectorAll('[data-toggle-password]').forEach(button=>button.onclick=()=>{const input=passwordForm.elements[button.dataset.togglePassword],shown=input.type==='text';input.type=shown?'password':'text';button.textContent=shown?'◉':'×';button.setAttribute('aria-label',(shown?'Afficher':'Masquer')+' '+button.dataset.label);});}
    const dialog=root.querySelector('dialog'); dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close();}});
  }
  function openRelances(event) {
    if (!data.organizer || !SDR.relances) return;
    let dialog=document.getElementById('sdr-relances-dialog');
    if (!dialog) {
      dialog=document.createElement('dialog');dialog.id='sdr-relances-dialog';dialog.className='sdr-relances-dialog';dialog.setAttribute('aria-labelledby','sdr-relances-title');
      dialog.innerHTML=`<header class="sdr-relances-heading"><h2 id="sdr-relances-title">✉ Relances utilisateurs</h2><button type="button" class="sdr-relances-close" aria-label="Fermer les relances" autofocus>×</button></header><iframe title="Filtres et messages aux utilisateurs" src="${h(SDR.relances)}"></iframe>`;
      document.body.append(dialog);
      dialog.querySelector('button').addEventListener('click',()=>dialog.close());
      dialog.addEventListener('close',()=>{document.body.classList.remove('sdr-relances-open');dialog._opener?.focus();});
      window.addEventListener('message',message=>{
        if(message.origin===location.origin && message.source===dialog.querySelector('iframe').contentWindow && message.data?.type==='sdr-relances-close' && dialog.open)dialog.close();
      });
    }
    dialog._opener=event.currentTarget;
    dialog.showModal();document.body.classList.add('sdr-relances-open');
  }
  function planningView(replied,selected){return `<div class="page-heading"><div><div class="eyebrow">À TOI D’ENTRER EN SCÈNE</div><h1>${view==='answers'?'Mes petits engagements.':`Salut ${h(data.user.name.split(' ')[0])}<span class="wave">✳</span><br>On joue quand ?`}</h1><p>${view==='answers'?'Tes disponibilités et tes sélections, au même endroit.':'Un rôle, une date, une belle soirée. À toi de choisir.'}</p></div><div class="heading-sticker">L’imprévu,<br><strong>ça se prépare !</strong><span>↙</span></div></div><section class="stats-grid" aria-label="Résumé de la saison"><div class="stat-card lilac"><span>LES DATES À VENIR</span><strong>${String(data.events.length).padStart(2,'0')}<small>occasions de monter sur scène</small></strong><b>↗</b></div><div class="stat-card peach"><span>EN ATTENTE DE TOI</span><strong>${String(data.events.length-replied).padStart(2,'0')}<small>${data.events.length-replied?'petites réponses à donner':'tout est à jour, bravo !'}</small></strong><b>☀</b></div><div class="stat-card lime"><span>TON ÉQUIPE T’ATTEND</span><strong>${String(selected).padStart(2,'0')}<small>date${selected>1?'s':''} où tu es sélectionné·e</small></strong><b>✳</b></div></section>${view==='planning'?seasonChart():''}<div class="planning-toolbar"><div class="filter-group" aria-label="Filtrer les dates">${[['all','Toutes les dates'],['pending','À répondre'],['yes','Mes dispos']].map(([k,label])=>`<button data-filter="${k}" class="filter-chip ${filter===k?'active':''}" aria-pressed="${filter===k}">${label}${k==='pending'?` <span>${data.events.length-replied}</span>`:''}</button>`).join('')}</div><label class="search-box"><span>⌕</span><input id="event-search" type="search" placeholder="Un lieu, un format…" aria-label="Rechercher une date" value="${h(query)}"></label></div><div class="section-overline"><h2>Le prochain grand n’importe quoi.</h2><span id="result-count"></span></div><div id="event-cards" class="event-grid">${cardsHTML()}</div><div class="small-note"><span>♡</span> Une disponibilité n’est pas une sélection : l’organisation compose ensuite les équipes.</div>`;}
  async function loadArchives(){
    if(archiveLoading) return;
    archiveLoading=true;archiveError='';if(view==='archives')render();
    try{archiveData=await api('archives');}
    catch(error){archiveError=error.message;}
    finally{archiveLoading=false;if(view==='archives')render();}
  }
  function archiveView(){
    const heading=`<div class="page-heading"><div><div class="eyebrow">ON Y ÉTAIT, ON A JOUÉ</div><h1>Les belles soirées<br>restent en mémoire<span class="wave">✳</span></h1><p>Les spectacles passés, leurs infos et les équipes qui ont partagé la scène.</p></div><div class="heading-sticker">C’était pas écrit.<br><strong>Mais c’est arrivé !</strong><span>↶</span></div></div>`;
    if(archiveLoading || (!archiveData&&!archiveError))return heading+'<p class="archive-message" role="status">On ressort les souvenirs…</p>';
    if(archiveError)return heading+`<div class="empty-state"><h2>Les souvenirs se font attendre.</h2><p>${h(archiveError)}</p><button class="button secondary" data-retry-archives>Réessayer</button></div>`;
    if(!archiveData.events.length)return heading+'<div class="empty-state"><span aria-hidden="true">↶</span><h2>Les souvenirs commencent bientôt.</h2><p>Chaque spectacle apparaîtra ici dès le lendemain de sa date.<br>Ses informations et son équipe resteront consultables.</p></div>';
    return heading+`<div class="archive-toolbar"><label class="archive-season">Saison<select id="archive-season"><option value="all">Toutes les saisons</option>${archiveData.seasons.map(season=>`<option value="${h(season.id)}" ${archiveSeason===season.id?'selected':''}>${h(season.label)}</option>`).join('')}</select></label><label class="search-box"><span aria-hidden="true">⌕</span><input id="archive-search" type="search" placeholder="Un spectacle, un lieu…" aria-label="Rechercher un spectacle passé" value="${h(archiveQuery)}"></label></div><div class="section-overline"><h2>Ça, c’est déjà dans la boîte.</h2><span id="archive-count" aria-live="polite"></span></div><div id="archive-cards" class="event-grid">${archiveCards()}</div><p class="small-note"><span aria-hidden="true">♡</span>Les souvenirs se consultent. Les disponibilités et la composition des équipes sont fermées.</p>`;
  }
  function filteredArchives(){
    const term=archiveQuery.trim().toLocaleLowerCase('fr');
    return (archiveData?.events||[]).filter(event=>(archiveSeason==='all'||event.season===archiveSeason)&&`${event.title} ${event.venue} ${event.city} ${event.format} ${event.team} ${dateParts(event.date).full}`.toLocaleLowerCase('fr').includes(term));
  }
  function archiveCards(){
    const events=filteredArchives();
    if(!events.length)return '<div class="empty-state"><span aria-hidden="true">↶</span><h3>Aucun souvenir par ici.</h3><p>Essaie une autre saison ou un autre mot.</p></div>';
    return events.map((event,i)=>{
      const date=dateParts(event.date),team=event.responses.filter(person=>person.selectedRole);
      return `<article class="match-card archive-card color-${i%3}"><div class="card-top"><span class="tag">${h(event.format)}</span><span class="archive-past-label">✓ Spectacle passé</span></div><div class="card-date"><strong>${String(date.day).padStart(2,'0')}</strong><div><span>${h(date.month)} <small>${event.date.slice(0,4)}</small></span><p>${h(date.weekday)}${event.time?' · '+h(event.time.replace(':','h')):''}</p></div><span class="card-doodle" aria-hidden="true">${['↶','✳','〰'][i%3]}</span></div><h3>${eventTitle(event.title)}</h3><p class="card-location">⌖ ${h(event.venue)}${event.city?' · '+h(event.city):''}</p><div class="card-foot"><div class="volunteers">${team.length?`<div class="avatar-stack" aria-hidden="true">${team.slice(0,3).map(person=>`<span>${h([...person.name][0])}</span>`).join('')}</div><span>${team.length} dans l’équipe</span>`:'<span>Équipe non renseignée</span>'}</div><button class="card-action" data-archive-event="${event.id}" aria-label="Revoir ${h(event.title)} du ${h(date.full)}">Revoir la soirée <span aria-hidden="true">↗</span></button></div></article>`;
    }).join('');
  }
  function wireArchives(){
    root.querySelectorAll('[data-archive-event]').forEach(button=>button.onclick=()=>openArchivedEvent(Number(button.dataset.archiveEvent)));
    const count=root.querySelector('#archive-count');if(count){const n=filteredArchives().length;count.textContent=n+' spectacle'+(n>1?'s':'');}
  }
  function renderArchiveCards(){
    root.querySelector('#archive-cards').innerHTML=archiveCards();wireArchives();
  }
  function openArchivedEvent(id){
    const event=archiveData?.events.find(item=>item.id===id);if(!event)return;
    const team=event.responses.filter(person=>person.selectedRole),date=dateParts(event.date),dialog=root.querySelector('#event-dialog');
    dialog.classList.remove('composition-dialog');
    dialog.innerHTML=`<button class="dialog-close" aria-label="Fermer">×</button><div class="dialog-banner archive-banner"><span class="tag">SPECTACLE PASSÉ</span><span aria-hidden="true">↶</span></div><div class="dialog-inner"><div class="eyebrow">${h(date.full)}</div><h2 id="dialog-title">${eventTitle(event.title)}</h2><div class="dialog-facts"><p>⌖ ${event.venueUrl?`<a href="${h(event.venueUrl)}">${h(event.venue)}</a>`:h(event.venue)}${event.address||event.city?`<small>${h([event.address,event.city].filter(Boolean).join(' · '))}</small>`:''}</p><p>◷ ${event.time?h(event.time.replace(':','h')):'Horaire non renseigné'}<small>Rendez-vous troupe : ${event.callTime?h(event.callTime.replace(':','h')):'non renseigné'}</small></p></div>${event.intermissionSet?`<p class="guest-team intermission-note">⏸ ${event.intermission?'Avec entracte':'Sans entracte'}</p>`:''}<p class="guest-team">${h(event.format)}${event.team?' · Avec '+h(event.team):''}</p><p class="archive-readonly">Cette soirée est passée. Les réponses et la composition des équipes sont conservées en lecture seule.</p>${event.mine?`<p class="selection-note">${event.mine.selectedRole?'★ Tu étais dans l’équipe : '+h(roleNames[event.mine.selectedRole]||event.mine.selectedRole)+'.':'Ta réponse : '+h(statusNames[event.mine.availability]||event.mine.availability)+'.'}</p>`:''}<section class="team-preview"><h3>L’équipe de cette soirée <span>${team.length} personne${team.length>1?'s':''}</span></h3>${team.length?`<div class="selected-people">${team.map(person=>`<div><span class="avatar" aria-hidden="true">${h([...person.name][0])}</span><span><b>${h(person.name)}</b><small>${h(roleNames[person.selectedRole]||person.selectedRole)}</small></span></div>`).join('')}</div>`:'<p>Aucune sélection n’a été enregistrée pour cette soirée.</p>'}</section>${data.organizer?`<details class="archive-responses"><summary>Les réponses à l’époque (${event.responses.length})</summary>${event.responses.length?`<ul>${event.responses.map(person=>`<li><strong>${h(person.name)}</strong><span>${h(statusNames[person.availability]||person.availability)}${person.roles.length?' · '+person.roles.map(role=>h(roleNames[role]||role)).join(', '):''}</span></li>`).join('')}</ul>`:'<p>Aucune réponse enregistrée.</p>'}</details>`:''}<a class="button secondary archive-public-link" href="${h(event.url)}">Voir la fiche du spectacle <span aria-hidden="true">↗</span></a></div>`;
    dialog.querySelector('.dialog-close').onclick=()=>dialog.close();dialog.showModal();
  }
  async function openCreateEvent(id=null){
    if(!data.organizer) return;
    const editing=Number.isInteger(id);
    const dialog=root.querySelector('#new-event-dialog');
    dialog.innerHTML=`<button class="dialog-close" aria-label="Fermer la fenêtre">×</button><div class="dialog-inner"><h2 id="new-event-title">${editing?'Modifier le spectacle':'Nouveau spectacle'}</h2><p class="field-help" role="status">On prépare la feuille de route…</p></div>`;
    dialog.querySelector('.dialog-close').onclick=()=>dialog.close();
    dialog.showModal();
    try{
      const [options,existing]=await Promise.all([api('event-options'),editing?api(`events/${id}/edit`):Promise.resolve(null)]);
      if(!dialog.open || !dialog.isConnected) return;
      const assigned=Object.fromEntries(Object.keys(roleNames).map(key=>[key,editing?data.events.find(item=>item.id===id)?.responses.filter(person=>person.selectedRole===key).length||0:0]));
      const choices=(items,placeholder,selected=0)=>`<option value="0" ${!selected?'selected':''}>${placeholder}</option>${items.map(item=>`<option value="${item.id}" ${item.id===selected?'selected':''}>${h(item.name)}${item.city?' · '+h(item.city):''}</option>`).join('')}`;
      dialog.innerHTML=`<button class="dialog-close" aria-label="Fermer la fenêtre">×</button><div class="dialog-banner"><span class="tag">${editing?'UNE DATE À AJUSTER':'UNE NOUVELLE DATE'}</span><span aria-hidden="true">✳</span></div><div class="dialog-inner"><div class="eyebrow">${editing?'LES DÉTAILS DU SPECTACLE':'ON PRÉPARE LA PROCHAINE SOIRÉE'}</div><h2 id="new-event-title">${editing?'Modifier le spectacle':'Et si on jouait…'}</h2><p class="new-event-intro">${editing?'Corrige les informations pratiques sans quitter les coulisses.':'Les infos pratiques d’abord. Le grand récit peut attendre.<br>Le titre et la date sont obligatoires.'}</p>
      <form id="new-event-form">
        <label class="new-event-field">Titre du spectacle *<input name="title" maxlength="200" required placeholder="Cabaret d’impro, rencontre avec…" autocomplete="off" value="${h(existing?.title||'')}"></label>
        <div class="new-event-grid"><label class="new-event-field">Date du spectacle *<input name="date" type="date" required value="${h(existing?.date||'')}"></label><label class="new-event-field">Format<select name="format">${choices(options.formats,'À préciser',existing?.format)}</select></label></div>
        <fieldset class="new-event-section"><legend>Le lieu & les horaires <span aria-hidden="true">↗</span></legend>
          <label class="new-event-field">Lieu<select name="venue">${choices(options.venues,'Lieu à préciser',existing?.venue)}</select><small id="new-venue-address">Choisis parmi les lieux enregistrés.</small></label>
          <div class="new-event-grid"><label class="new-event-field">Rendez-vous de la troupe<input name="callTime" type="time" value="${h(existing?.callTime||'')}"></label><label class="new-event-field">Début du spectacle<input name="time" type="time" value="${h(existing?.time||'')}"></label></div>
          <label class="new-event-field">Troupe invitée<select name="team">${choices(options.teams,'Pas de troupe invitée / à préciser',existing?.team)}</select></label><label class="close-switch"><input type="checkbox" name="intermission" ${existing?.intermission?'checked':''}><span class="switch-track" aria-hidden="true"></span><span>Avec entracte<small>À cocher si une pause est prévue pendant le spectacle.</small></span></label>
        </fieldset>
        <fieldset class="new-event-section"><legend class="stage-reminder-heading">Qui monte sur scène ? <span aria-hidden="true">♧</span><button type="button" class="button secondary stage-reminder-button" data-remind-event="${editing?id:''}" ${!editing||existing.closed?'disabled':''} title="${editing?(existing.closed?'Les réponses sont fermées':'Choisir les personnes à relancer'):'Enregistre d’abord le spectacle'}" aria-haspopup="dialog">Relancer les non-inscrits <span aria-hidden="true">✉</span></button></legend><p class="field-help">Places pour les SDR, hors troupe invitée. ${editing?'Les places déjà attribuées ne peuvent pas être retirées.':'Ajustables ensuite dans la composition de l’équipe.'}</p><div class="new-event-capacities">${Object.entries(roleNames).map(([key,label])=>`<label class="new-event-field">${label}<input name="capacity_${key}" type="number" min="${assigned[key]}" max="30" step="1" required value="${existing?.capacity?.[key]??0}"></label>`).join('')}</div><label class="close-switch"><input type="checkbox" name="closed" ${existing?.closed?'checked':''}><span class="switch-track" aria-hidden="true"></span><span>Fermer les réponses<small>Les membres ne pourront plus changer leur disponibilité.</small></span></label></fieldset>
        <div class="new-event-section"><label class="new-event-field">Lien de billetterie <span class="optional">(facultatif)</span><input name="bookingUrl" type="url" placeholder="https://…" value="${h(existing?.bookingUrl||'')}"></label>${existing?.hasBlocks?`<p class="new-event-block-note">La présentation de ce spectacle est composée de blocs Gutenberg. Elle reste intacte ici ; <a href="${h(existing.editUrl)}">ouvre l’éditeur</a> pour la modifier.</p>`:`<label class="new-event-field">Présentation <span class="optional">(facultatif)</span><textarea name="description" rows="3" placeholder="Quelques mots sur la soirée…">${h(existing?.description||'')}</textarea></label>`}</div>
        <p class="new-event-save-help">${editing?'Les changements seront visibles dans le planning et l’agenda public. Aucun email n’est envoyé automatiquement.':'L’enregistrement simple ne déclenche aucun email. « Enregistrer et prévenir les joueurs » ouvre le choix des destinataires avant l’envoi.'}</p><p class="form-message" role="status" aria-live="polite"></p>
        <div class="new-event-actions"><button type="submit" value="save" class="button primary">${editing?'Enregistrer les modifications':'Enregistrer le spectacle'} <span aria-hidden="true">✓</span></button>${editing?`<a class="button secondary" href="${h(existing.editUrl)}">Ouvrir dans Gutenberg <span aria-hidden="true">↗</span></a>`:'<button type="submit" value="invite" class="button secondary new-event-invite" aria-haspopup="dialog" disabled>Enregistrer et prévenir les joueurs <span aria-hidden="true">✉</span></button><button type="submit" value="editor" class="button secondary">Enregistrer et ouvrir Gutenberg <span aria-hidden="true">↗</span></button>'}</div><button type="button" class="new-event-cancel">Annuler</button>
      </form></div>`;
      const form=dialog.querySelector('form');
      let saving=false;
      const close=()=>{if(!saving) dialog.close();};
      dialog.querySelector('.dialog-close').onclick=close;
      dialog.querySelector('.new-event-cancel').onclick=close;
      dialog.oncancel=event=>{if(saving) event.preventDefault();};
      dialog.onclick=event=>{if(event.target===dialog){const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)close();}};
      form.elements.venue.onchange=()=>{
        const venue=options.venues.find(item=>item.id===Number(form.elements.venue.value));
        dialog.querySelector('#new-venue-address').textContent=venue?[venue.address,venue.city].filter(Boolean).join(' · ')||'Adresse à compléter dans la fiche du lieu.':'Choisis parmi les lieux enregistrés.';
      };
      form.elements.venue.onchange();
      const initialFields=JSON.stringify([...new FormData(form)]);
      const reminderButton=form.querySelector('[data-remind-event]');
      const refreshReminder=()=>{
        const dirty=JSON.stringify([...new FormData(form)])!==initialFields;
        const invite=form.querySelector('[value=invite]');
        if(invite){
          invite.disabled=form.elements.closed.checked||!form.elements.date.value||form.elements.date.value<options.today;
          invite.title=invite.disabled?'Renseigne une date à venir avec les réponses ouvertes':'Enregistrer puis choisir les personnes à prévenir';
        }
        reminderButton.disabled=!editing||existing.closed||dirty;
        reminderButton.title=!editing?'Enregistre d’abord le spectacle':dirty?'Enregistre les modifications avant de relancer':existing.closed?'Les réponses sont fermées':'Choisir les personnes à relancer';
      };
      form.addEventListener('input',refreshReminder);
      refreshReminder();
      form.onsubmit=async event=>{
        event.preventDefault();if(saving) return;
        const fd=new FormData(form),openEditor=event.submitter?.value==='editor',invite=!editing&&event.submitter?.value==='invite';
        if(invite&&window.SDREventReminders.isSending()){toast('Termine l’envoi en cours avant de créer un spectacle avec invitation.',true);return;}
        const body={title:fd.get('title'),date:fd.get('date'),time:fd.get('time'),callTime:fd.get('callTime'),venue:Number(fd.get('venue')),format:Number(fd.get('format')),team:Number(fd.get('team')),capacity:Object.fromEntries(Object.keys(roleNames).map(key=>[key,Number(fd.get('capacity_'+key))])),bookingUrl:fd.get('bookingUrl'),closed:fd.has('closed'),intermission:fd.has('intermission')};
        if(fd.has('description')) body.description=fd.get('description');
        if(editing) body.revision=existing.revision;
        const message=form.querySelector('.form-message');
        saving=true;message.textContent='Enregistrement du spectacle…';
        dialog.querySelectorAll('button,input,select,textarea').forEach(control=>control.disabled=true);
        try{
          const result=await api(editing?`events/${id}/edit`:'events',body);
          if(editing){
            dialog.close();
            try{data=await api('dashboard');render();toast('Le spectacle a été modifié.');}
            catch(error){location.reload();}
            return;
          }
          if(openEditor && result.editUrl){location.assign(result.editUrl);return;}
          // Use the saved event directly: a failed dashboard refresh must never invite a duplicate save.
          if(result.status==='publish' && result.event.date>=options.today){data.events.push({...result.event,responses:[],mine:null});data.events.sort((a,b)=>a.date.localeCompare(b.date)||a.id-b.id);}
          dialog.close();view='planning';filter='all';query='';render();
          root.querySelector('[data-new-event]')?.focus();
          toast('Le spectacle est enregistré.');
          if(invite){
            try{await window.SDREventReminders.open(result.id,root.querySelector(`[data-edit-event="${result.id}"]`)||root.querySelector('[data-new-event]'),'announcement');}
            catch(error){toast('Le spectacle est enregistré, mais la sélection des destinataires n’a pas pu s’ouvrir. Retrouve-le dans le planning pour réessayer.',true);}
          }
        }catch(error){
          saving=false;message.textContent=error.message;
          dialog.querySelectorAll('button,input,select,textarea').forEach(control=>control.disabled=false);
          refreshReminder();
        }
      };
      form.elements.title.focus();
    }catch(error){
      if(!dialog.open || !dialog.isConnected) return;
      dialog.querySelector('.field-help').textContent=error.message;
      const retry=document.createElement('button');retry.type='button';retry.className='button secondary';retry.textContent='Réessayer';retry.onclick=()=>{dialog.close();openCreateEvent(id);};dialog.querySelector('.dialog-inner').append(retry);
    }
  }
  function seasonChart(){
    if(!data.organizer || !data.seasonStats) return '';
    const players=[...data.seasonStats.players].sort((a,b)=>a.name.localeCompare(b.name,'fr'));
    const maximum=Math.max(1,...players.flatMap(p=>[p.proposed,p.played]));
    const active=players.some(p=>p.proposed||p.played);
    const bar=(value,kind)=>`<div class="season-bar-value"><span class="season-track" aria-hidden="true"><span class="season-bar ${kind}" style="width:${value/maximum*100}%"></span></span><b>${value}</b></div>`;
    return `<details class="season-chart" aria-labelledby="season-chart-title">
      <summary class="season-chart-heading"><div><div class="eyebrow">LE COLLECTIF, EN UN COUP D’ŒIL</div><h2 id="season-chart-title">Qui brûle les planches ?<span aria-hidden="true">✳</span></h2><p>Septembre ${h(data.season.start.slice(0,4))} → juillet ${h(data.season.end.slice(0,4))}</p></div><span class="season-toggle" aria-hidden="true">＋</span></summary><div class="season-chart-body">
      ${players.length?`<div class="season-chart-tools"><span id="season-player-count" aria-live="polite">${players.length} joueur·ses</span><label class="season-search"><span aria-hidden="true">⌕</span><input id="season-player-search" type="search" placeholder="Trouver un prénom…" aria-label="Rechercher un joueur dans le bilan de saison"></label></div>
      ${!active?'<p class="season-start-note">La scène attend ses premières envies : les compteurs se rempliront au fil des réponses.</p>':''}
      <div class="season-chart-scroll" tabindex="0" role="region" aria-label="Bilan des joueurs, liste défilante"><table class="season-table"><thead><tr><th scope="col">Joueur·se</th><th scope="col"><span class="season-key proposed" aria-hidden="true"></span>S’est proposé·e</th><th scope="col"><span class="season-key played" aria-hidden="true"></span>A déjà joué</th></tr></thead><tbody>${players.map((p,i)=>`<tr class="season-player-row" data-name="${h(p.name.toLocaleLowerCase('fr'))}"><th scope="row"><span class="season-avatar tone-${i%3}" aria-hidden="true">${h([...p.name][0])}</span><span>${h(p.name)}</span></th><td>${bar(p.proposed,'proposed')}</td><td>${bar(p.played,'played')}</td></tr>`).join('')}</tbody></table><p id="season-no-results" class="season-empty" hidden>Aucun joueur ne correspond à ce prénom.</p></div>`:'<p class="season-empty">Les joueurs apparaîtront ici dès leur arrivée dans la troupe.</p>'}
      <p class="season-chart-note">Propositions : « Partant·e » et « Si besoin » comme joueur·se, pour les dates de la saison. Déjà joué : sélection comme joueur·se sur une date passée (à partir du lendemain). MC et DJ exclus.</p>
    </div></details>`;
  }
  function filtered(){return data.events.filter(e=>(view!=='answers'||e.mine)&&(filter!=='pending'||!e.mine)&&(filter!=='yes'||['yes','maybe'].includes(e.mine?.availability))&&(`${e.title} ${e.venue} ${e.city} ${e.format} ${e.team} ${dateParts(e.date).full}`.toLowerCase().includes(query.toLowerCase())));}
  function cardsHTML(){const events=filtered();return events.length?events.map(card).join(''):`<div class="empty-state"><span>✳</span><h3>${view==='answers'?'Tout commence par une première date.':'Rien par ici pour le moment.'}</h3><p>${view==='answers'?'Retrouve le planning pour donner tes disponibilités.':'Essaie un autre filtre ou un autre mot.'}</p></div>`;}
  function renderCards(){const cards=root.querySelector('#event-cards');cards.innerHTML=cardsHTML();root.querySelector('#result-count').textContent=filtered().length+' date(s)';cards.querySelectorAll('[data-event]').forEach(b=>b.onclick=()=>openEvent(Number(b.dataset.event)));wireEditActions(cards);wireComposeActions(cards);}
  function wireEditActions(container){container.querySelectorAll('[data-edit-event]').forEach(button=>button.onclick=()=>openCreateEvent(Number(button.dataset.editEvent)));}
  function wireComposeActions(container){container.querySelectorAll('[data-organize]').forEach(button=>button.onclick=()=>openEvent(Number(button.dataset.organize),true));}
  function teamStatus(e){
    const total=Object.values(e.capacity).reduce((sum,n)=>sum+n,0),selected=e.responses.filter(person=>person.selectedRole).length;
    const complete=total>0&&Object.keys(roleNames).every(role=>roleCounts(e,role).selected>=e.capacity[role]);
    return {kind:complete?'complete':selected?'partial':'empty',label:complete?'Équipe complète':selected?'Équipe incomplète':'Équipe à composer',detail:total?`${selected} / ${total} personne${total>1?'s':''} retenue${total>1?'s':''}`:'Besoins à préciser'};
  }
  function teamCard(e){
    if(!data.organizer)return '';
    const status=teamStatus(e);
    return `<div class="card-team-panel"><div class="card-team-status ${status.kind}"><strong><span aria-hidden="true">${status.kind==='complete'?'✓':'♧'}</span> ${status.label}</strong><small>${status.detail}</small></div><button type="button" class="button secondary card-compose-action" data-organize="${e.id}" aria-label="Composer l’équipe pour ${h(e.title)} du ${h(dateParts(e.date).full)}" aria-haspopup="dialog">Composer l’équipe <span aria-hidden="true">↗</span></button></div>`;
  }
  function card(e){const d=dateParts(e.date), mine=e.mine, idx=data.events.indexOf(e), willing=e.responses.filter(r=>r.availability!=='no');return `<article class="match-card color-${idx%3}"><div class="card-top"><span class="tag">${h(e.format)}</span><span class="response-state ${mine?'responded':''}">${e.closed?'Réponses fermées':mine?mine.selectedRole?'★ Dans l’équipe':statusNames[mine.availability]:'● À toi de jouer'}</span></div><div class="card-date"><strong>${String(d.day).padStart(2,'0')}</strong><div><span>${h(d.month)} <small>${e.date.slice(0,4)}</small></span><p class="card-date-detail"><span class="weekday-label">${h(d.weekday)}</span>${e.time?'<span> · '+h(e.time.replace(':','h'))+'</span>':''}</p></div><span class="card-doodle">${['✳','↗','〰'][idx%3]}</span></div><h3>${eventTitle(e.team?"Sortie d'route VS "+e.team:e.title)}</h3><p class="card-location"><span>⌖</span> ${h(e.venue)}${e.time?'':' · Horaire à venir'}</p>${roleSummary(e)}${teamCard(e)}<div class="card-foot"><div class="volunteers">${willing.length?`<div class="avatar-stack">${willing.slice(0,3).map(r=>`<span title="${h(r.name)}">${h(r.name[0])}</span>`).join('')}</div><span>${willing.length} volontaire${willing.length>1?'s':''}</span>`:'<span class="first-volunteer">Et si tu ouvrais le bal ?</span>'}</div><div class="card-actions">${data.organizer?`<button class="card-edit-action" data-edit-event="${e.id}" aria-label="Modifier le spectacle ${h(e.title)}" aria-haspopup="dialog"><span class="card-edit-icon" aria-hidden="true">✎</span> Modifier</button>`:''}<button class="card-action" data-event="${e.id}" aria-label="${mine?'Voir ma réponse':'Répondre'} pour ${h(e.title)} du ${h(d.full)}">${mine?'Ma réponse':'Je me lance'} <span>↗</span></button></div></div></article>`;}
  function roleCounts(e,key){
    const proposed=e.responses.filter(r=>['yes','maybe'].includes(r.availability)&&r.roles.includes(key));
    return {proposed:proposed.length,maybe:proposed.filter(r=>r.availability==='maybe').length,selected:e.responses.filter(r=>r.selectedRole===key).length};
  }
  function roleSummary(e){
    return `<div class="role-meters">${Object.entries(roleNames).map(([key,name])=>{
      const count=roleCounts(e,key),capacity=e.capacity[key];
      return `<section class="role-summary" aria-label="${name} : ${count.proposed} candidature(s), dont ${count.maybe} si besoin ; ${count.selected} personne(s) retenue(s) pour ${capacity} place(s)"><h4>${name}</h4><p class="role-proposed ${count.proposed?'has-volunteers':''}"><span aria-hidden="true">♧</span> <strong>${count.proposed}</strong> proposé${count.proposed>1?'·es':'·e'}</p><p class="role-maybe">${count.maybe?`〰 dont ${count.maybe} si besoin`:'&nbsp;'}</p><div class="role-selected"><span>Retenus</span><b>${count.selected}<i>/${capacity}</i></b></div><span class="meter" aria-hidden="true"><span style="width:${capacity?Math.min(100,count.selected/capacity*100):0}%"></span></span></section>`;
    }).join('')}</div>`;
  }
  function profileChipField(type,title,items,helper){return `<fieldset class="profile-chip-field"><legend>${title}</legend><p>${helper}</p><div class="profile-chips" data-chip-list="${type}">${items.map((item,i)=>`<span class="profile-chip"><span class="chip-text">${h(item)}</span><button type="button" data-remove-chip aria-label="Retirer ${h(item)}">×</button></span>`).join('')}</div><div class="profile-chip-entry"><input data-chip-input="${type}" maxlength="60" placeholder="Ex. les impros ratées"><button class="button secondary" type="button" data-add-chip="${type}">Ajouter <span>＋</span></button></div></fieldset>`;}
  function profileView(){const p=data.user;return `<div class="page-heading"><div><div class="eyebrow">LES PETITS DÉTAILS QUI COMPTENT</div><h1>Moi, en coulisses<span class="wave">✳</span></h1><p>Quelques infos pour trouver ta place et préparer les soirées.</p></div></div><div class="profile-panel profile-photo-panel"><div class="profile-intro">${p.photoUrl?`<img class="profile-photo-preview" src="${h(p.photoUrl)}" alt="Portrait de ${h(p.name)}">`:`<span class="avatar large">${h(p.name[0])}</span>`}<div><h2>${h(p.name)}</h2><p>Ton portrait de troupe</p></div></div><form id="profile-photo-form"><label class="photo-upload">${p.photoUrl?'Changer ma photo':'Ajouter une photo'}<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required><span>JPG, PNG ou WebP · 5 Mo maximum</span></label><button class="button secondary" type="submit">Envoyer la photo <span>↗</span></button><p class="form-message" aria-live="polite"></p></form></div><form id="profile-form" class="profile-panel"><fieldset><legend>Ma petite bio</legend><p>Quelques mots pour que le public mette un visage sur la troupe.</p><textarea name="description" rows="4" maxlength="600" placeholder="Mon super-pouvoir sur scène, mon parcours, une anecdote…">${h(p.description)}</textarea><small class="field-counter">600 caractères maximum</small></fieldset>${profileChipField('likes','J’aime',p.likes,'Ajoute les choses que tu aimes : un mot, une passion, une obsession.')} ${profileChipField('dislikes','J’aime pas',p.dislikes,'Les choses qui te font lever les yeux au ciel, avec tendresse.')}<label class="roster-optin"><input type="checkbox" name="rosterVisible" ${p.rosterVisible?'checked':''}><span><b>Je veux apparaître dans le Trombinoscope public</b><small>Ma photo, ma bio et mes listes « J’aime / J’aime pas » seront visibles sur le site.</small></span></label><fieldset><legend>Sur scène, je peux être…</legend><p>Coche les rôles que tu souhaites proposer.</p><div class="role-options">${Object.entries(roleNames).map(([k,v])=>`<label class="role-option"><input type="checkbox" name="roles" value="${k}" ${p.roles.includes(k)?'checked':''}><span>${k==='joueur'?'✳':k==='mc'?'♧':'♫'} ${v}</span></label>`).join('')}</div></fieldset><label class="close-switch stage-opt-in"><input type="checkbox" name="stageOptIn" ${p.stageOptIn?'checked':''}><span class="switch-track" aria-hidden="true"></span><span>OK pour la scène<small>Je souhaite recevoir les invitations à jouer, faire le MC ou le DJ selon mes rôles.</small></span></label><div class="profile-fields"><label>Mon repas<select name="diet">${[['unspecified','À préciser'],['standard','Sans préférence'],['vegetarian','Végétarien'],['vegan','Végétalien']].map(([k,v])=>`<option value="${k}" ${p.diet===k?'selected':''}>${v}</option>`).join('')}</select></label><label>Mes années d’impro<input type="number" min="0" max="80" name="experience" value="${h(p.experience)}" placeholder="À préciser"></label><label>Genre / comment me désigner <small>facultatif</small><input name="gender" maxlength="80" value="${h(p.gender)}" placeholder="Libre à toi de le préciser"></label></div><p class="privacy-note">⌑ Repas, genre, rôles et expérience restent privés. Le choix d’apparaître dans le Trombinoscope est modifiable à tout moment.</p><button class="button primary" type="submit">Enregistrer mon profil <span>↗</span></button><p class="form-message" aria-live="polite"></p></form><details class="password-panel"><summary><span class="password-icon" aria-hidden="true">⌁</span><span><b>Définir ou changer mon mot de passe</b><small>Ton mot de passe reste privé et n’est jamais affiché.</small></span><span class="password-plus" aria-hidden="true">＋</span></summary><form id="password-form"><label>Mot de passe actuel<span class="password-field"><input type="password" name="currentPassword" autocomplete="current-password" required><button type="button" data-toggle-password="currentPassword" data-label="le mot de passe actuel" aria-label="Afficher le mot de passe actuel">◉</button></span></label><label>Nouveau mot de passe<span class="password-field"><input type="password" name="newPassword" autocomplete="new-password" minlength="12" maxlength="4096" required aria-describedby="password-help"><button type="button" data-toggle-password="newPassword" data-label="le nouveau mot de passe" aria-label="Afficher le nouveau mot de passe">◉</button></span></label><label>Confirmer le nouveau mot de passe<span class="password-field"><input type="password" name="confirmPassword" autocomplete="new-password" minlength="12" maxlength="4096" required><button type="button" data-toggle-password="confirmPassword" data-label="la confirmation du mot de passe" aria-label="Afficher la confirmation du mot de passe">◉</button></span></label><p id="password-help" class="password-help">Au moins 12 caractères. Évite ton nom, ton identifiant et ton adresse email.</p><button class="button primary" type="submit">Enregistrer mon mot de passe <span>✓</span></button><p class="form-message" role="status" aria-live="polite"></p></form></details><p class="profile-logout"><a href="${h(SDR.logout)}">Se déconnecter ↗</a></p><div class="facebook-note"><b>f</b><div><h3>Un raccourci avec Facebook</h3><p>${data.facebookReady?'Associe ton compte Facebook pour te connecter en un clic.':'La connexion Facebook sera disponible après activation de l’application Meta pour le site de la troupe.'}</p><a href="${h(SDR.home)}connexion-facebook/" class="social-link">${data.facebookReady?'Associer mon compte Facebook ↗':'État de la connexion Facebook ↗'}</a></div></div>`;}
  function addProfileChip(type){const input=root.querySelector(`[data-chip-input="${type}"]`),list=root.querySelector(`[data-chip-list="${type}"]`);if(!input||!list)return;const value=input.value.trim().replace(/,$/,'').trim();if(!value)return;if([...list.querySelectorAll('.chip-text')].some(item=>item.textContent.toLocaleLowerCase('fr')===value.toLocaleLowerCase('fr'))){input.value='';return;}if(list.querySelectorAll('.profile-chip').length>=12){toast('Tu peux ajouter jusqu’à 12 éléments.',true);return;}list.insertAdjacentHTML('beforeend',`<span class="profile-chip"><span class="chip-text">${h(value)}</span><button type="button" data-remove-chip aria-label="Retirer ${h(value)}">×</button></span>`);list.lastElementChild.querySelector('[data-remove-chip]').onclick=()=>list.lastElementChild.remove();input.value='';input.focus();}
  async function saveProfile(e){e.preventDefault();const form=e.currentTarget, button=form.querySelector('button[type=submit]');button.disabled=true;const fd=new FormData(form),chips=type=>[...form.querySelectorAll(`[data-chip-list="${type}"] .chip-text`)].map(node=>node.textContent);try{const p=await api('profile',{roles:fd.getAll('roles'),stageOptIn:fd.has('stageOptIn'),diet:fd.get('diet'),experience:fd.get('experience')===''?'':Number(fd.get('experience')),gender:fd.get('gender'),description:fd.get('description'),likes:chips('likes'),dislikes:chips('dislikes'),rosterVisible:fd.has('rosterVisible')});data.user={...data.user,...p};render();toast('Ton profil est à jour.');}catch(err){form.querySelector('.form-message').textContent=err.message;toast(err.message,true);button.disabled=false;}}
  async function saveProfilePhoto(e){e.preventDefault();const form=e.currentTarget,button=form.querySelector('button[type=submit]'),file=form.elements.photo.files[0];if(!file)return;button.disabled=true;try{const body=new FormData();body.append('photo',file);const response=await fetch(SDR.api+'profile-photo',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':SDR.nonce},body});const result=await response.json();if(!response.ok)throw new Error(result.message||'Impossible d’envoyer cette photo.');data.user={...data.user,...result};render();toast('Ton portrait est en place.');}catch(error){form.querySelector('.form-message').textContent=error.message;toast(error.message,true);button.disabled=false;}}
  async function savePassword(e){
    e.preventDefault();
    const form=e.currentTarget,button=form.querySelector('button[type=submit]'),message=form.querySelector('.form-message'),fd=new FormData(form);
    const currentPassword=fd.get('currentPassword'),newPassword=fd.get('newPassword'),confirmPassword=fd.get('confirmPassword');
    message.textContent='';
    if(newPassword!==confirmPassword){message.textContent='Les deux nouveaux mots de passe ne correspondent pas.';form.elements.confirmPassword.focus();return;}
    if([...newPassword].length<12){message.textContent='Choisis au moins 12 caractères.';form.elements.newPassword.focus();return;}
    button.disabled=true;form.querySelectorAll('input').forEach(input=>input.disabled=true);
    try{
      const result=await api('password',{currentPassword,newPassword});
      SDR.nonce=result.nonce;
      form.reset();form.querySelectorAll('input').forEach(input=>{input.disabled=false;input.type='password';});
      form.closest('details').open=false;
      toast('Ton mot de passe est enregistré. Tu restes connecté·e ici ; les autres appareils ont été déconnectés.');
    }catch(err){
      message.textContent=err.message;
      form.querySelectorAll('input').forEach(input=>input.disabled=false);
      button.disabled=false;
      form.elements.currentPassword.focus();
    }
  }
  function openEvent(id,organize=false){
    const e=data.events.find(event=>event.id===id);if(!e)return;
    organize=Boolean(organize&&data.organizer);dialogEvent=id;
    const dialog=root.querySelector('#event-dialog');dialog.classList.toggle('composition-dialog',organize);
    dialog.innerHTML=eventDialog(e,organize);dialog.showModal();wireDialog(e,organize);
  }
  function eventDialog(e,organize){const d=dateParts(e.date),mine=e.mine, selected=e.responses.filter(r=>r.selectedRole),roles=mine?.roles?.length?mine.roles:data.user.roles.slice(0,1);return `<button class="dialog-close" aria-label="Fermer">×</button><div class="dialog-banner"><span class="tag">${h(e.format)}</span><span>✳</span></div><div class="dialog-inner"><div class="eyebrow">${h(d.full)}</div><h2 id="dialog-title">${eventTitle(e.title)}</h2><div class="dialog-facts"><p>⌖ <a href="${h(e.venueUrl)}">${h(e.venue)}</a>${e.address?`<small>${h(e.address)}</small>`:''}</p><p>◷ ${e.time?h(e.time.replace(':','h')):'Horaire à préciser'}<small>Rendez-vous troupe : ${e.callTime?h(e.callTime.replace(':','h')):'à préciser'}</small></p></div>${e.intermissionSet?`<p class="guest-team intermission-note">⏸ ${e.intermission?'Avec entracte':'Sans entracte'}</p>`:''}${e.team?`<p class="guest-team">Avec <a href="${h(e.teamUrl)}">${h(e.team)}</a></p>`:''}${organize?organizationDialog(e):`<form id="response-form"><fieldset ${e.closed?'disabled':''}><legend>Tu es de la partie ?</legend><div class="availability-options">${[['yes','☀','Partant·e !'],['maybe','〰','Si besoin'],['no','☾','Pas dispo']].map(([k,icon,name])=>`<label class="availability-option ${k}"><input type="radio" name="availability" value="${k}" ${mine?.availability===k?'checked':''} required><span><b>${icon}</b>${name}</span></label>`).join('')}</div><div class="choose-roles"><h3>Dans quel rôle ?</h3><div class="role-options">${Object.entries(roleNames).filter(([k])=>data.user.roles.includes(k)).map(([k,name])=>`<label class="role-option"><input type="checkbox" name="roles" value="${k}" ${roles.includes(k)?'checked':''}><span>${name}</span></label>`).join('')}</div>${!data.user.roles.length?'<p>Ajoute tes rôles dans « Mon profil » avant de te proposer.</p>':''}<p class="field-help">Tu peux proposer plusieurs rôles. Un seul sera retenu par soirée.</p></div><button type="submit" class="button primary wide">${mine?'Enregistrer ma réponse':'C’est parti, j’envoie ma réponse'} <span>↗</span></button>${mine?'<button type="button" class="clear-response">Retirer ma réponse</button>':''}</fieldset>${e.closed?'<p class="closed-note">Les réponses sont fermées. Contacte l’organisation pour un changement.</p>':''}<p class="form-message" role="status"></p></form><div class="selection-note">${mine?.selectedRole?`★ Tu es sélectionné·e en tant que ${h(roleNames[mine.selectedRole])}.`:'♡ Tu donnes ta disponibilité ; l’organisation confirme ensuite l’équipe.'}</div>`}${organize?'':`<section class="team-preview"><h3>L’équipe se dessine <span>${selected.length} sélectionné·es</span></h3>${selected.length?`<div class="selected-people">${selected.map(r=>`<div><span class="avatar">${h(r.name[0])}</span><span><b>${h(r.name)}</b><small>${roleNames[r.selectedRole]}</small></span></div>`).join('')}</div>`:'<p>Les rôles attendent encore leurs interprètes.</p>'}</section>`}</div>`;}
  function organizationDialog(e){const candidates=e.responses.filter(r=>r.availability!=='no'),responded=new Set(e.responses.map(r=>r.userId)),missing=data.members.filter(m=>!responded.has(m.id));return `<section class="organizer-panel"><div class="team-dialog-heading"><div><div class="eyebrow">LE COLLECTIF ENTRE EN SCÈNE</div><h3>Composer l’équipe</h3></div><button type="button" class="button secondary stage-reminder-button" data-remind-event="${e.id}" ${e.closed?'disabled':''} title="${e.closed?'Les réponses sont fermées':'Choisir les personnes à relancer'}" aria-haspopup="dialog">Relancer les non-inscrits <span aria-hidden="true">✉</span></button></div>${compositionBoard(e)}${needsEditor(e)}<h3 class="candidates-heading">À toi de distribuer les rôles</h3><p class="field-help">Coche un rôle pour sélectionner une personne, décoche-le pour la retirer. Un seul rôle par personne et par soirée ; les choix sont préparés automatiquement, puis confirmés avec les boutons de sauvegarde.</p>${candidates.length?candidates.map(r=>`<div class="candidate"><div class="candidate-info"><b>${h(r.name)}</b><span class="candidate-status ${r.availability}"><span aria-hidden="true">${r.availability==='yes'?'☀':'〰'}</span>${statusNames[r.availability]}</span><small>${h({vegetarian:'Repas végétarien',vegan:'Repas végétalien',standard:'Sans préférence',unspecified:'Repas à préciser'}[r.diet])}</small></div><div class="team-role-options" role="group" aria-label="Rôle de ${h(r.name)}">${r.roles.map(k=>`<label class="team-role-option"><input type="checkbox" data-select-user="${r.userId}" value="${k}" ${r.selectedRole===k?'checked':''} aria-label="Sélectionner ${h(r.name)} comme ${roleNames[k]}"><span><span class="role-check" aria-hidden="true">✓</span>${roleNames[k]}</span></label>`).join('')}</div></div>`).join(''):'<p class="empty-inline">Aucune candidature pour le moment.</p>'}<p class="selection-message" role="status" aria-live="polite"></p>${finalizeActions(e)}<details><summary>Sans réponse (${missing.length})</summary><p>${missing.map(m=>h(m.name)).join(' · ')||'Tout le monde a répondu.'}</p></details><details><summary>Indisponibles (${e.responses.filter(r=>r.availability==='no').length})</summary><p>${e.responses.filter(r=>r.availability==='no').map(r=>h(r.name)).join(' · ')||'Aucune indisponibilité déclarée.'}</p></details></section>`;}
  function finalizeActions(e){
    const selected=e.responses.filter(r=>r.selectedRole).length,disabled=selected?'':'disabled';
    return `<section class="finalize-panel"><div><h3>Finaliser cette équipe</h3><p>${selected?`${selected} personne${selected>1?'s':''} retenue${selected>1?'s':''}. L’email est envoyé individuellement aux membres sélectionnés.`:'Sélectionne au moins une personne avant de finaliser.'}</p></div><div class="finalize-buttons"><button type="button" class="button primary" data-finalize="notify" ${disabled}>Sauvegarder et informer les joueurs <span>✉</span></button><button type="button" class="button secondary" data-finalize="silent" ${disabled}>Sauvegarder sans informer</button></div><p class="finalize-message" role="status" aria-live="polite"></p></section>`;
  }
  function compositionBoard(e){
    const total=Object.values(e.capacity).reduce((sum,n)=>sum+n,0),selected=e.responses.filter(r=>r.selectedRole).length,remaining=Math.max(0,total-selected);
    return `<div class="composition-progress" aria-label="Composition actuelle">${Object.entries(roleNames).map(([key,name])=>{const n=roleCounts(e,key).selected;return `<span class="${n===e.capacity[key]?'complete':''}"><span aria-hidden="true">${key==='joueur'?'✳':key==='mc'?'♧':'♫'}</span> ${name} <b>${n}/${e.capacity[key]}</b></span>`;}).join('')}</div><section class="composition-board"><div class="composition-heading"><div><span class="eyebrow">LE CASTING DE LA SOIRÉE</span><h3>L’équipe prend forme<span aria-hidden="true">✳</span></h3></div><span class="composition-total">${selected}<small>/${total}</small></span></div><p class="composition-caption">${!total?'Aucune place prévue : ajuste les besoins ci-dessous.':remaining?`Encore ${remaining} place${remaining>1?'s':''} à distribuer.`:'Tout le monde est en place !'}</p><div class="casting-grid">${Object.entries(roleNames).map(([key,name])=>{
      const count=roleCounts(e,key),people=e.responses.filter(r=>r.selectedRole===key),free=Math.max(0,e.capacity[key]-count.selected);
      return `<section class="casting-role ${free?'':'complete'}"><div class="casting-role-title"><span aria-hidden="true">${key==='joueur'?'✳':key==='mc'?'♧':'♫'}</span><h4>${name}</h4><b>${count.selected}/${e.capacity[key]}</b></div><p class="casting-proposals">${count.proposed} proposé${count.proposed>1?'·es':'·e'}${count.maybe?` <span>· ${count.maybe} si besoin</span>`:''}</p><div class="casting-seats">${people.map(p=>`<span class="casting-person"><span aria-hidden="true">✓</span>${h(p.name)}</span>`).join('')}${Array.from({length:Math.min(free,6)},()=>'<span class="casting-empty"><span aria-hidden="true">＋</span>Place libre</span>').join('')}${free>6?`<span class="casting-more">+ ${free-6} autres places libres</span>`:''}${!e.capacity[key]?'<span class="casting-unused">Pas de besoin</span>':''}</div><p class="casting-state">${!e.capacity[key]?'Rôle non prévu':free?`${free} à trouver`:'✓ Au complet !'}</p></section>`;
    }).join('')}</div></section>`;
  }
  function needsEditor(e){
    return `<details class="needs-editor"><summary>Ajuster les besoins <span>＋ / −</span></summary><form id="settings-form"><p class="field-help">Combien de places pour chaque rôle ? Les personnes déjà retenues restent en place.</p><div class="needs-steppers">${Object.entries(roleNames).map(([key,name])=>`<div class="needs-role"><label for="capacity-${key}">${name}</label><div class="capacity-stepper"><button type="button" data-step="-1" data-role="${key}" aria-label="Retirer une place ${name}">−</button><input id="capacity-${key}" type="number" min="${roleCounts(e,key).selected}" max="30" name="${key}" value="${e.capacity[key]}" required><button type="button" data-step="1" data-role="${key}" aria-label="Ajouter une place ${name}">+</button></div><small data-capacity-hint="${key}"></small></div>`).join('')}</div><label class="close-switch"><input type="checkbox" name="closed" ${e.closed?'checked':''}><span class="switch-track" aria-hidden="true"></span><span>Fermer les réponses<small>Les membres ne pourront plus changer leur disponibilité.</small></span></label><div class="needs-actions"><button class="button primary" type="submit">Enregistrer les besoins <span>✓</span></button><button class="button secondary" type="reset">Annuler</button></div><p class="form-message" aria-live="polite"></p></form></details>`;
  }
  function wireDialog(e,organize){const dialog=root.querySelector('dialog');dialog.querySelector('.dialog-close').onclick=()=>dialog.close();const form=dialog.querySelector('#response-form');if(form){form.onsubmit=async event=>{event.preventDefault();const fd=new FormData(form);await saveResponse(e,{availability:fd.get('availability'),roles:fd.getAll('roles')},form);};const clear=form.querySelector('.clear-response');if(clear)clear.onclick=()=>saveResponse(e,{availability:'clear',roles:[]},form);const updateRoles=()=>{form.querySelector('.choose-roles').hidden=form.querySelector('[name=availability]:checked')?.value==='no';};form.querySelectorAll('[name=availability]').forEach(i=>i.onchange=updateRoles);updateRoles();}
    dialog.querySelectorAll('[data-select-user]').forEach(input=>input.onchange=async()=>{
      const userId=Number(input.dataset.selectUser), role=input.checked?input.value:'', scroll=dialog.scrollTop;
      const controls=[...dialog.querySelectorAll('[data-select-user]')], message=dialog.querySelector('.selection-message');
      const needsFields=[...dialog.querySelectorAll('#settings-form input,#settings-form button')], disabledStates=needsFields.map(field=>field.disabled);
      needsFields.forEach(field=>field.disabled=true);
      dialog.querySelector('[data-remind-event]').disabled=true;
      controls.forEach(control=>{control.disabled=true;if(Number(control.dataset.selectUser)===userId)control.checked=control.value===role;});
      message.classList.remove('error');message.textContent='Enregistrement…';
      let saved=false;
      try{
        await api(`events/${e.id}/selection`,{userId,role});saved=true;
        e.responses.find(r=>r.userId===userId).selectedRole=role;
        data=await api('dashboard');
        if(!dialog.isConnected)return;
        const wasOpen=dialog.open;
        dialog.close();render();
        if(wasOpen){
          openEvent(e.id,true);
          const refreshed=root.querySelector('dialog');refreshed.scrollTop=scroll;
          refreshed.querySelector(`[data-select-user="${userId}"][value="${input.value}"]`)?.focus({preventScroll:true});
          refreshed.querySelector('.selection-message').textContent='✓ Équipe mise à jour.';
        }
      }catch(err){
        needsFields.forEach((field,i)=>field.disabled=disabledStates[i]);
        dialog.querySelector('[data-remind-event]').disabled=e.closed;
        controls.forEach(control=>{control.checked=e.responses.find(r=>r.userId===Number(control.dataset.selectUser)).selectedRole===control.value;control.disabled=false;});
        message.classList.add('error');
        message.textContent=saved?'Le choix est enregistré, mais l’affichage n’a pas pu être actualisé. Recharge la page.':err.message;
      }
    });
    dialog.querySelectorAll('[data-finalize]').forEach(button=>button.onclick=async()=>{
      const notify=button.dataset.finalize==='notify',buttons=[...dialog.querySelectorAll('[data-finalize]')],message=dialog.querySelector('.finalize-message');
      buttons.forEach(item=>item.disabled=true);dialog.querySelectorAll('[data-select-user]').forEach(input=>input.disabled=true);
      dialog.querySelector('[data-remind-event]').disabled=true;
      message.classList.remove('error');message.textContent=notify?'Préparation des emails…':'Sauvegarde de l’équipe…';
      try{
        const result=await api(`events/${e.id}/finalize`,{notify});
        if(!notify) message.textContent='✓ Équipe sauvegardée sans envoyer d’email.';
        else if(result.alreadyNotified) message.textContent='✓ Cette composition avait déjà été envoyée aux joueurs.';
        else{
          const parts=[`${result.notified} joueur${result.notified>1?'s':''} informé${result.notified>1?'s':''}`];
          if(result.skipped)parts.push(`${result.skipped} sans adresse email`);
          if(result.failed)parts.push(`${result.failed} envoi${result.failed>1?'s':''} en échec`);
          message.textContent='✓ Équipe sauvegardée · '+parts.join(' · ')+'.';
          if(result.failed)message.classList.add('error');
        }
        toast(notify?'Équipe finalisée.':'Équipe sauvegardée sans notification.',!!result.failed);
      }catch(err){message.classList.add('error');message.textContent=err.message;toast(err.message,true);}
      finally{buttons.forEach(item=>item.disabled=false);dialog.querySelectorAll('[data-select-user]').forEach(input=>input.disabled=false);refreshNeeds();}
    });
    const settings=dialog.querySelector('#settings-form');
    const refreshNeeds=()=>{
        if(!settings)return;
        let dirty=settings.elements.closed.checked!==e.closed;
        Object.keys(roleNames).forEach(key=>{
          const input=settings.elements[key],n=Number(input.value),min=Number(input.min),valid=input.value!==''&&Number.isInteger(n)&&n>=min&&n<=30;
          dirty=dirty||n!==e.capacity[key]||!valid;
          settings.querySelector(`[data-capacity-hint="${key}"]`).textContent=valid?`${min} retenu${min>1?'·es':'·e'} · ${n-min} libre${n-min>1?'s':''}`:`Prévoir entre ${min} et 30 places.`;
          settings.querySelector(`[data-role="${key}"][data-step="-1"]`).disabled=!valid||n<=min;
          settings.querySelector(`[data-role="${key}"][data-step="1"]`).disabled=!valid||n>=30;
        });
        dialog.querySelectorAll('[data-select-user],[data-finalize]').forEach(input=>input.disabled=dirty||(!dirty&&input.dataset.finalize&&e.responses.every(r=>!r.selectedRole)));
        const reminder=dialog.querySelector('[data-remind-event]');
        reminder.disabled=dirty||e.closed;
        reminder.title=dirty?'Enregistre les besoins avant de relancer':e.closed?'Les réponses sont fermées':'Choisir les personnes à relancer';
        settings.querySelector('.form-message').textContent=dirty?'Enregistre les besoins pour reprendre la composition.':'';
      };
    if(settings){
      settings.querySelectorAll('[data-step]').forEach(button=>button.onclick=()=>{
        const input=settings.elements[button.dataset.role];
        input.value=Math.max(Number(input.min),Math.min(30,Number(input.value)+Number(button.dataset.step)));
        refreshNeeds();
      });
      settings.addEventListener('input',refreshNeeds);
      settings.onreset=event=>{
        event.preventDefault();
        Object.keys(roleNames).forEach(key=>settings.elements[key].value=e.capacity[key]);
        settings.elements.closed.checked=e.closed;
        refreshNeeds();
      };
      refreshNeeds();
      settings.onsubmit=async event=>{
        event.preventDefault();
        const fd=new FormData(settings),fields=[...settings.querySelectorAll('input,button')];
        fields.forEach(field=>field.disabled=true);
        dialog.querySelectorAll('[data-select-user]').forEach(input=>input.disabled=true);
        dialog.querySelector('[data-remind-event]').disabled=true;
        try{
          await api(`events/${e.id}/settings`,{capacity:Object.fromEntries(Object.keys(roleNames).map(key=>[key,Number(fd.get(key))])),closed:fd.has('closed')});
          data=await api('dashboard');
          if(!dialog.isConnected)return;
          const wasOpen=dialog.open;dialog.close();render();
          if(wasOpen){openEvent(e.id,true);root.querySelector('.selection-message').textContent='✓ Besoins enregistrés : le tableau est à jour.';}
        }catch(err){
          fields.forEach(field=>field.disabled=false);refreshNeeds();
          settings.querySelector('.form-message').textContent=err.message;
        }
      };
    }
  }
  async function saveResponse(e,body,form){if(busy)return;busy=true;form.querySelectorAll('button').forEach(b=>b.disabled=true);try{await api(`events/${e.id}/response`,body);data=await api('dashboard');root.querySelector('dialog').close();render();toast(body.availability==='clear'?'Réponse retirée.':body.availability==='yes'?'C’est noté, tu es partant·e !':'Ta réponse est enregistrée.');}catch(err){form.querySelector('.form-message').textContent=err.message;form.querySelectorAll('button').forEach(b=>b.disabled=false);}finally{busy=false;}}
  api('dashboard').then(result=>{data=result;render();if(view==='archives')loadArchives();const eventId=Number(new URLSearchParams(location.search).get('spectacle'));if(data.events.some(e=>e.id===eventId))openEvent(eventId,new URLSearchParams(location.search).get('vue')==='equipes'||new URLSearchParams(location.search).get('composer')==='1');}).catch(err=>{root.innerHTML=`<div class="app-loading"><h2>Petit contretemps.</h2><p>${h(err.message)}</p><button class="button primary" id="retry">Réessayer</button><a href="${h(SDR.home)}">Retour à l’accueil</a></div>`;root.querySelector('#retry').onclick=()=>location.reload();});
})();
