(() => {
  'use strict';
  const h=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('fr');
  const access={all:'Tous les membres',organizer:'Organisation',administrator:'Administration'};
  let cached,request;

  async function load(){
    if(cached)return cached;
    if(!request)request=(async()=>{
      const response=await fetch(SDR.api+'documentation',{credentials:'same-origin',headers:{'X-WP-Nonce':SDR.nonce}});
      const data=await response.json();
      if(!response.ok)throw new Error(data.message||'La documentation ne peut pas être chargée.');
      cached=data;return data;
    })().finally(()=>{request=null;});
    return request;
  }

  function guideURL(domain,guide){
    const url=new URL(location.href);
    url.searchParams.set('vue','documentation');url.searchParams.set('domaine',domain);url.searchParams.set('guide',guide);
    ['spectacle','composer','recherche'].forEach(key=>url.searchParams.delete(key));
    return url.href;
  }

  function article(guide,domain,open){
    return `<details class="documentation-guide" data-doc-guide="${h(guide.id)}" data-doc-guide-domain="${h(domain.id)}" ${open?'open':''}>
      <summary><span class="documentation-guide-title">${h(guide.title)}</span><span class="documentation-access">${h(access[guide.access])}</span></summary>
      <div class="documentation-guide-body"><p class="documentation-summary">${h(guide.summary)}</p>
        <ol>${guide.steps.map(step=>`<li>${h(step)}</li>`).join('')}</ol>
        ${guide.fields?.length?`<section class="documentation-fields"><h4>Champs de la fiche</h4><ul>${guide.fields.map(field=>`<li>${h(field)}</li>`).join('')}</ul></section>`:''}
        ${guide.notes?.length?`<aside class="documentation-note"><h4>À savoir</h4><ul>${guide.notes.map(note=>`<li>${h(note)}</li>`).join('')}</ul></aside>`:''}
        <a class="documentation-permalink" href="${h(guideURL(domain.id,guide.id))}">Lien direct vers ce guide <span aria-hidden="true">↗</span></a>
      </div></details>`;
  }

  async function mount(container){
    container.innerHTML='<p class="documentation-loading" role="status">Chargement de la documentation…</p>';
    try{
      const data=await load();if(!container.isConnected)return;
      const params=new URLSearchParams(location.search),guides=data.domains.flatMap(domain=>domain.guides.map(guide=>({guide,domain,text:normalize([domain.title,guide.title,guide.summary,...guide.steps,...guide.notes,...(guide.fields||[])].join(' '))})));
      let domain=data.domains.some(item=>item.id===params.get('domaine'))?params.get('domaine'):data.domains[0].id;
      const requested=guides.find(item=>item.guide.id===params.get('guide'));
      if(requested)domain=requested.domain.id;
      container.innerHTML=`<header class="documentation-heading"><div class="eyebrow">MODE D’EMPLOI</div><h1>Documentation</h1><p>Des procédures concrètes pour préparer les spectacles et utiliser les Coulisses.</p><p class="documentation-meta">${guides.length} guides · ${data.domains.length} domaines · Mis à jour le ${h(data.updatedLabel)}</p></header>
        <div class="documentation-search"><label for="documentation-search">Rechercher dans tous les guides</label><div><input id="documentation-search" type="search" placeholder="Créer un spectacle, relancer, modifier mon profil…" autocomplete="off"><button type="button" data-doc-clear hidden>Effacer</button></div></div>
        <div class="documentation-layout"><nav class="documentation-domains" aria-label="Domaines de documentation"><h2>Par domaine</h2>${data.domains.map(item=>`<button type="button" data-doc-domain="${h(item.id)}"><span>${h(item.title)}</span><small>${item.guides.length}</small></button>`).join('')}</nav>
        <section class="documentation-results" aria-labelledby="documentation-domain-title"><header><p class="documentation-result-count" role="status" aria-live="polite"></p><h2 id="documentation-domain-title"></h2><p class="documentation-domain-summary"></p></header><div class="documentation-articles"></div></section></div>`;
      const input=container.querySelector('#documentation-search'),clear=container.querySelector('[data-doc-clear]'),articles=container.querySelector('.documentation-articles');
      input.value=params.get('recherche')||'';
      const updateURL=guide=>{
        const url=new URL(location.href);url.searchParams.set('vue','documentation');url.searchParams.set('domaine',domain);
        if(input.value.trim())url.searchParams.set('recherche',input.value.trim());else url.searchParams.delete('recherche');
        if(guide)url.searchParams.set('guide',guide);else url.searchParams.delete('guide');
        history.replaceState(null,'',url);
      };
      const show=(selectedGuide='')=>{
        const terms=normalize(input.value.trim()).split(/\s+/).filter(Boolean),searching=terms.length>0,active=data.domains.find(item=>item.id===domain);
        const found=guides.filter(item=>searching?terms.every(term=>item.text.includes(term)):item.domain.id===domain);
        container.querySelector('#documentation-domain-title').textContent=searching?'Résultats de recherche':active.title;
        container.querySelector('.documentation-domain-summary').textContent=searching?'La recherche porte sur tous les domaines.':active.summary;
        container.querySelector('.documentation-result-count').textContent=`${found.length} guide${found.length>1?'s':''}${searching?' trouvé'+(found.length>1?'s':''):''}`;
        clear.hidden=!input.value;
        container.querySelectorAll('[data-doc-domain]').forEach(button=>{const selected=!searching&&button.dataset.docDomain===domain;button.classList.toggle('active',selected);button.setAttribute('aria-pressed',String(selected));});
        articles.innerHTML=found.length?found.map((item,index)=>article(item.guide,item.domain,item.guide.id===selectedGuide||(!selectedGuide&&!searching&&index===0))).join(''):'<div class="documentation-empty"><h3>Aucun guide trouvé</h3><p>Essaie un mot plus court, comme « lieu », « équipe » ou « email ».</p></div>';
        articles.querySelectorAll('details').forEach(details=>details.ontoggle=()=>{if(container.isConnected&&details.isConnected&&details.open)updateURL(details.dataset.docGuide);});
        if(selectedGuide)articles.querySelector(`[data-doc-guide="${CSS.escape(selectedGuide)}"]`)?.scrollIntoView({block:'nearest'});
      };
      input.oninput=()=>{updateURL();show();};
      clear.onclick=()=>{input.value='';updateURL();show();input.focus();};
      container.querySelectorAll('[data-doc-domain]').forEach(button=>button.onclick=()=>{domain=button.dataset.docDomain;input.value='';updateURL();show();});
      show(requested?.guide.id);
    }catch(error){
      if(!container.isConnected)return;
      container.innerHTML=`<div class="documentation-empty" role="status"><h1>Documentation indisponible</h1><p>${h(error.message)}</p><button type="button" class="button secondary">Réessayer</button></div>`;
      container.querySelector('button').onclick=()=>mount(container);
    }
  }
  window.SDRDocumentation={mount};
})();
