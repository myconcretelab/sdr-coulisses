<?php
/** First-install checks and a deliberately limited support report. No email or network calls. */
defined('ABSPATH') || exit;

final class SDR_Setup {
    static function boot() {
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook === 'settings_page_sdr-coulisse') wp_enqueue_style('sdr-setup', plugins_url('../assets/setup.css', __FILE__), [], '1.12.0');
        });
    }

    static function version() {
        return get_file_data(__DIR__.'/../sdr-coulisses.php', ['version' => 'Version'])['version'];
    }

    static function checks() {
        global $wp_version, $wpdb;
        $workspace = SDR_Settings::workspace_id();
        $table = $wpdb->prefix.'sdr_responses';
        $storage = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        $member_role = get_role('sdr_member'); $organizer_role = get_role('sdr_organizer');
        $roles = $member_role && $member_role->has_cap('sdr_member') && $organizer_role && $organizer_role->has_cap('sdr_organize');
        $members = (bool) get_users(['role' => 'sdr_member', 'fields' => 'ID', 'number' => 1]);
        $organizers = (bool) get_users(['role' => 'sdr_organizer', 'fields' => 'ID', 'number' => 1]);
        return [
            ['id' => 'wordpress', 'label' => 'WordPress', 'status' => version_compare($wp_version, '6.5', '>=') ? 'ok' : 'action', 'detail' => 'Version '.$wp_version.' ; version minimale : 6.5.'],
            ['id' => 'php', 'label' => 'PHP', 'status' => version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'action', 'detail' => 'Version '.PHP_VERSION.' ; version minimale : 8.1.'],
            ['id' => 'storage', 'label' => 'Stockage des réponses', 'status' => $storage ? 'ok' : 'action', 'detail' => $storage ? 'La table des disponibilités est disponible.' : 'La table des disponibilités manque. Après sauvegarde, désactive puis réactive Coulisse. Si le problème persiste, contacte la personne qui administre le site.'],
            ['id' => 'roles', 'label' => 'Droits de la troupe', 'status' => $roles ? 'ok' : 'action', 'detail' => $roles ? 'Les rôles Membre de la troupe et Organisation sont disponibles.' : 'Un rôle ou ses droits manque. Après sauvegarde, désactive puis réactive Coulisse.'],
            ['id' => 'workspace', 'label' => 'Page des Coulisses', 'status' => $workspace ? 'ok' : 'action', 'detail' => $workspace ? 'Une page publiée contient l’espace membres. La connexion est requise.' : 'Crée la page des Coulisses ci-dessous ou choisis une page publiée contenant son bloc.'],
            ['id' => 'member', 'label' => 'Premier compte membre', 'status' => $members ? 'ok' : 'todo', 'detail' => $members ? 'Au moins un compte Membre de la troupe existe.' : 'Pour le premier essai, crée un compte Membre de la troupe dans Comptes / Utilisateurs. Ouvre son profil et choisis ses rôles artistiques.', 'url' => admin_url('users.php'), 'link' => 'Gérer les comptes'],
            ['id' => 'organizer', 'label' => 'Compte d’organisation', 'status' => $organizers ? 'ok' : 'todo', 'detail' => $organizers ? 'Au moins un compte d’organisation existe.' : 'Un administrateur peut organiser les spectacles. Pour déléguer, crée un compte Organisation dans Comptes / Utilisateurs.', 'url' => admin_url('users.php'), 'link' => 'Gérer les comptes'],
            ['id' => 'email', 'label' => 'Réception des emails', 'status' => 'manual', 'detail' => 'Ouvre un modèle, utilise « M’envoyer cet aperçu », puis vérifie ta boîte de réception et les indésirables. Cette vérification ne peut pas confirmer automatiquement la réception.', 'url' => admin_url('edit.php?post_type=sdr_email_template'), 'link' => 'Ouvrir les modèles d’emails'],
        ];
    }

    static function report() {
        if (!current_user_can('manage_options')) return new WP_Error('sdr_setup_forbidden', 'Le rapport d’installation est réservé à l’administration.', ['status' => is_user_logged_in() ? 403 : 401]);
        global $wp_version;
        $checks = self::checks(); $theme = wp_get_theme();
        return [
            'product' => 'Coulisse', 'version' => self::version(), 'wordpress' => $wp_version, 'php' => PHP_VERSION,
            'theme' => ['slug' => $theme->get_stylesheet(), 'version' => $theme->get('Version'), 'troupe_skin' => current_theme_supports('sdr-coulisses-skin')],
            'checks' => array_column($checks, 'status', 'id'),
        ];
    }

    static function render() {
        if (!current_user_can('manage_options')) return;
        $checks = self::checks(); $labels = ['ok' => 'Prêt', 'action' => 'À corriger', 'todo' => 'À préparer', 'manual' => 'À tester'];
        $issues = count(array_filter($checks, static fn($check) => $check['status'] === 'action'));
        echo '<section class="sdr-setup" aria-labelledby="sdr-setup-title"><header><div><h2 id="sdr-setup-title">Bien démarrer</h2><p>Vérifie la configuration, puis fais un essai avec un compte membre avant d’inviter toute la troupe.</p></div><span class="sdr-setup-version">Coulisse '.esc_html(self::version()).'</span></header>';
        echo '<p class="sdr-setup-summary">'.esc_html($issues ? $issues.' point(s) de configuration à corriger.' : 'Les prérequis contrôlés sont prêts. Termine les essais de connexion et de réception des emails.').'</p><ul class="sdr-setup-checks">';
        foreach ($checks as $check) {
            echo '<li><span class="sdr-setup-status status-'.esc_attr($check['status']).'">'.esc_html($labels[$check['status']]).'</span><div><strong>'.esc_html($check['label']).'</strong><p>'.esc_html($check['detail']).'</p>';
            if (!empty($check['url'])) echo '<a href="'.esc_url($check['url']).'">'.esc_html($check['link']).' →</a>';
            echo '</div></li>';
        }
        echo '</ul><details class="sdr-setup-support"><summary>Préparer un retour ou signaler un problème</summary><p>Décris l’action effectuée, le résultat attendu et le message affiché. Le rapport ci-dessous contient uniquement des versions et des états de configuration : aucun nom de membre, email, adresse de site, chemin serveur ou contenu de spectacle.</p><label for="sdr-setup-report">Rapport technique à joindre au signalement</label><textarea id="sdr-setup-report" rows="12" readonly spellcheck="false">'.esc_textarea(wp_json_encode(self::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</textarea><p><button type="button" class="button" id="sdr-copy-report">Copier le rapport technique</button> <a class="button" href="https://github.com/myconcretelab/sdr-coulisses/issues/new/choose" target="_blank" rel="noopener noreferrer">Signaler sur GitHub ↗</a></p><p id="sdr-copy-status" role="status" aria-live="polite"></p><p>Rien n’est transmis automatiquement. Vérifie aussi les captures avant de les partager : elles peuvent contenir des informations sur les membres.</p></details></section>';
    }
}
SDR_Setup::boot();
