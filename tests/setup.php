<?php
/** Local WordPress only. No real email or support request. */
if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() !== 'local' && !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local'))) throw new RuntimeException('Installation locale uniquement.');
$original = get_current_user_id(); $settings = get_option(SDR_Settings::OPTION, null); $posts = []; $users = []; $checks = 0; $mails = 0;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$block = static function () use (&$mails) { $mails++; return true; };
add_filter('pre_wp_mail', $block, PHP_INT_MAX);
try {
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach (['administrator', 'sdr_member', 'sdr_organizer', 'subscriber'] as $role) {
        $login = 'setup-'.wp_generate_password(10, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => wp_generate_password(40), 'role' => $role]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message()); $users[] = $id;
    }
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'NOM PRIVÉ TEST', 'post_content' => '<!-- wp:sdr/coulisses /-->']); $posts[] = $page;
    update_option(SDR_Settings::OPTION, SDR_Settings::sanitize(['name' => 'NOM PRIVÉ TEST', 'match_name' => '', 'sender_name' => 'NOM PRIVÉ TEST', 'sender_email' => 'adresse-privee@example.org', 'accent' => '#123456', 'logo_id' => -1, 'workspace_page' => $page]));
    $before = get_option(SDR_Settings::OPTION); wp_set_current_user($users[0]);
    $list = array_column(SDR_Setup::checks(), null, 'id');
    foreach (['wordpress', 'php', 'storage', 'roles', 'workspace', 'member', 'organizer'] as $key) $assert($list[$key]['status'] === 'ok', 'Configuration prête : '.$key);
    $assert($list['email']['status'] === 'manual', 'La réception des emails reste un essai manuel');
    $assert(get_option(SDR_Settings::OPTION) === $before, 'Les contrôles ne changent pas les réglages');
    $report = SDR_Setup::report(); $json = wp_json_encode($report);
    $assert($report['version'] === get_file_data(dirname(__DIR__).'/sdr-coulisses.php', ['version' => 'Version'])['version'], 'Version extraite du plugin distribué');
    $assert($report['checks']['email'] === 'manual', 'Le rapport ne prétend pas avoir validé la réception');
    foreach (['NOM PRIVÉ TEST', 'adresse-privee', home_url(), ABSPATH] as $private) $assert(!str_contains($json, $private), 'Aucune donnée personnelle, URL de site ou chemin dans le rapport');
    $assert(array_keys($report) === ['product', 'version', 'wordpress', 'php', 'theme', 'checks'], 'Rapport limité à une liste explicite de données techniques');
    ob_start(); SDR_Setup::render(); $html = ob_get_clean();
    $assert(str_contains($html, 'Bien démarrer') && str_contains($html, 'Copier le rapport technique'), 'Parcours de départ et copie du rapport visibles');
    $assert(str_contains($html, 'Rien n’est transmis automatiquement'), 'Partage du rapport volontaire et expliqué');
    $assert(str_contains($html, 'readonly') && str_contains($html, 'sdr-setup-report'), 'Rapport disponible à la sélection manuelle');
    wp_update_post(['ID' => $page, 'post_status' => 'draft']);
    $list = array_column(SDR_Setup::checks(), null, 'id');
    $assert($list['workspace']['status'] === 'action', 'Page privée non publiée détectée');
    global $wpdb; $table = $wpdb->prefix.'sdr_responses';
    $missing = static fn($query) => str_starts_with($query, 'SHOW TABLES LIKE') && str_contains($query, 'sdr') ? "SELECT ''" : $query;
    add_filter('query', $missing);
    try { $list = array_column(SDR_Setup::checks(), null, 'id'); $assert($list['storage']['status'] === 'action', 'Table de réponses manquante détectée sans création automatique'); }
    finally { remove_filter('query', $missing); }
    $no_caps = static function ($caps, $cap, $name) { if ($name === 'sdr_organizer') $caps['sdr_organize'] = false; return $caps; };
    add_filter('role_has_cap', $no_caps, 10, 3);
    try { $list = array_column(SDR_Setup::checks(), null, 'id'); $assert($list['roles']['status'] === 'action', 'Droits d’organisation incomplets détectés'); }
    finally { remove_filter('role_has_cap', $no_caps, 10); }
    foreach (array_merge(array_slice($users, 1), [0]) as $uid) {
        wp_set_current_user($uid); $result = SDR_Setup::report();
        $assert(is_wp_error($result) && $result->get_error_data()['status'] === ($uid ? 403 : 401), 'Rapport refusé hors administration');
        ob_start(); SDR_Setup::render(); $html = ob_get_clean(); $assert($html === '', 'Aucun rapport affiché hors administration');
    }
    $assert($mails === 0, 'Aucun email envoyé par les contrôles et le rapport');
    WP_CLI::success($checks.' vérifications de démarrage réussies. Aucun email réel.');
} finally {
    foreach ($posts as $id) wp_delete_post($id, true);
    foreach ($users as $id) wp_delete_user($id);
    if ($settings === null) delete_option(SDR_Settings::OPTION); else update_option(SDR_Settings::OPTION, $settings, false);
    wp_set_current_user($original); remove_filter('pre_wp_mail', $block, PHP_INT_MAX);
}
