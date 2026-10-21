(() => {
    'use strict';
    const cfg = window.SDRRelances;
    const $ = id => document.getElementById(`sr-${id}`);
    const state = { users: [], selected: new Set(), page: 1, filters: [], definitions: [], variables: [], previewReady: false, loading: true, initialized: false, running: false, campaign: null };
    let listVersion = 0, previewVersion = 0, previewTimer, searchTimer, draftTimer, target = 'body', editorBound = false, draftWriting = false, draftDirty = false, lastEdit = '';
    const el = (tag, text, className) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (className) n.className = className; return n; };
    async function api(path, body) {
        const response = await fetch(cfg.api + path, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
        let data;
        try { data = await response.json(); } catch { throw new Error('Le serveur a renvoyé une réponse illisible. Réessaie.'); }
        if (!response.ok) throw new Error(data.message || 'La requête a échoué. Réessaie.');
        return data;
    }
    function notice(message, error = false) {
        const box = el('div', undefined, `notice ${error ? 'notice-error' : 'notice-success'} inline`);
        box.append(el('p', message)); $('notice').replaceChildren(box);
    }
    function editor() { return window.tinymce?.get('sr-body'); }
    function body() { const ed = editor(); return ed && !ed.isHidden() ? ed.getContent() : $('body').value; }
    function setBody(value) { $('body').value = value; const ed = editor(); if (ed?.initialized) ed.setContent(value); }
    function message() { return { subject: $('subject').value, body: body(), event: Number($('event').value), user: Number($('preview-user').value) }; }
    function query() { return { role: $('role').value, search: $('search').value, event: Number($('event').value), filters: state.filters, mode: $('mode').value }; }
    function activePending() { return state.campaign?.pending > 0; }
    function buttons() {
        const ready = state.initialized && !state.loading && !state.running;
        $('send').disabled = !ready || !state.selected.size || !state.previewReady || activePending();
        $('send').textContent = state.selected.size ? `Envoyer à ${state.selected.size} personne${state.selected.size > 1 ? 's' : ''}` : 'Envoyer à la sélection';
        $('test').disabled = !ready || !state.previewReady || !state.selected.size;
        $('select-all').disabled = !ready || !state.users.length;
        $('page-select').disabled = !ready || !state.users.length;
        $('insert').disabled = !state.initialized || state.running || !$('variable').value;
        $('resume').hidden = !activePending() || state.running;
        $('cancel').hidden = !activePending() || state.running;
        $('workspace').classList.toggle('sr-sending', state.running);
        // Inert also prevents keyboard editing while an immutable campaign is being sent.
        $('workspace').querySelector('.sr-audience').inert = state.running;
        $('workspace').querySelector('.sr-compose-column > .sr-card').inert = state.running;
    }
    function renderFilters(counts = {}) {
        const container = $('filters'); container.replaceChildren();
        state.definitions.forEach(f => {
            const label = el('label', undefined, 'sr-filter');
            const input = el('input'); input.type = 'checkbox'; input.value = f.key; input.checked = state.filters.includes(f.key); input.disabled = Boolean(f.event && $('event').value === '0');
            if (f.event && $('event').value === '0') label.title = 'Choisis d’abord un spectacle de référence.';
            input.addEventListener('change', () => { state.filters = [...container.querySelectorAll('input:checked')].map(n => n.value); reloadUsers(); });
            label.append(input, el('span', f.label), el('small', String(counts[f.key] || 0))); container.append(label);
        });
    }
    function renderUsers() {
        const rows = $('users'); rows.replaceChildren();
        const pages = Math.max(1, Math.ceil(state.users.length / 20)); state.page = Math.min(state.page, pages);
        const visible = state.users.slice((state.page - 1) * 20, state.page * 20);
        if (!visible.length) { const row = el('tr'); const cell = el('td', 'Aucun utilisateur ne correspond à ces critères. Modifie ou retire un filtre.', 'sr-empty'); cell.colSpan = 4; row.append(cell); rows.append(row); }
        visible.forEach(u => {
            const row = el('tr'); const checkCell = el('th', undefined, 'check-column'); checkCell.scope = 'row';
            const check = el('input'); check.type = 'checkbox'; check.id = `sr-user-${u.id}`; check.checked = state.selected.has(u.id); check.setAttribute('aria-label', `Sélectionner ${u.name}`);
            check.addEventListener('change', () => { check.checked ? state.selected.add(u.id) : state.selected.delete(u.id); selectionChanged(); }); checkCell.append(check);
            const identity = el('td'); const label = el('label', u.name, 'sr-user-label'); label.htmlFor = check.id;
            identity.append(label, el('span', u.email, 'sr-user-email'), el('span', u.roles.join(', '), 'sr-user-role'));
            const missing = el('td');
            u.missing.filter(key => !['no_login', 'never_login', 'stage_opt_in'].includes(key)).forEach(key => { const definition = state.definitions.find(f => f.key === key); if (definition) missing.append(el('span', definition.label, 'sr-badge')); });
            if (!missing.childNodes.length) missing.textContent = '—';
            row.append(checkCell, identity, missing, el('td', u.login)); rows.append(row);
        });
        $('count').textContent = `${state.users.length} utilisateur${state.users.length > 1 ? 's' : ''}`;
        $('page').textContent = `Page ${state.page} sur ${pages}`; $('prev').disabled = state.page <= 1; $('next').disabled = state.page >= pages;
        const selectedVisible = visible.filter(u => state.selected.has(u.id)).length;
        $('page-select').checked = !!visible.length && selectedVisible === visible.length;
        $('page-select').indeterminate = selectedVisible > 0 && selectedVisible < visible.length;
        $('selected').textContent = `${state.selected.size} sélectionné${state.selected.size > 1 ? 's' : ''}`;
    }
    function selectionChanged() {
        renderUsers();
        const previous = Number($('preview-user').value); const users = state.users.filter(u => state.selected.has(u.id));
        $('preview-user').replaceChildren();
        if (!users.length) { const option = new Option('Sélectionne des destinataires', '0'); $('preview-user').add(option); }
        else users.forEach(u => $('preview-user').add(new Option(`${u.name} — ${u.email}`, String(u.id))));
        if (users.some(u => u.id === previous)) $('preview-user').value = String(previous);
        $('preview-user').disabled = !users.length || state.running;
        queuePreview(); buttons();
    }
    async function reloadUsers() {
        const version = ++listVersion; state.loading = true; state.selected.clear(); state.page = 1; state.previewReady = false;
        $('users').setAttribute('aria-busy', 'true'); $('count').textContent = 'Recherche…'; selectionChanged(); buttons();
        try {
            const params = new URLSearchParams({ ...query(), filters: state.filters.join(',') });
            const result = await api(`users?${params}`);
            if (version !== listVersion) return;
            state.users = result.users; state.loading = false;
            renderFilters(result.counts); selectionChanged(); $('users').setAttribute('aria-busy', 'false');
        } catch (error) {
            if (version !== listVersion) return;
            state.users = []; state.loading = true; renderUsers(); $('users').setAttribute('aria-busy', 'false');
            $('count').textContent = 'Chargement impossible'; notice(error.message, true); buttons();
        }
    }
    function renderVariables() {
        const previous = $('variable').value; $('variable').replaceChildren();
        state.variables.forEach(v => { const opt = new Option(`{{${v.key}}} — ${v.label}`, v.key); opt.disabled = !!v.event && $('event').value === '0'; $('variable').add(opt); });
        if (previous && !$('variable').querySelector(`option[value="${CSS.escape(previous)}"]`)?.disabled) $('variable').value = previous;
        if ($('variable').selectedOptions[0]?.disabled) $('variable').selectedIndex = 0;
    }
    function queuePreview() {
        state.previewReady = false; ++previewVersion; clearTimeout(previewTimer); buttons();
        if (!state.selected.size || !Number($('preview-user').value)) { $('preview').replaceChildren(el('p', 'Sélectionne une personne et compose ton message pour afficher l’aperçu.', 'sr-empty')); $('preview').setAttribute('aria-busy', 'false'); return; }
        $('preview').setAttribute('aria-busy', 'true'); previewTimer = setTimeout(updatePreview, 400);
    }
    async function updatePreview() {
        const version = ++previewVersion; const payload = message();
        if (!payload.subject.trim() || !payload.body.trim()) { $('preview').replaceChildren(el('p', 'Renseigne l’objet et le message pour voir l’aperçu.', 'sr-empty')); $('preview').setAttribute('aria-busy', 'false'); return; }
        try {
            const result = await api('preview', payload);
            if (version !== previewVersion) return;
            const meta = el('div', undefined, 'sr-preview-meta'); meta.append(el('p', `À : ${result.to}`), el('strong', result.subject));
            const frame = el('iframe', undefined, 'sr-preview-frame'); frame.title = 'Aperçu du message personnalisé'; frame.setAttribute('sandbox', ''); frame.srcdoc = result.html;
            $('preview').replaceChildren(meta, frame); state.previewReady = true;
        } catch (error) { if (version === previewVersion) { state.previewReady = false; $('preview').replaceChildren(el('p', error.message, 'sr-empty')); } }
        finally { if (version === previewVersion) { $('preview').setAttribute('aria-busy', 'false'); buttons(); } }
    }
    function changed() {
        if (!state.initialized || state.running) return;
        const edit = JSON.stringify([$('subject').value, body(), $('event').value]);
        if (edit === lastEdit) return;
        lastEdit = edit;
        queuePreview(); $('draft-status').textContent = 'Modifications en cours…'; draftDirty = true; clearTimeout(draftTimer); draftTimer = setTimeout(saveDraft, 900);
    }
    async function saveDraft() {
        if (draftWriting || !draftDirty) return;
        draftWriting = true; draftDirty = false;
        try { await api('draft', message()); if (!draftDirty) $('draft-status').textContent = 'Brouillon enregistré.'; }
        catch { $('draft-status').textContent = 'Brouillon non enregistré. Garde cet onglet ouvert.'; }
        finally { draftWriting = false; if (draftDirty) saveDraft(); }
    }
    function bindEditor(ed) {
        if (editorBound || ed.id !== 'sr-body') return;
        editorBound = true;
        ed.on('init', () => { if (state.initialized) ed.setContent($('body').value); });
        ed.on('input change keyup undo redo', changed); ed.on('focus', () => { target = 'body'; });
        ed.on('keydown', closeEmbedded);
    }
    function closeEmbedded(event) {
        if (event.key !== 'Escape' || window.parent === window || $('confirm').open || event.defaultPrevented) return;
        // TinyMCE's own link dialog must handle Escape before the surrounding popup.
        if (editor()?.windowManager.getWindows().length) return;
        event.preventDefault();
        window.parent.postMessage({ type: 'sdr-relances-close' }, window.location.origin);
    }
    function showCampaign(c) {
        state.campaign = c; $('campaign').hidden = false; $('campaign-subject').textContent = c.subject;
        $('progress').max = c.total; $('progress').value = c.total - c.pending;
        $('progress-text').textContent = `${c.accepted} transmis à WordPress sur ${c.total} · ${c.pending} en attente${c.failed ? ` · ${c.failed} en échec` : ''}${c.uncertain ? ` · ${c.uncertain} à vérifier` : ''}${c.cancelled ? ` · ${c.cancelled} annulé(s)` : ''}. ${state.running ? 'Garde cet onglet ouvert pendant l’envoi.' : c.pending ? 'L’envoi peut être repris ci-dessous.' : 'Traitement terminé. La transmission ne garantit pas la réception.'}`;
        $('issues').replaceChildren(...c.issues.map(i => el('p', `${i.name} (${i.email}) : ${i.message}`)));
        buttons();
    }
    async function sendBatches() {
        if (state.running || !state.campaign?.pending) return;
        state.running = true; buttons(); showCampaign(state.campaign);
        try {
            while (state.campaign.pending) showCampaign(await api(`campaigns/${state.campaign.id}/send`, {}));
            notice('Traitement terminé. Consulte le bilan des transmissions ci-dessous.');
        } catch (error) { notice(`${error.message} L’envoi est suspendu. Utilise « Reprendre l’envoi » pour continuer les destinataires restants.`, true); }
        finally { state.running = false; showCampaign(state.campaign); }
    }
    function attach() {
        document.addEventListener('keydown', closeEmbedded);
        $('search').addEventListener('input', () => { clearTimeout(searchTimer); ++listVersion; state.loading = true; state.selected.clear(); selectionChanged(); searchTimer = setTimeout(reloadUsers, 250); });
        ['role', 'mode'].forEach(id => $(id).addEventListener('change', reloadUsers));
        $('event').addEventListener('change', () => { if ($('event').value === '0') state.filters = state.filters.filter(k => !state.definitions.find(f => f.key === k)?.event); renderVariables(); renderFilters(); changed(); reloadUsers(); });
        $('reset').addEventListener('click', () => { state.filters = []; $('search').value = ''; $('role').value = 'members'; $('mode').value = 'all'; reloadUsers(); });
        $('select-all').addEventListener('click', () => { state.users.forEach(u => state.selected.add(u.id)); selectionChanged(); });
        $('clear').addEventListener('click', () => { state.selected.clear(); selectionChanged(); });
        $('page-select').addEventListener('change', e => { state.users.slice((state.page - 1) * 20, state.page * 20).forEach(u => e.target.checked ? state.selected.add(u.id) : state.selected.delete(u.id)); selectionChanged(); });
        $('prev').addEventListener('click', () => { state.page--; renderUsers(); }); $('next').addEventListener('click', () => { state.page++; renderUsers(); });
        $('subject').addEventListener('focus', () => { target = 'subject'; }); $('subject').addEventListener('input', changed);
        $('body').addEventListener('focus', () => { target = 'body'; }); $('body').addEventListener('input', changed);
        $('preview-user').addEventListener('change', queuePreview);
        $('insert').addEventListener('click', () => {
            const token = `{{${$('variable').value}}}`;
            if (target === 'body' && editor() && !editor().isHidden()) { editor().focus(); editor().insertContent(token); }
            else { const field = target === 'subject' ? $('subject') : $('body'); const start = field.selectionStart ?? field.value.length; const end = field.selectionEnd ?? start; field.setRangeText(token, start, end, 'end'); field.focus(); }
            changed();
        });
        $('template').addEventListener('click', () => {
            if ((body().trim() || $('subject').value.trim()) && !window.confirm('Remplacer l’objet et le message actuels par cet exemple ?')) return;
            $('subject').value = 'Ton profil sur {{nom_site}}';
            setBody('<p>Bonjour {{prenom}},</p><p>Un petit rappel pour compléter ta biographie et choisir ta photo de profil dans les Coulisses.</p><p><a href="{{lien_profil}}">Compléter mon profil</a></p><p>Merci et à très vite !<br>L’équipe {{nom_site}}</p>'); changed();
        });
        $('test-help').textContent = `Le test sera envoyé uniquement à ${cfg.email}, avec les valeurs de la personne affichée dans l’aperçu.`;
        $('test').addEventListener('click', async () => {
            $('test').disabled = true;
            try { const result = await api('test', message()); notice(result.message); } catch (error) { notice(error.message, true); }
            finally { buttons(); }
        });
        $('send').addEventListener('click', () => { if ($('send').disabled) return; $('confirm-copy').textContent = `Tu vas envoyer « ${$('subject').value} » à ${state.selected.size} personne${state.selected.size > 1 ? 's' : ''}.`; $('confirm').showModal(); });
        $('confirm-back').addEventListener('click', () => $('confirm').close());
        $('confirm-send').addEventListener('click', async () => {
            $('confirm-send').disabled = true;
            try { const result = await api('campaigns', { ...message(), ...query(), recipients: [...state.selected] }); showCampaign(result); $('confirm').close(); await sendBatches(); }
            catch (error) { $('confirm').close(); notice(error.message, true); }
            finally { $('confirm-send').disabled = false; buttons(); }
        });
        $('resume').addEventListener('click', sendBatches);
        $('cancel').addEventListener('click', async () => {
            if (!window.confirm('Annuler les messages encore en attente ? Les messages déjà transmis resteront envoyés.')) return;
            $('cancel').disabled = true;
            try { showCampaign(await api(`campaigns/${state.campaign.id}/cancel`, {})); notice('Les envois restants ont été annulés.'); } catch (error) { notice(error.message, true); }
            finally { $('cancel').disabled = false; }
        });
        window.addEventListener('beforeunload', e => { if (state.running || draftDirty || draftWriting) { e.preventDefault(); e.returnValue = ''; } });
        if (window.tinymce) { if (editor()) bindEditor(editor()); window.tinymce.on('AddEditor', e => bindEditor(e.editor)); }
    }
    async function init() {
        attach();
        try {
            const data = await api('bootstrap'); state.definitions = data.filters; state.variables = data.variables;
            data.roles.forEach(r => $('role').add(new Option(r.label, r.key))); data.events.forEach(e => $('event').add(new Option(e.label, String(e.id))));
            if (data.draft) { $('subject').value = data.draft.subject; setBody(data.draft.body); if ([...$('event').options].some(o => o.value === String(data.draft.event))) $('event').value = String(data.draft.event); else if (data.draft.event) notice('Le spectacle du brouillon n’est plus disponible. Choisis un nouveau spectacle.', true); $('draft-status').textContent = 'Brouillon restauré.'; }
            lastEdit = JSON.stringify([$('subject').value, body(), $('event').value]);
            state.initialized = true; $('subject').disabled = false; $('variable').disabled = false; renderVariables(); renderFilters();
            $('workspace').setAttribute('aria-busy', 'false'); if (data.campaign) showCampaign(data.campaign); await reloadUsers();
        } catch (error) { notice(`Impossible de charger l’outil : ${error.message} Recharge cette page pour réessayer.`, true); $('workspace').setAttribute('aria-busy', 'false'); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
