(() => {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const roles = {joueur:'Joueur·se',mc:'MC',dj:'DJ'};
  let current;
  async function api(path, body) {
    const response=await fetch(SDR.api+path,{method:body===undefined?'GET':'POST',credentials:'same-origin',headers:{'X-WP-Nonce':SDR.nonce,'Content-Type':'application/json'},...(body===undefined?{}:{body:JSON.stringify(body)})});
    let data;try{data=await response.json();}catch{throw new Error('Le serveur ne répond pas correctement. Réessaie.');}
    if(!response.ok)throw new Error(data.message||'La relance n’a pas pu être préparée.');return data;
  }
  async function open(id, opener, kind='reminder') {
    const announcement=kind==='announcement';
    if(current?.running){if(!current.dialog.open)current.dialog.showModal();current.dialog.querySelector('[role=status]').textContent='Une relance est déjà en cours. Tu peux suivre son avancement ici.';return;}
    current?.dialog.remove();
    const dialog=document.createElement('dialog');dialog.className='event-dialog reminder-dialog';dialog.setAttribute('aria-labelledby','reminder-title');
    dialog.innerHTML=`<div class="dialog-banner"><span class="tag">${announcement?'UNE NOUVELLE DATE À PARTAGER':'UN PETIT SIGNE À LA TROUPE'}</span><span class="dialog-doodle" aria-hidden="true">✉</span><button type="button" class="dialog-close" aria-label="Fermer les relances">×</button></div><div class="dialog-inner"><h2 id="reminder-title">${announcement?'Prévenir les joueurs':'Relancer les non-inscrits'}</h2><p role="status" aria-live="polite">On prépare la liste…</p><div class="reminder-content"></div></div>`;
    document.body.append(dialog);dialog.showModal();
    const state=current={id,kind,dialog,opener,running:false,selected:new Set(),campaign:null,sent:false,requestId:'relance-'+Date.now()+'-'+Math.random().toString(36).slice(2)};
    dialog.querySelector('.dialog-close').onclick=()=>dialog.close();
    dialog.addEventListener('close',()=>opener?.focus({preventScroll:true}));
    await load(state);
  }
  async function load(state) {
    const {dialog,id}=state,notice=dialog.querySelector('[role=status]'),content=dialog.querySelector('.reminder-content');
    notice.textContent='On prépare la liste…';
    try{
      const data=await api(`events/${id}/reminders?kind=${state.kind}`);if(!dialog.isConnected)return;
      state.kind=data.kind||(data.campaign?.pending?data.campaign.kind||'reminder':state.kind);
      dialog.querySelector('#reminder-title').textContent=state.kind==='announcement'?'Prévenir les joueurs':'Relancer les non-inscrits';
      state.requestId='relance-'+Date.now()+'-'+Math.random().toString(36).slice(2);state.campaign=data.campaign;state.users=data.users;state.selected=new Set(data.users.map(user=>user.id));state.sent=false;
      const targeted=state.kind==='reminder',needs=Object.entries(data.needs||{}).filter(([,count])=>count>0);
      const needsText=needs.map(([role,count])=>`${count} ${role==='joueur'?(count>1?'joueur·ses':'joueur·se'):roles[role]}`).join(' · ');
      const emptyMessage=!targeted?'Personne à contacter pour le moment. Le choix « OK pour la scène » se renseigne dans le profil.':!data.hasCapacity?'Renseigne les besoins de l’équipe avant de relancer.':!needs.length?'L’équipe est complète : aucune relance nécessaire.':'Aucun profil sans réponse ne correspond aux rôles encore recherchés. Les personnes ayant déjà répondu ne sont pas relancées.';
      content.innerHTML=`<p class="reminder-event-name">${escape(data.event.title)}</p><p class="field-help">${escape(data.event.date)} · ${escape(data.event.venue)}<br>Seuls les membres « OK pour la scène », sans aucune réponse et avec une adresse personnelle, figurent ici.</p>
        ${targeted?`<div class="reminder-needs"><strong>${needs.length?'Encore à trouver : '+escape(needsText):data.hasCapacity?'L’équipe est complète':'Besoins à préciser'}</strong><p>${needs.length?'La relance concerne uniquement les profils correspondant à ces rôles. Les besoins seront revérifiés juste avant chaque envoi.':escape(emptyMessage)}</p></div>`:''}
        <div class="reminder-selection"><label><input type="checkbox" data-reminder-all ${data.users.length?'checked':''}> Tout sélectionner</label><span class="reminder-count" aria-live="polite"></span></div>
        <div class="reminder-users">${data.users.map(user=>`<label class="reminder-user"><input type="checkbox" data-reminder-user="${user.id}" checked><span><strong>${escape(user.name)}</strong><small>${escape(user.email)}</small><span class="reminder-roles">${user.roles.map(role=>`<span class="${targeted&&user.targetRoles?.includes(role)?'reminder-role-needed':''}">${roles[role]}${targeted&&user.targetRoles?.includes(role)?' · recherché':''}</span>`).join('')}</span></span></label>`).join('')||`<p class="field-help">${escape(emptyMessage)}</p>`}</div>
        ${data.excluded?`<p class="field-help">${data.excluded} profil(s) sans adresse personnelle utilisable ne figurent pas dans cette liste.</p>`:''}
        <div class="reminder-mail-note"><strong>Deux boutons dans leur email</strong><p>« OK, je me lance ! » pour ${targeted?'proposer les rôles recherchés':'choisir leurs rôles'} ; « Je ne suis pas dispo » pour confirmer leur absence. Le tout sans connexion.</p></div>
        <div class="reminder-report" role="status" aria-live="polite"></div><div class="reminder-issues"></div>
        <div class="reminder-actions"><button type="button" class="button secondary" data-reminder-refresh>Actualiser la liste</button><button type="button" class="button primary" data-reminder-send></button><button type="button" class="button secondary" data-reminder-cancel hidden>Annuler les envois restants</button></div><p class="field-help reminder-send-help">Un email personnel par personne cochée. Garde cette page ouverte pendant l’envoi.</p>`;
      notice.textContent=state.campaign?.pending?'Un envoi est en attente. Tu peux le reprendre ou annuler les envois restants.':state.kind==='announcement'?'Le spectacle est enregistré. Choisis qui prévenir : aucun email ne part avant ta confirmation.':!data.users.length?emptyMessage:'Choisis les personnes à contacter, puis confirme l’envoi.';
      content.querySelectorAll('[data-reminder-user]').forEach(input=>input.onchange=()=>{const uid=Number(input.dataset.reminderUser);input.checked?state.selected.add(uid):state.selected.delete(uid);buttons(state);});
      content.querySelector('[data-reminder-all]').onchange=event=>{state.selected=new Set(event.target.checked?state.users.map(user=>user.id):[]);content.querySelectorAll('[data-reminder-user]').forEach(input=>input.checked=event.target.checked);buttons(state);};
      content.querySelector('[data-reminder-refresh]').onclick=()=>{state.requestId='relance-'+Date.now()+'-'+Math.random().toString(36).slice(2);load(state);};
      content.querySelector('[data-reminder-send]').onclick=()=>send(state);
      content.querySelector('[data-reminder-cancel]').onclick=async()=>{try{state.campaign=await api(`events/${id}/reminders/${state.campaign.id}/cancel`,{});await load(state);}catch(error){notice.textContent=error.message;}};
      report(state);buttons(state);
    }catch(error){notice.textContent=error.message;content.innerHTML='<button type="button" class="button secondary">Réessayer</button>';content.querySelector('button').onclick=()=>load(state);}
  }
  function buttons(state) {
    const content=state.dialog.querySelector('.reminder-content'),pending=state.campaign?.pending>0;
    const send=content.querySelector('[data-reminder-send]');if(!send)return;
    send.disabled=state.running||state.sent||(!pending&&!state.selected.size);
    send.textContent=state.running?'Envoi en cours…':pending?`Reprendre ${state.campaign.pending} relance(s)`:`Envoyer à ${state.selected.size} personne${state.selected.size>1?'s':''} ✉`;
    content.querySelector('[data-reminder-refresh]').disabled=state.running||pending;
    content.querySelector('[data-reminder-cancel]').hidden=!pending||state.running;
    content.querySelector('.reminder-users').inert=state.running||pending||state.sent;
    const all=content.querySelector('[data-reminder-all]');all.disabled=state.running||pending||state.sent||!state.users.length;all.checked=!!state.users.length&&state.selected.size===state.users.length;all.indeterminate=state.selected.size>0&&state.selected.size<state.users.length;
    content.querySelector('.reminder-count').textContent=`${state.selected.size} sélectionné${state.selected.size>1?'s':''} sur ${state.users.length}`;
  }
  function report(state) {
    const c=state.campaign;if(!c)return;
    state.dialog.querySelector('.reminder-report').textContent=`${c.accepted} email(s) transmis à WordPress · ${c.pending} en attente${c.skipped?` · ${c.skipped} ignoré(s) après vérification`:''}${c.failed?` · ${c.failed} en échec`:''}${c.uncertain?` · ${c.uncertain} transmission(s) à vérifier`:''}${c.cancelled?` · ${c.cancelled} annulé(s)`:''}.`;
    state.dialog.querySelector('.reminder-issues').innerHTML=c.issues.map(issue=>`<p>${escape(issue.name)} : ${escape(issue.message)}</p>`).join('');
  }
  async function send(state) {
    if(state.running)return;state.running=true;buttons(state);
    const notice=state.dialog.querySelector('[role=status]');notice.textContent='Préparation des emails personnels…';
    try{
      if(!state.campaign?.pending)state.campaign=await api(`events/${state.id}/reminders`,{recipients:[...state.selected],requestId:state.requestId,kind:state.kind});
      report(state);
      while(state.campaign.pending){state.campaign=await api(`events/${state.id}/reminders/${state.campaign.id}/send`,{});report(state);}
      state.sent=true;notice.textContent='Traitement terminé. Le bilan indique les transmissions acceptées par WordPress, sans garantir leur réception.';
    }catch(error){notice.textContent=error.message+' Tu peux réessayer ou reprendre les envois restants.';}
    finally{state.running=false;buttons(state);}
  }
  window.SDREventReminders={open,isSending:()=>Boolean(current?.running)};
  document.addEventListener('click',event=>{const button=event.target.closest('[data-remind-event]');if(button&&!button.disabled&&button.dataset.remindEvent){event.preventDefault();open(Number(button.dataset.remindEvent),button);}});
  window.addEventListener('beforeunload',event=>{if(current?.running){event.preventDefault();event.returnValue='';}});
})();
