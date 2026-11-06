<?php
/** Portable installation and compatibility — local WordPress only, emails intercepted. */
if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() !== 'local' && !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local'))) throw new RuntimeException('Installation locale uniquement.');
$original_settings = get_option(SDR_Settings::OPTION, null); $original_user = get_current_user_id();
$posts = []; $users = []; $checks = 0; $mails = [];
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$block = static function ($pre, $mail) use (&$mails) { $mails[] = $mail; return true; };
add_filter('pre_wp_mail', $block, PHP_INT_MAX, 2);
remove_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10);
try {
    require_once ABSPATH.'wp-admin/includes/user.php';
    delete_option(SDR_Settings::OPTION);
    if (current_theme_supports('sdr-coulisses-skin')) {
        $assert(SDR_Settings::name() === 'Les Sorties de Route', 'Identité SDR conservée par le thème');
        $assert(str_contains(SDR_Settings::identity()['logo'], '/themes/sdr-scene/'), 'Logo SDR fourni par son thème');
    } else {
        $assert(SDR_Settings::name() === wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'Nom du site comme identité par défaut');
        $assert(SDR_Settings::identity()['logo'] === '', 'Aucun logo SDR sur une installation standard');
        $assert(apply_filters('nsl_is_register_allowed', true) === true, 'Les inscriptions externes du site ne sont pas bloquées');
    }
    if (!get_page_by_path('coulisses')) {
        $occupied = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page à préserver', 'post_name' => 'coulisses', 'post_content' => 'Contenu existant']); $posts[] = $occupied;
        add_filter('sanitize_option_'.SDR_Settings::OPTION, ['SDR_Settings', 'sanitize']);
        try {
            $created = SDR_Settings::ensure_workspace(); $posts[] = $created;
            $assert(!is_wp_error($created) && SDR_Settings::workspace_id() === $created, 'Création avant réglages : page mémorisée avec la validation administrative');
            $assert(get_post_field('post_content', $occupied) === 'Contenu existant' && get_post_field('post_name', $created) !== 'coulisses', 'Un slug déjà occupé ne provoque aucun remplacement');
        } finally { remove_filter('sanitize_option_'.SDR_Settings::OPTION, ['SDR_Settings', 'sanitize']); delete_option(SDR_Settings::OPTION); }
    }
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Espace de test', 'post_name' => 'espace-portabilite', 'post_content' => '<!-- wp:sdr/coulisses /-->']); $posts[] = $page;
    $public = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page publique test', 'post_content' => 'Texte à conserver']); $posts[] = $public;
    $settings = ['name' => 'Les Traverseurs', 'match_name' => 'Traverseurs', 'sender_name' => 'La troupe', 'sender_email' => 'troupe@example.org', 'accent' => '#126654', 'logo_id' => -1, 'workspace_page' => $page];
    update_option(SDR_Settings::OPTION, SDR_Settings::sanitize($settings));
    $assert(SDR_Settings::name() === 'Les Traverseurs' && SDR_Settings::identity()['logo'] === '', 'Identité personnalisée sans image');
    $assert(SDR_Settings::workspace_id() === $page && SDR_Settings::ensure_workspace() === $page, 'Page choisie conservée, création idempotente');
    $assert(SDR_Settings::workspace_url(['vue' => 'profil']) === add_query_arg('vue', 'profil', get_permalink($page)), 'Liens adaptés à une page sans slug imposé');
    $assert(str_contains(SDR_Relances::workspace_url(), 'sdr-relances=1') && str_contains(SDR_Relances::workspace_url(), wp_parse_url(get_permalink($page), PHP_URL_PATH)), 'Relances intégrées reliées à la bonne page');
    foreach ([['name' => ''], ['accent' => 'red; display:none'], ['sender_email' => "bad\n@example.org"], ['logo_id' => $public], ['workspace_page' => $public], ['workspace_page' => []], ['workspace_page' => -1], ['name' => []]] as $invalid) {
        $assert(SDR_Settings::sanitize(array_replace($settings, $invalid)) === get_option(SDR_Settings::OPTION), 'Réglages invalides refusés sans écraser les précédents');
    }
    $assert(get_post_field('post_content', $public) === 'Texte à conserver', 'Les pages publiques existantes ne sont pas réécrites');
    $login = 'coulisse-test-'.wp_generate_password(12, false, false);
    $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(40), 'user_email' => $login.'@example.invalid', 'role' => 'subscriber']); $users[] = $uid;
    $assert(apply_filters('login_redirect', home_url('/autre-espace/'), '', get_userdata($uid)) === home_url('/autre-espace/'), 'Les connexions des non-membres ne sont pas redirigées');
    wp_set_current_user($uid);
    $assert(apply_filters('show_admin_bar', true) === true, 'Les réglages de barre d’administration des non-membres sont conservés');
    $assert(apply_filters('wp_mail_from', 'autre@example.org') === 'autre@example.org', 'Les expéditeurs des autres extensions restent inchangés');
    $headers = SDR_Settings::mail_headers(['Content-Type: text/plain; charset=UTF-8']);
    $assert(in_array('From: La troupe <troupe@example.org>', $headers, true), 'Expéditeur appliqué seulement aux emails du plugin');
    $context = SDR_Email_Context::build('message');
    $assert($context['vars']['nom_troupe'] === 'Les Traverseurs', 'Nom de troupe disponible dans les modèles');
    $default = SDR_Email_Renderer::render(SDR_Email_Defaults::snapshot('message'), SDR_Email_Context::build('message', 0, null, ['message' => '<p>Essai</p>', 'subject' => 'Essai']));
    $assert(!is_wp_error($default) && str_contains($default['html'], 'Les Traverseurs') && !str_contains($default['html'], 'Sorties de Route'), 'Modèle de départ neutre et personnalisé');
    $registry = WP_Block_Type_Registry::get_instance();
    foreach (['sdr/details', 'sdr/related-events', 'sdr-scene/details', 'sdr-scene/related-events'] as $name) $assert($registry->is_registered($name), 'Bloc disponible et compatible : '.$name);
    $assert(get_post_type_object('sdr_venue')->template === [['sdr/details'], ['sdr/related-events']], 'Les nouvelles fiches utilisent les blocs du plugin');
    $venue = wp_insert_post(['post_type' => 'sdr_venue', 'post_status' => 'publish', 'post_title' => 'Lieu test', 'meta_input' => ['_sdr_address' => 'Adresse publique', '_sdr_contact' => 'CONTACT PRIVÉ TEST']]); $posts[] = $venue;
    $html = SDR_Public_Blocks::details([], '', (object) ['context' => ['postId' => $venue]]);
    $assert(str_contains($html, 'Adresse publique') && !str_contains($html, 'CONTACT PRIVÉ TEST'), 'Bloc public indépendant du thème et sans contact privé');
    $previous_query = $GLOBALS['wp_query']; $previous_main = $GLOBALS['wp_the_query']; $previous_post = $GLOBALS['post'] ?? null;
    try {
        $GLOBALS['wp_query'] = new WP_Query(['post_type' => 'sdr_venue', 'p' => $venue]);
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $GLOBALS['wp_query']->the_post();
        $rendered = apply_filters('the_content', 'Texte public');
        $assert(str_contains($rendered, 'Adresse publique') && str_contains($rendered, 'Texte public') && !str_contains($rendered, 'CONTACT PRIVÉ TEST'), 'Fiche créée sans blocs : informations pratiques publiques ajoutées');
        $assert(get_post_field('post_content', $venue) === '', 'Le rendu public ne réécrit pas le contenu enregistré');
        wp_update_post(['ID' => $venue, 'post_content' => '<!-- wp:sdr-scene/details /-->']);
        $GLOBALS['wp_query']->post = get_post($venue); $GLOBALS['post'] = get_post($venue);
        $rendered = apply_filters('the_content', get_post_field('post_content', $venue));
        $assert(substr_count($rendered, 'Adresse publique') === 1, 'Un ancien bloc placé dans le contenu ne produit aucun doublon');
    } finally {
        $GLOBALS['wp_query'] = $previous_query; $GLOBALS['wp_the_query'] = $previous_main; $GLOBALS['post'] = $previous_post;
        if ($previous_post) setup_postdata($previous_post);
    }
    $assert(count($mails) === 0, 'Configuration et lecture sans email');
    WP_CLI::success($checks.' vérifications de portabilité réussies.');
} finally {
    foreach (array_reverse($posts) as $id) wp_delete_post($id, true);
    foreach ($users as $id) wp_delete_user($id);
    if ($original_settings === null) delete_option(SDR_Settings::OPTION); else update_option(SDR_Settings::OPTION, $original_settings, false);
    wp_set_current_user($original_user);
    remove_filter('pre_wp_mail', $block, PHP_INT_MAX);
    add_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10, 3);
}
