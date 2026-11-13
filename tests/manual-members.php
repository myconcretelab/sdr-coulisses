<?php
/** wp eval-file tests/manual-members.php — .local only; all emails intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$users = []; $events = []; $checks = 0; $mails = 0; $last_mail = null; $original = get_current_user_id();
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$rest = static function ($path, $data) {
    $request = new WP_REST_Request('POST', '/sdr/v1/'.$path);
    $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($data));
    return rest_do_request($request);
};
$block = static function ($pre, $mail) use (&$mails, &$last_mail) { $mails++; $last_mail = $mail; return true; };
add_filter('pre_wp_mail', $block, PHP_INT_MAX, 2);
remove_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10);
try {
    require_once ABSPATH.'wp-admin/includes/user.php'; global $wpdb;
    foreach (['org', 'member', 'other', 'optout', 'demo', 'subscriber', 'admin'] as $key) {
        $login = 'manual-member-'.wp_generate_password(12, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(40), 'user_email' => $login.'@example.org', 'role' => ['org' => 'sdr_organizer', 'subscriber' => 'subscriber', 'admin' => 'administrator'][$key] ?? 'sdr_member', 'meta_input' => ['sdr_roles' => ['joueur', 'mc', 'dj'], 'sdr_stage_opt_in' => $key !== 'optout', 'sdr_demo' => $key === 'demo']]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $users[$key] = $id;
    }
    $date = (new DateTimeImmutable('today', wp_timezone()))->modify('+20 days')->format('Y-m-d');
    foreach (['publish', 'draft', 'past'] as $kind) {
        $events[$kind] = wp_insert_post(['post_type' => 'sdr_event', 'post_status' => $kind === 'draft' ? 'draft' : 'publish', 'post_title' => 'Essai ajout manuel', 'meta_input' => ['_sdr_date' => $kind === 'past' ? '2000-01-01' : $date, '_sdr_capacity_joueur' => 1, '_sdr_capacity_mc' => 1, '_sdr_capacity_dj' => 1]]);
    }
    $event = $events['publish']; $path = 'events/'.$event.'/manual-member';
    $body = ['userId' => $users['member'], 'role' => 'dj', 'consent' => true];
    $row = static fn($uid) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND user_id=%d", $event, $uid));
    foreach ([0, $users['member']] as $uid) {
        wp_set_current_user($uid);
        $assert($rest($path, $body)->get_status() === ($uid ? 403 : 401), 'Ajout interdit au visiteur et au simple membre');
    }
    wp_set_current_user($users['org']);
    foreach ([['consent' => false], ['consent' => 'true'], ['userId' => 0], ['userId' => (string)$users['member']], ['role' => 'inconnu'], ['role' => []]] as $invalid) {
        $assert($rest($path, array_replace($body, $invalid))->get_status() === 400, 'Requête invalide ou accord absent refusé');
    }
    foreach (['optout', 'demo', 'subscriber'] as $key) $assert($rest($path, array_replace($body, ['userId' => $users[$key]]))->get_status() === 403, 'Compte hors des membres volontaires refusé : '.$key);
    update_user_meta($users['member'], 'sdr_roles', ['joueur']);
    $assert($rest($path, $body)->get_status() === 400, 'Rôle absent du profil refusé');
    update_user_meta($users['member'], 'sdr_roles', ['joueur', 'mc', 'dj']);
    foreach (['draft', 'past'] as $kind) $assert($rest('events/'.$events[$kind].'/manual-member', $body)->get_status() === 404, 'Spectacle non composable refusé : '.$kind);
    $assert($row($users['member']) === null, 'Aucune réponse créée par les requêtes refusées');
    foreach (['joueur', 'mc', 'dj'] as $role) {
        $assert($rest($path, array_replace($body, ['role' => $role]))->get_status() === 200, 'Ajout direct dans le rôle '.$role);
        $r = $row($users['member']); $audit = get_post_meta($event, '_sdr_manual_member_'.$users['member'], true);
        $assert($r->availability === 'yes' && $r->selected_role === $role && json_decode($r->roles, true) === [$role], 'Disponibilité et attribution enregistrées ensemble');
        $assert($audit['organizer'] === $users['org'] && $audit['role'] === $role && !empty($audit['at']), 'Origine, rôle et date de l’accord conservés');
        $assert($rest($path, $body)->get_status() === 409, 'Double ajout interdit');
        $assert($rest($path, array_replace($body, ['userId' => $users['other'], 'role' => $role]))->get_status() === 409, 'Dernière place déjà prise refusée');
        $assert($row($users['other']) === null, 'Refus sans créer de disponibilité artificielle');
        $dashboard = SDR_Coulisses::dashboard(); $e = array_column($dashboard['events'], null, 'id')[$event];
        $item = array_column($e['responses'], null, 'userId')[$users['member']];
        $assert($item['addedByOrganization'] === true && $item['selectedRole'] === $role, 'Origine exposée dans le planning');
        $members = array_column($dashboard['members'], null, 'id');
        $assert($members[$users['member']]['roles'] === ['joueur', 'mc', 'dj'], 'Rôles du profil disponibles pour le formulaire');
        $assert($rest('events/'.$event.'/selection', ['userId' => $users['member'], 'role' => ''])->get_status() === 200, 'Retrait de l’équipe autorisé');
        $assert($row($users['member']) === null && !get_post_meta($event, '_sdr_manual_member_'.$users['member'], true), 'Retrait manuel rétablit Sans réponse et nettoie son origine');
    }
    foreach (['yes', 'maybe', 'no'] as $status) {
        SDR_Coulisses::save_response($event, $users['member'], ['availability' => $status, 'roles' => $status === 'no' ? [] : ['joueur']]);
        $before = $row($users['member']);
        $assert($rest($path, $body)->get_status() === 409 && $row($users['member']) == $before, 'Réponse personnelle jamais écrasée : '.$status);
        SDR_Coulisses::save_response($event, $users['member'], ['availability' => 'clear']);
    }
    $assert($rest($path, $body)->get_status() === 200, 'Nouvel ajout avant réponse personnelle');
    wp_set_current_user($users['member']);
    $assert($rest('events/'.$event.'/response', ['availability' => 'yes', 'roles' => ['dj']])->get_status() === 200, 'Le membre peut confirmer sa propre réponse');
    $assert(!get_post_meta($event, '_sdr_manual_member_'.$users['member'], true) && $row($users['member'])->selected_role === 'dj', 'Réponse personnelle garde la sélection et retire la mention manuelle');
    wp_set_current_user($users['org']);
    $rest('events/'.$event.'/selection', ['userId' => $users['member'], 'role' => '']);
    $assert($row($users['member'])->availability === 'yes', 'Retrait après réponse personnelle conserve cette réponse');
    SDR_Coulisses::save_response($event, $users['member'], ['availability' => 'clear']);
    update_post_meta($event, '_sdr_closed', 1);
    $assert($rest($path, $body)->get_status() === 200, 'Organisation autorisée à composer quand les réponses sont fermées');
    $personal = SDR_Coulisses::save_response($event, $users['member'], ['availability' => 'no', 'roles' => []]);
    $assert(is_wp_error($personal) && $row($users['member'])->selected_role === 'dj', 'Fermeture respectée pour la réponse du membre');
    $rest('events/'.$event.'/selection', ['userId' => $users['member'], 'role' => '']);
    wp_set_current_user($users['admin']);
    $assert($rest($path, $body)->get_status() === 200, 'Administration également autorisée');
    update_post_meta($event, '_sdr_closed', 0);
    $assert(!is_wp_error(SDR_Coulisses::save_response($event, $users['member'], ['availability' => 'clear'])) && !get_post_meta($event, '_sdr_manual_member_'.$users['member'], true), 'Retirer sa réponse nettoie aussi l’ajout manuel');
    $assert($mails === 0, 'Aucun email déclenché par un ajout ou retrait');
    $assert($rest($path, $body)->get_status() === 200, 'Membre ajouté avant la confirmation d’équipe');
    $silent = $rest('events/'.$event.'/finalize', ['notify' => false]);
    $assert($silent->get_status() === 200 && $mails === 0, 'Sauvegarder sans informer reste sans email');
    $notify = $rest('events/'.$event.'/finalize', ['notify' => true]);
    $assert($notify->get_status() === 200 && $notify->get_data()['notified'] === 1 && $mails === 1, 'Le membre ajouté manuellement reçoit la confirmation d’équipe');
    $assert($last_mail['to'] === get_userdata($users['member'])->user_email && str_contains($last_mail['message'], 'DJ'), 'Confirmation adressée au membre avec son rôle DJ');
    $again = $rest('events/'.$event.'/finalize', ['notify' => true]);
    $assert($again->get_status() === 200 && $again->get_data()['alreadyNotified'] && $mails === 1, 'Pas de double email pour une composition inchangée');
    WP_CLI::success($checks.' vérifications des ajouts manuels réussies.');
} finally {
    foreach ($events as $id) { $wpdb->delete($wpdb->prefix.'sdr_responses', ['event_id' => $id]); wp_clear_scheduled_hook('sdr_notify_new_event', [$id]); wp_delete_post($id, true); }
    foreach ($users as $id) wp_delete_user($id);
    wp_set_current_user($original); remove_filter('pre_wp_mail', $block, PHP_INT_MAX);
    add_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10, 3);
}
