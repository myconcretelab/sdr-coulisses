<?php
/** wp eval-file tests/documentation.php — local only; no real emails. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$users = []; $checks = 0; $mails = 0; $original = get_current_user_id();
$assert = static function ($condition, $message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; };
$block = static function () use (&$mails) { $mails++; return true; };
add_filter('pre_wp_mail', $block, PHP_INT_MAX);
try {
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach (['subscriber', 'sdr_member', 'sdr_organizer', 'administrator'] as $role) {
        $login = 'sdr-doc-'.wp_generate_password(12, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => wp_generate_password(40), 'role' => $role]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $users[] = $id;
    }
    $request = static fn() => rest_do_request(new WP_REST_Request('GET', '/sdr/v1/documentation'));
    wp_set_current_user(0);
    $assert($request()->get_status() === 401, 'Documentation réservée aux membres connectés');
    wp_set_current_user($users[0]);
    $assert($request()->get_status() === 403, 'Un simple abonné ne peut pas consulter les Coulisses');
    foreach (array_slice($users, 1) as $id) {
        wp_set_current_user($id);
        $response = $request();
        $assert($response->get_status() === 200, 'Lecture autorisée pour les membres, organisateurs et administrateurs');
        $assert(($response->get_headers()['Cache-Control'] ?? '') === 'no-store, private', 'Réponse privée sans cache partagé');
    }
    $data = $response->get_data(); $ids = []; $references = [];
    $assert(count($data['domains']) >= 8, 'Les domaines demandés sont documentés');
    $assert(!empty($data['updatedLabel']), 'Date de relecture lisible');
    foreach ($data['domains'] as $domain) {
        $assert(!empty($domain['guides']), 'Chaque domaine contient des procédures');
        foreach ($domain['guides'] as $guide) {
            $assert(!isset($ids[$guide['id']]), 'Lien direct unique : '.$guide['id']);
            $ids[$guide['id']] = true;
            $assert(!empty($guide['steps']) && !empty($guide['summary']), 'Procédure concrète : '.$guide['id']);
            if (isset($guide['reference'])) {
                $labels = array_values(array_map(static fn($field) => $field[0], SDR_Coulisses::fields($guide['reference'])));
                $assert($guide['fields'] === $labels, 'Champs repris du registre réel : '.$guide['reference']);
                $references[] = $guide['reference'];
            }
        }
    }
    $assert(count($ids) >= 24, 'Au moins 24 guides disponibles');
    $assert(in_array('sdr_event', $references, true) && in_array('sdr_venue', $references, true), 'Références automatiques pour spectacles et lieux');
    $assert($mails === 0, 'Consulter la documentation ne déclenche aucun email');
    WP_CLI::success($checks.' vérifications réussies. Aucun email réel.');
} finally {
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user($original);
    remove_filter('pre_wp_mail', $block, PHP_INT_MAX);
}
