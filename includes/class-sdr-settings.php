<?php
/** Portable identity and installation settings. Existing content is never replaced. */
defined('ABSPATH') || exit;

final class SDR_Settings {
    const OPTION = 'sdr_coulisses_settings';

    static function boot() {
        add_action('admin_menu', static function () {
            add_options_page('Coulisse · Gestion de troupe', 'Coulisse', 'manage_options', 'sdr-coulisse', [__CLASS__, 'page']);
        });
        add_action('admin_init', static function () {
            register_setting('sdr_coulisse', self::OPTION, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize'], 'default' => []]);
        });
        add_action('admin_post_sdr_create_workspace', [__CLASS__, 'create_workspace']);
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options') || self::workspace_id()) return;
            echo '<div class="notice notice-info"><p>Coulisse : configure l’identité de la troupe et crée son espace membres dans <a href="'.esc_url(admin_url('options-general.php?page=sdr-coulisse')).'">Réglages → Coulisse</a>.</p></div>';
        });
        add_action('admin_enqueue_scripts', static function ($hook) {
            if ($hook !== 'settings_page_sdr-coulisse') return;
            wp_enqueue_media();
            wp_enqueue_script('sdr-settings', plugins_url('../assets/settings.js', __FILE__), ['jquery'], '1.12.0', true);
        });
        add_filter('body_class', static function ($classes) { if (self::is_workspace()) $classes[] = 'sdr-workspace'; return $classes; });
        add_filter('template_include', static function ($template) {
            if (self::is_workspace() && !current_theme_supports('sdr-coulisses-skin')) return __DIR__.'/../templates/workspace.php';
            return $template;
        }, 99);
    }

    static function all() {
        $defaults = apply_filters('sdr_coulisses_identity_defaults', [
            'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'match_name' => '',
            'logo_id' => 0, 'logo_url' => '', 'accent' => '#6551b5', 'workspace_page' => 0,
            'sender_email' => '', 'sender_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'tagline' => '', 'tagline_note' => '', 'footer' => 'Gestion des spectacles, des disponibilités et des équipes.',
        ]);
        return array_replace($defaults, (array) get_option(self::OPTION, []));
    }

    static function get($key) { return self::all()[$key] ?? ''; }
    static function name() { return self::get('name') ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES); }
    static function match_name() { return self::get('match_name') ?: self::name(); }
    static function identity() {
        $settings = self::all();
        $id = (int) $settings['logo_id'];
        return ['name' => self::name(), 'matchName' => self::match_name(),
            'logo' => $id < 0 ? '' : ($id ? (wp_get_attachment_image_url($id, 'medium') ?: '') : $settings['logo_url']),
            'accent' => $settings['accent'], 'tagline' => $settings['tagline'], 'taglineNote' => $settings['tagline_note'], 'footer' => $settings['footer']];
    }

    static function workspace_id() {
        $id = (int) self::get('workspace_page');
        $page = $id ? get_post($id) : get_page_by_path('coulisses');
        return $page && $page->post_type === 'page' && $page->post_status === 'publish'
            && (has_block('sdr/coulisses', $page->post_content) || has_shortcode($page->post_content, 'sdr_coulisses')) ? (int) $page->ID : 0;
    }
    static function workspace_url($args = []) {
        $id = self::workspace_id();
        $url = $id ? get_permalink($id) : home_url('/coulisses/');
        return $args ? add_query_arg($args, $url) : $url;
    }
    static function is_workspace() {
        if (!is_singular('page')) return false;
        $page = get_queried_object();
        return $page instanceof WP_Post && ($page->ID === self::workspace_id() || has_block('sdr/coulisses', $page->post_content) || has_shortcode($page->post_content, 'sdr_coulisses'));
    }

    static function sanitize($input) {
        $old = (array) get_option(self::OPTION, []);
        if (!is_array($input)) return $old;
        $clean = [];
        foreach (['name', 'match_name', 'sender_name'] as $key) {
            if (!is_string($input[$key] ?? '')) return $old;
            $clean[$key] = sanitize_text_field(mb_substr($input[$key] ?? '', 0, 150));
        }
        if ($clean['name'] === '') { add_settings_error(self::OPTION, 'name', 'Indique le nom de la troupe.'); return $old; }
        $accent = is_string($input['accent'] ?? '') ? sanitize_hex_color($input['accent'] ?? '') : null;
        if (!$accent) { add_settings_error(self::OPTION, 'accent', 'Choisis une couleur valide.'); return $old; }
        $clean['accent'] = $accent;
        $email = $input['sender_email'] ?? '';
        if (!is_string($email) || preg_match('/[\r\n]/', $email) || ($email !== '' && !is_email($email))) { add_settings_error(self::OPTION, 'sender', 'L’adresse d’expédition doit être un email valide.'); return $old; }
        $clean['sender_email'] = sanitize_email($email);
        $logo = filter_var($input['logo_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($logo === false || $logo < -1 || ($logo > 0 && !wp_attachment_is_image($logo))) { add_settings_error(self::OPTION, 'logo', 'Choisis une image dans la médiathèque.'); return $old; }
        $clean['logo_id'] = $logo;
        $page = filter_var($input['workspace_page'] ?? 0, FILTER_VALIDATE_INT);
        if ($page === false || $page < 0) { add_settings_error(self::OPTION, 'page', 'Choisis une page valide.'); return $old; }
        if ($page) {
            $post = get_post($page);
            if (!$post || $post->post_type !== 'page' || $post->post_status !== 'publish' || (!has_block('sdr/coulisses', $post->post_content) && !has_shortcode($post->post_content, 'sdr_coulisses'))) {
                add_settings_error(self::OPTION, 'page', 'Choisis une page publiée contenant le bloc « Coulisses de la troupe » ou le shortcode [sdr_coulisses].'); return $old;
            }
        }
        $clean['workspace_page'] = $page;
        return $clean;
    }

    static function ensure_workspace() {
        if ($id = self::workspace_id()) return $id;
        $id = wp_insert_post(wp_slash(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Les Coulisses', 'post_name' => 'coulisses', 'post_content' => '<!-- wp:sdr/coulisses /-->']), true);
        if (is_wp_error($id)) return $id;
        // The Settings API also sanitizes programmatic updates in admin-post.php.
        $settings = self::all();
        $settings['workspace_page'] = $id;
        update_option(self::OPTION, $settings, false);
        return $id;
    }
    static function create_workspace() {
        if (!current_user_can('manage_options')) wp_die('Action réservée à l’administration.', '', ['response' => 403]);
        check_admin_referer('sdr_create_workspace');
        $id = self::ensure_workspace();
        if (is_wp_error($id)) wp_die(esc_html($id->get_error_message()));
        wp_safe_redirect(admin_url('options-general.php?page=sdr-coulisse&workspace=created')); exit;
    }
    static function mail_headers($headers) {
        $email = self::get('sender_email');
        if ($email && is_email($email)) $headers[] = 'From: '.sanitize_text_field(self::get('sender_name') ?: self::name()).' <'.$email.'>';
        return $headers;
    }

    static function page() {
        if (!current_user_can('manage_options')) return;
        $s = self::all(); $identity = self::identity();
        echo '<div class="wrap"><h1>Coulisse · Gestion de troupe</h1><p>Configure l’identité de la troupe et son espace membres. Le thème du site public conserve sa présentation.</p>';
        settings_errors();
        SDR_Setup::render();
        if (isset($_GET['workspace']) && $_GET['workspace'] === 'created') echo '<div class="notice notice-success"><p>La page des Coulisses est prête. Aucun contenu existant n’a été remplacé.</p></div>';
        echo '<form action="options.php" method="post">';settings_fields('sdr_coulisse');
        echo '<table class="form-table"><tbody>';
        foreach (['name' => 'Nom de la troupe', 'match_name' => 'Nom court pour les rencontres', 'sender_name' => 'Nom d’expédition des emails', 'sender_email' => 'Adresse d’expédition des emails'] as $key => $label) {
            echo '<tr><th><label for="sdr-'.$key.'">'.esc_html($label).'</label></th><td><input class="regular-text" type="'.($key === 'sender_email' ? 'email' : 'text').'" id="sdr-'.$key.'" name="'.self::OPTION.'['.$key.']" value="'.esc_attr($s[$key]).'" '.($key === 'name' ? 'required' : '').'>';
            if ($key === 'match_name') echo '<p class="description">Facultatif. Utilisé dans les titres automatiques avec d’autres troupes, en mode « Avec » ou « VS ».</p>';
            if ($key === 'sender_email') echo '<p class="description">Facultative : vide, les réglages d’email du site sont utilisés. Une adresse personnalisée doit être autorisée par ton hébergeur ou ton service d’envoi. Ce réglage ne concerne que les emails de Coulisse.</p>';
            echo '</td></tr>';
        }
        echo '<tr><th>Logo de la troupe</th><td><input type="hidden" id="sdr-logo-id" name="'.self::OPTION.'[logo_id]" value="'.esc_attr($s['logo_id']).'"><img id="sdr-logo-preview" src="'.esc_url($identity['logo']).'" alt="" style="max-width:140px;max-height:100px;display:'.($identity['logo'] ? 'block' : 'none').';margin-bottom:12px"><button type="button" class="button" id="sdr-logo-choose">Choisir un logo</button> <button type="button" class="button" id="sdr-logo-remove">Afficher seulement le nom</button></td></tr>';
        echo '<tr><th><label for="sdr-accent">Couleur de l’espace membres</label></th><td><input id="sdr-accent" type="color" name="'.self::OPTION.'[accent]" value="'.esc_attr($s['accent']).'"></td></tr>';
        echo '<tr><th><label for="sdr-page">Page des Coulisses</label></th><td><select id="sdr-page" name="'.self::OPTION.'[workspace_page]"><option value="0">Détection de la page « coulisses »</option>';
        foreach (get_pages(['post_status' => 'publish']) as $page) {
            if (!has_block('sdr/coulisses', $page->post_content) && !has_shortcode($page->post_content, 'sdr_coulisses')) continue;
            echo '<option value="'.$page->ID.'" '.selected((int) $s['workspace_page'], (int) $page->ID, false).'>'.esc_html($page->post_title).'</option>';
        }
        echo '</select><p class="description">Seules les pages publiées contenant le bloc des Coulisses sont proposées. Leurs visiteurs doivent se connecter avec un compte membre.</p></td></tr></tbody></table>';
        submit_button('Enregistrer les réglages');echo '</form><hr><h2>Espace membres</h2>';
        if (self::workspace_id()) echo '<p><a class="button button-primary" href="'.esc_url(self::workspace_url()).'">Ouvrir les Coulisses</a> <a class="button" href="'.esc_url(get_edit_post_link(self::workspace_id())).'">Modifier la page</a></p><p>La page est prête. Crée ou configure les comptes Membre de la troupe et Organisation dans Comptes / Utilisateurs.</p>';
        else { echo '<p>Crée une page dédiée à l’espace membres. Cette action ne change ni l’accueil ni le menu du site.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sdr_create_workspace">';wp_nonce_field('sdr_create_workspace');submit_button('Créer la page des Coulisses', 'primary', 'submit', false);echo '</form>'; }
        echo '<h2>Vérifier les emails</h2><p>Dans « Modèles d’emails », ouvre un modèle puis utilise « M’envoyer cet aperçu ». Le test part uniquement vers ton compte administrateur et ne contacte aucun membre de la troupe.</p></div>';
    }
}
SDR_Settings::boot();
