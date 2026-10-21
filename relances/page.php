<?php defined('ABSPATH') || exit; ?>
<div class="wrap sdr-relances" id="sdr-relances">
    <h1>Relances utilisateurs</h1>
    <p class="sdr-intro">Retrouve les profils à compléter et adresse un message personnel à chaque membre.</p>
    <div id="sr-notice" role="status" aria-live="polite"></div>
    <noscript><div class="notice notice-error"><p>Active JavaScript pour filtrer les utilisateurs et composer les messages.</p></div></noscript>
    <div class="sr-workspace" id="sr-workspace" aria-busy="true">
        <section class="sr-card sr-audience" aria-labelledby="sr-audience-title">
            <div class="sr-card-heading"><span class="sr-step">1</span><div><h2 id="sr-audience-title">Choisir les destinataires</h2><p>Filtre les profils, puis sélectionne les personnes à contacter.</p></div></div>
            <div class="sr-filter-top">
                <label>Rechercher<input type="search" id="sr-search" placeholder="Nom, prénom ou email…" autocomplete="off"></label>
                <label>Rôle WordPress<select id="sr-role"><option value="members">Membres et organisation SDR</option><option value="all">Tous les utilisateurs</option></select></label>
            </div>
            <div class="sr-event"><label for="sr-event">Spectacle de référence <span class="sr-optional">facultatif</span></label><select id="sr-event"><option value="0">Aucun spectacle sélectionné</option></select><p class="description">Utilisé par les filtres de spectacle et les variables de date, de lieu et de compte à rebours.</p></div>
            <fieldset class="sr-filters"><legend>Profils à relancer</legend><div id="sr-filters"><p class="description">Chargement des filtres…</p></div></fieldset>
            <div class="sr-filter-mode"><label for="sr-mode">Correspondance</label> <select id="sr-mode"><option value="all">Tous les filtres cochés (ET)</option><option value="any">Au moins un filtre coché (OU)</option></select><button class="button-link" type="button" id="sr-reset">Réinitialiser</button></div>
            <p class="description sr-login-note">Les connexions déjà enregistrées par l’extension d’invitations et les sessions WordPress sont reprises. Pour les anciens comptes sans historique, la première connexion reste inconnue. Les comptes de démonstration sont exclus.</p>
            <div class="sr-results-toolbar"><strong id="sr-count" aria-live="polite">Chargement…</strong><span id="sr-selected">0 sélectionné</span></div>
            <div class="sr-selection-actions"><button type="button" class="button" id="sr-select-all" disabled>Tout sélectionner dans les résultats</button><button type="button" class="button-link" id="sr-clear">Vider la sélection</button></div>
            <div class="sr-table-wrap"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="sr-page-select" aria-label="Sélectionner cette page"></td><th scope="col">Utilisateur</th><th scope="col">À compléter</th><th scope="col">Dernière connexion connue</th></tr></thead><tbody id="sr-users"><tr><td colspan="4">Chargement des utilisateurs…</td></tr></tbody></table></div>
            <div class="sr-pagination"><button type="button" class="button" id="sr-prev" aria-label="Page précédente">‹</button><span id="sr-page">Page 1</span><button type="button" class="button" id="sr-next" aria-label="Page suivante">›</button></div>
        </section>
        <div class="sr-compose-column">
            <section class="sr-card" aria-labelledby="sr-compose-title">
                <div class="sr-card-heading"><span class="sr-step">2</span><div><h2 id="sr-compose-title">Composer le message</h2><p>Chaque destinataire reçoit son propre email personnalisé.</p></div></div>
                <label for="sr-subject">Objet de l’email</label><input id="sr-subject" type="text" maxlength="200" class="widefat" placeholder="Un petit point avant {{spectacle}}" disabled>
                <div class="sr-variable-tools"><label for="sr-variable">Insérer une variable</label><div><select id="sr-variable" disabled></select><button type="button" id="sr-insert" class="button" disabled>Insérer</button></div><p class="description" id="sr-variable-help">Clique dans l’objet ou le message pour choisir où insérer la variable.</p></div>
                <label for="sr-body" class="sr-editor-label">Message</label>
                <?php wp_editor('', 'sr-body', ['textarea_name' => 'sr-body', 'textarea_rows' => 12, 'media_buttons' => false, 'teeny' => false, 'quicktags' => true, 'tinymce' => ['toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo', 'toolbar2' => '', 'block_formats' => 'Paragraphe=p;Titre 2=h2;Titre 3=h3']]); ?>
                <div class="sr-draft-line"><span id="sr-draft-status" role="status">Le brouillon sera enregistré automatiquement.</span><button type="button" class="button-link" id="sr-template">Exemple de relance</button></div>
            </section>
            <section class="sr-card" aria-labelledby="sr-preview-title">
                <div class="sr-card-heading"><span class="sr-step">3</span><div><h2 id="sr-preview-title">Prévisualiser et envoyer</h2><p>L’aperçu utilise les vraies valeurs du destinataire choisi.</p></div></div>
                <label for="sr-preview-user">Voir le message pour</label><select id="sr-preview-user" disabled><option value="0">Sélectionne des destinataires</option></select>
                <div id="sr-preview" aria-busy="false"><p class="sr-empty">Sélectionne une personne et compose ton message pour afficher l’aperçu.</p></div>
                <div class="sr-send-actions"><button type="button" class="button" id="sr-test" disabled>M’envoyer un test</button><button type="button" class="button button-primary" id="sr-send" disabled>Envoyer à la sélection</button></div>
                <p class="description" id="sr-test-help"></p>
                <div id="sr-campaign" hidden><h3>Suivi de l’envoi</h3><p id="sr-campaign-subject"></p><progress id="sr-progress" max="1" value="0" aria-label="Progression de l’envoi"></progress><p id="sr-progress-text" role="status" aria-live="polite"></p><div id="sr-issues"></div><button type="button" class="button" id="sr-resume" hidden>Reprendre l’envoi</button> <button type="button" class="button-link-delete" id="sr-cancel" hidden>Annuler les envois restants</button></div>
            </section>
        </div>
    </div>
    <dialog id="sr-confirm" aria-labelledby="sr-confirm-title"><h2 id="sr-confirm-title">Confirmer l’envoi</h2><p id="sr-confirm-copy"></p><p>Un email distinct sera transmis pour chaque personne. Vérifie l’aperçu et, si besoin, envoie-toi un test avant de confirmer.</p><div class="sr-send-actions"><button type="button" class="button" id="sr-confirm-back">Revenir au message</button><button type="button" class="button button-primary" id="sr-confirm-send">Confirmer l’envoi</button></div></dialog>
</div>
