<?php
/** wp eval-file tests/stage-bulk.php — local only; no real email. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$ids = []; $checks = 0; $mails = 0;
$original_user = get_current_user_id(); $original_request = $_REQUEST;
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$block_mail = static function () use (&$mails) { $mails++; return true; };
add_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
$deny = null;
$die_handler = static fn() => static function () { throw new RuntimeException('bulk-denied'); };
try {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['administrator', 'sdr_member', 'sdr_member', 'sdr_member', 'sdr_organizer'] as $i => $role) {
        $login = 'sr-bulk-' . wp_generate_password(12, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password(40), 'role' => $role, 'meta_input' => ['sdr_roles' => ['joueur', 'mc'], 'sdr_stage_opt_in' => false, 'sdr_diet' => 'vegetarian']]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $ids[] = $id;
    }
    [$admin, $one, $two, $untouched, $organizer] = $ids;
    wp_set_current_user($admin);
    $actions = apply_filters('bulk_actions-users', ['delete' => 'Supprimer']);
    $assert(isset($actions['sdr_stage_enable'], $actions['sdr_stage_disable'], $actions['delete']), 'Deux actions ajoutées sans retirer les actions existantes');
    $columns = apply_filters('manage_users_columns', ['username' => 'Identifiant']);
    $assert($columns['sdr_stage_opt_in'] === 'OK pour la scène', 'Colonne visible dans la liste');
    $_REQUEST['sdr_stage_nonce'] = wp_create_nonce('bulk-users');
    $before = $mails;
    $url = apply_filters('handle_bulk_actions-users', admin_url('users.php?paged=2'), 'sdr_stage_enable', [$one, $two, $one]);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $result);
    $assert($result['sdr_stage_changed'] === '2' && $result['paged'] === '2', 'Activation des deux comptes, doublons ignorés, pagination conservée');
    $assert(SDR_Coulisses::profile($one)['stageOptIn'] && SDR_Coulisses::profile($two)['stageOptIn'] && !SDR_Coulisses::profile($untouched)['stageOptIn'], 'Seuls les comptes sélectionnés sont activés');
    $assert(apply_filters('manage_users_custom_column', '', 'sdr_stage_opt_in', $one) === 'Oui', 'Colonne mise à jour immédiatement');
    $url = apply_filters('handle_bulk_actions-users', $url, 'sdr_stage_enable', [$one, $two]);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $result);
    $assert($result['sdr_stage_changed'] === '0' && $result['sdr_stage_unchanged'] === '2', 'Rejouer une activation ne compte pas de faux changements');
    $url = apply_filters('handle_bulk_actions-users', $url, 'sdr_stage_disable', [$one, $two]);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $result);
    $assert($result['sdr_stage_result'] === 'disabled' && $result['sdr_stage_changed'] === '2' && !get_user_meta($one, 'sdr_stage_opt_in', true) && !get_user_meta($two, 'sdr_stage_opt_in', true), 'Désactivation groupée et bilan actualisé');
    $assert(apply_filters('manage_users_custom_column', '', 'sdr_stage_opt_in', $one) === 'Non', 'Colonne reflétant la désactivation');
    $assert(get_user_meta($one, 'sdr_roles', true) === ['joueur', 'mc'] && get_user_meta($one, 'sdr_diet', true) === 'vegetarian', 'Rôles et autres données du profil conservés');
    $assert($mails === $before, 'Aucun email déclenché par les actions groupées');
    $assert(apply_filters('handle_bulk_actions-users', 'unchanged', 'another_action', [$one]) === 'unchanged', 'Actions tierces conservées');
    $deny = static function ($allcaps, $caps, $args) use ($one) {
        if (($args[0] ?? '') === 'edit_user' && (int) ($args[2] ?? 0) === $one) foreach ($caps as $cap) $allcaps[$cap] = false;
        return $allcaps;
    };
    add_filter('user_has_cap', $deny, 10, 3);
    $url = apply_filters('handle_bulk_actions-users', admin_url('users.php'), 'sdr_stage_enable', [$one, $two, 0]);
    parse_str(wp_parse_url($url, PHP_URL_QUERY), $result);
    $assert($result['sdr_stage_skipped'] === '2' && $result['sdr_stage_changed'] === '1' && !get_user_meta($one, 'sdr_stage_opt_in', true), 'Droits vérifiés pour chaque compte avant modification');
    remove_filter('user_has_cap', $deny, 10); $deny = null;
    add_filter('wp_die_handler', $die_handler);
    $_REQUEST['sdr_stage_nonce'] = 'invalid';
    $denied = false;
    try { SDR_Event_Reminders::stage_bulk_update(admin_url('users.php'), 'sdr_stage_enable', [$one]); } catch (RuntimeException $e) { $denied = $e->getMessage() === 'bulk-denied'; }
    $assert($denied && !get_user_meta($one, 'sdr_stage_opt_in', true), 'Action forgée sans nonce refusée avant modification');
    foreach ([$untouched, $organizer] as $uid) {
        wp_set_current_user($uid); $_REQUEST['sdr_stage_nonce'] = wp_create_nonce('bulk-users');
        $assert(!isset(apply_filters('bulk_actions-users', [])['sdr_stage_enable']), 'Actions masquées aux membres et organisateurs');
        $denied = false;
        try { SDR_Event_Reminders::stage_bulk_update(admin_url('users.php'), 'sdr_stage_enable', [$one]); } catch (RuntimeException $e) { $denied = $e->getMessage() === 'bulk-denied'; }
        $assert($denied && !get_user_meta($one, 'sdr_stage_opt_in', true), 'Action réservée à l’administration même avec un nonce valide');
    }
    WP_CLI::success($checks . ' vérifications réussies. Aucun email réel envoyé.');
} finally {
    if ($deny) remove_filter('user_has_cap', $deny, 10);
    remove_filter('wp_die_handler', $die_handler);
    foreach ($ids as $id) wp_delete_user($id);
    wp_set_current_user($original_user); $_REQUEST = $original_request;
    remove_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
}
