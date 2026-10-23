<?php
/** wp eval-file tests/event-reminder-needs.php — .local only; all emails intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$ids = []; $events = []; $campaigns = []; $mails = []; $checks = 0; $original_user = get_current_user_id();
$assert = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$rest = static function ($method, $path, $data = []) {
    $request = new WP_REST_Request($method, '/sdr/v1/' . $path);
    if ($method === 'GET') $request->set_query_params($data);
    else { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($data)); }
    return rest_do_request($request);
};
$block_mail = static function ($pre, $mail) use (&$mails) { $mails[] = $mail; return true; };
add_filter('pre_wp_mail', $block_mail, PHP_INT_MAX, 2);
remove_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10);
try {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['org' => [], 'player' => ['joueur'], 'mc' => ['mc'], 'multi' => ['joueur', 'mc'], 'dj' => ['dj'], 'selected-player' => ['joueur'], 'selected-mc' => ['mc'], 'selected-dj' => ['dj'], 'answered' => ['mc'], 'optout' => ['mc']] as $key => $roles) {
        $login = 'sr-needs-' . wp_generate_password(12, false, false);
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.org', 'user_pass' => wp_generate_password(40), 'display_name' => 'Essai ' . $key, 'role' => $key === 'org' ? 'sdr_organizer' : 'sdr_member', 'meta_input' => ['sdr_roles' => $roles, 'sdr_stage_opt_in' => !in_array($key, ['org', 'optout'], true)]]);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $ids[$key] = $id;
    }
    $date = (new DateTimeImmutable('today', wp_timezone()))->modify('+20 days')->format('Y-m-d');
    foreach ([['joueur' => 1, 'mc' => 2, 'dj' => 1], ['joueur' => 0, 'mc' => 0, 'dj' => 0]] as $capacity) {
        $events[] = wp_insert_post(['post_type' => 'sdr_event', 'post_status' => 'publish', 'post_title' => 'Le casting à compléter', 'meta_input' => ['_sdr_date' => $date, '_sdr_time' => '20:30', '_sdr_capacity_joueur' => $capacity['joueur'], '_sdr_capacity_mc' => $capacity['mc'], '_sdr_capacity_dj' => $capacity['dj']]]);
    }
    $event = $events[0]; $root = 'events/' . $event . '/reminders';
    wp_set_current_user($ids['org']);
    foreach (['joueur' => 'selected-player', 'mc' => 'selected-mc', 'dj' => 'selected-dj'] as $role => $key) {
        SDR_Coulisses::save_response($event, $ids[$key], ['availability' => 'yes', 'roles' => [$role]]);
        $assert($rest('POST', 'events/' . $event . '/selection', ['userId' => $ids[$key], 'role' => $role])->get_status() === 200, 'Sélection initiale ' . $role);
    }
    SDR_Coulisses::save_response($event, $ids['answered'], ['availability' => 'maybe', 'roles' => ['mc']]);
    $assert(SDR_Event_Reminders::needs($event) === ['mc' => 1], 'Un MC manque malgré une autre candidature MC si besoin');
    $audience = $rest('GET', $root)->get_data();
    $users = array_column($audience['users'], null, 'id');
    $assert($audience['needs'] === ['mc' => 1] && $audience['hasCapacity'], 'Besoin exact exposé à la fenêtre');
    $assert(isset($users[$ids['mc']], $users[$ids['multi']]), 'Profils MC sans réponse admissibles');
    foreach (['player', 'dj', 'selected-mc', 'answered', 'optout'] as $key) $assert(!isset($users[$ids[$key]]), 'Profil hors cible exclu : ' . $key);
    $assert($users[$ids['multi']]['roles'] === ['joueur', 'mc'] && $users[$ids['multi']]['targetRoles'] === ['mc'], 'Profil complet conservé, rôle recherché distingué');
    $assert($rest('POST', $root, ['requestId' => 'needs-wrong-role-123456', 'recipients' => [$ids['player']]])->get_status() === 409, 'Destinataire sans rôle recherché refusé côté serveur');
    $create = static function ($recipients, $request_id, $kind = 'reminder') use ($rest, $root, &$campaigns, $assert) {
        $result = $rest('POST', $root, ['requestId' => $request_id, 'kind' => $kind, 'recipients' => $recipients]);
        $assert($result->get_status() === 200, 'Campagne préparée ' . $request_id);
        $campaigns[] = $result->get_data()['id']; return $result->get_data()['id'];
    };
    $campaign = $create([$ids['mc'], $ids['multi']], 'needs-last-place-filled-123');
    $rest('POST', 'events/' . $event . '/selection', ['userId' => $ids['answered'], 'role' => 'mc']);
    $assert(SDR_Event_Reminders::needs($event) === [] && SDR_Event_Reminders::candidates($event)['users'] === [], 'Équipe complète sans destinataire à relancer');
    $result = $rest('POST', $root . '/' . $campaign . '/send')->get_data();
    $assert($result['skipped'] === 2 && $result['accepted'] === 0 && count($mails) === 0, 'Dernière place pourvue entre préparation et envoi : aucun email');
    $assert(!get_user_meta($ids['mc'], SDR_Event_Reminders::token_key($event), true), 'Aucun lien personnel généré pour un envoi ignoré');
    $rest('POST', 'events/' . $event . '/selection', ['userId' => $ids['answered'], 'role' => '']);
    $campaign = $create([$ids['mc'], $ids['multi']], 'needs-personalized-email-123');
    $result = $rest('POST', $root . '/' . $campaign . '/send')->get_data();
    $assert($result['accepted'] === 2 && count($mails) === 2, 'Deux relances MC personnalisées acceptées');
    foreach ($mails as $mail) {
        $assert(str_contains($mail['subject'], 'Recherche : 1 MC'), 'Objet adapté au besoin MC');
        $assert(str_contains($mail['message'], 'Il manque encore') && str_contains($mail['message'], '1 MC') && !str_contains($mail['message'], 'Joueur·se'), 'HTML ciblé sur MC sans proposer les rôles complets');
        $assert(str_contains($mail['message'], 'OK, je me lance') && str_contains($mail['message'], 'Je ne suis pas dispo'), 'Deux boutons de réponse conservés');
    }
    $mail = $mails[1];
    preg_match('/href="([^"]*sdr-reponse[^"]*)"/', $mail['message'], $link);
    parse_str(wp_parse_url(html_entity_decode($link[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
    $credentials = ['event' => $event, 'user' => $ids['multi'], 'token' => $query['cle']];
    wp_set_current_user(0);
    $assert(SDR_Event_Reminders::context($credentials)['roles'] === ['mc'], 'La fiche personnelle ne propose que MC');
    $assert(!SDR_Event_Reminders::response($event, $ids['multi']), 'Lire le lien ne répond pas automatiquement');
    $assert($rest('POST', 'email-response', $credentials + ['availability' => 'yes', 'roles' => ['joueur']])->get_status() === 400, 'Impossible de substituer un autre rôle au lien ciblé');
    $assert($rest('POST', 'email-response', $credentials + ['availability' => 'yes', 'roles' => ['mc']])->get_status() === 200, 'Réponse MC sans connexion');
    $assert(get_current_user_id() === 0 && SDR_Event_Reminders::response($event, $ids['multi'])->selected_role === '', 'La réponse ne compose pas l’équipe et n’ouvre aucune session');
    wp_set_current_user($ids['org']);
    $campaign = $create([$ids['mc']], 'needs-profile-change-12345');
    update_user_meta($ids['mc'], 'sdr_roles', ['joueur']);
    $result = $rest('POST', $root . '/' . $campaign . '/send')->get_data();
    $assert($result['skipped'] === 1 && count($mails) === 2, 'Profil devenu uniquement joueur ignoré avant envoi');
    update_user_meta($ids['mc'], 'sdr_roles', ['mc']);
    update_post_meta($event, '_sdr_capacity_mc', 3);
    $mail = SDR_Event_Reminders::email($event, get_userdata($ids['mc']), str_repeat('a', 64), time() + 3600);
    $assert(str_contains($mail['subject'], '2 MC') && str_contains($mail['plain'], 'Il reste 2 MC'), 'Texte et version texte suivent le nombre de places restant');
    $assert(SDR_Event_Reminders::candidates($events[1])['users'] === [], 'Aucun besoin défini : pas de relance');
    $zero = $rest('GET', 'events/' . $events[1] . '/reminders')->get_data();
    $assert(!$zero['hasCapacity'] && $zero['needs'] === [], 'Zéro place distingué d’une équipe complète');
    $announcements = SDR_Event_Reminders::candidates($events[1], 'announcement')['users'];
    $assert(isset($announcements[$ids['player']], $announcements[$ids['dj']]), 'Nouvelle date : annonces générales conservées sans besoins définis');
    $campaign = $create([$ids['player'], $ids['dj']], 'needs-announcement-resume-123', 'announcement');
    $resumed = $rest('GET', $root, ['kind' => 'reminder'])->get_data();
    $assert($resumed['kind'] === 'announcement' && in_array($ids['player'], array_column($resumed['users'], 'id'), true), 'Reprise d’annonce avec son audience générale, même ouverte depuis une relance');
    $result = $rest('POST', $root . '/' . $campaign . '/send')->get_data();
    $assert($result['accepted'] === 2 && count($mails) === 4, 'Annonce encore envoyée aux rôles déjà complets');
    $assert(SDR_Event_Reminders::need_summary(['joueur' => 2, 'mc' => 1, 'dj' => 1]) === '2 joueur·ses · 1 MC · 1 DJ', 'Plusieurs besoins et pluriel lisibles');
    WP_CLI::success($checks . ' vérifications réussies. Tous les emails interceptés, aucun envoi réel.');
} finally {
    foreach ($campaigns as $campaign) delete_option(SDR_Event_Reminders::PREFIX . $campaign);
    global $wpdb;
    foreach ($events as $event) { $wpdb->delete($wpdb->prefix . 'sdr_responses', ['event_id' => $event]); wp_clear_scheduled_hook('sdr_notify_new_event', [$event]); wp_delete_post($event, true); }
    foreach ($ids as $id) wp_delete_user($id);
    wp_set_current_user($original_user); remove_filter('pre_wp_mail', $block_mail, PHP_INT_MAX);
    add_action('transition_post_status', ['SDR_Coulisses', 'queue_new_event_email'], 10, 3);
}
