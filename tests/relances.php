<?php
/** Integration : wp eval-file tests/relances.php (installation locale uniquement). */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) {
    throw new RuntimeException('Ces tests sont réservés à une installation .local via WP-CLI.');
}
if (!class_exists('SDR_Relances')) throw new RuntimeException('Activer SDR — Relances utilisateurs.');
$ids = []; $posts = []; $campaigns = []; $checks = 0; $mails = [];
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$old_active = get_user_meta($admin->ID, 'sdr_relances_active', true);
$old_history = get_user_meta($admin->ID, 'sdr_relances_history', true);
$old_draft = get_user_meta($admin->ID, 'sdr_relances_draft', true);
$assert = static function ($condition, $message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; };
$rest = static function ($method, $path, $data = []) { $r = new WP_REST_Request($method, '/sdr-relances/v1/' . $path); $r->set_body_params($data); return rest_do_request($r); };
$mail_filter = static function ($pre, $atts) use (&$mails) { if (str_contains($atts['subject'], 'J-24')) $mails[] = $atts; return !str_starts_with((string) $atts['to'], 'sr-fail-'); };
add_filter('pre_wp_mail', $mail_filter, 10, 2);
try {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_set_current_user($admin->ID);
    delete_user_meta($admin->ID, 'sdr_relances_active');
    delete_user_meta($admin->ID, 'sdr_relances_history');
    $prefix = 'sr-test-' . wp_generate_password(8, false, false);
    for ($i = 0; $i < 8; $i++) {
        $id = wp_insert_user(['user_login' => $prefix . $i, 'user_email' => ($i === 7 ? 'sr-fail-' : '') . $prefix . $i . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => 'sdr_member', 'display_name' => 'Essai ' . $i, 'first_name' => $i === 0 ? 'Aline & Éloïse' : 'Essai']);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $ids[] = $id;
    }
    $event = wp_insert_post(['post_type' => 'sdr_event', 'post_title' => 'Spectacle & essai', 'post_status' => 'publish', 'meta_input' => ['_sdr_date' => (new DateTimeImmutable('today', wp_timezone()))->modify('+24 days')->format('Y-m-d'), '_sdr_time' => '20:30']]); $posts[] = $event;
    $payload = ['search' => $prefix, 'event' => $event, 'subject' => 'Bonjour {{ prenom }} — J-{{jours_avant_spectacle}}', 'body' => '<p><strong>{{prenom}}</strong> — {{spectacle}}</p><p><a href="{{lien_profil}}">Mon profil</a></p>'];
    $all = SDR_Relances::users(['search' => $prefix]); $assert(count($all['users']) === 8, 'Liste des utilisateurs de test');
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['never_login']])['users']) === 8, 'Nouveaux comptes suivis jamais connectés');
    SDR_Relances::record_login($ids[0]);
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['no_login']])['users']) === 7, 'Connexion enregistrée');
    update_user_meta($ids[1], '_iu_derniere_connexion', '2026-10-10 20:30:00');
    $assert(SDR_Relances::last_login($ids[1]) === (new DateTimeImmutable('2026-10-10 20:30:00', wp_timezone()))->getTimestamp(), 'Historique des invitations repris dans le fuseau du site');
    delete_user_meta($ids[1], '_iu_derniere_connexion');
    update_user_meta($ids[0], 'description', 'Une biographie.');
    update_user_meta($ids[1], 'sdr_profile_description', 'Biographie héritée.');
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['no_bio']])['users']) === 6, 'Biographie WordPress et ancienne biographie');
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['no_bio', 'no_login']])['users']) === 6, 'Filtres ET');
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['no_bio', 'no_login'], 'mode' => 'any'])['users']) === 7, 'Filtres OU');
    $assert(is_wp_error(SDR_Relances::users(['filters' => ['no_response']])), 'Filtre spectacle exige un spectacle');
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'sdr_responses', ['event_id' => $event, 'user_id' => $ids[0], 'availability' => 'no', 'roles' => '[]', 'updated_at' => current_time('mysql')]);
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['no_response'], 'event' => $event])['users']) === 7, 'Une réponse négative est une réponse');
    $extend = static function ($filters) { $filters['custom_test'] = ['label' => 'Filtre de test', 'matches' => static fn($u) => $u->first_name === 'Essai']; return $filters; };
    add_filter('sdr_relances_filters', $extend);
    $assert(count(SDR_Relances::users(['search' => $prefix, 'filters' => ['custom_test']])['users']) === 7, 'Filtre extensible');
    remove_filter('sdr_relances_filters', $extend);
    $days = SDR_Relances::definitions()['jours_avant_spectacle']['resolve'];
    update_post_meta($event, '_sdr_date', current_time('Y-m-d'));
    $assert($days(get_userdata($ids[0]), get_post($event)) === '0', 'Compte à rebours le jour même');
    update_post_meta($event, '_sdr_date', (new DateTimeImmutable('yesterday', wp_timezone()))->format('Y-m-d'));
    $assert($days(get_userdata($ids[0]), get_post($event)) === '-1', 'Compte à rebours pour une date passée');
    update_post_meta($event, '_sdr_date', (new DateTimeImmutable('today', wp_timezone()))->modify('+24 days')->format('Y-m-d'));
    $assert(is_wp_error(SDR_Relances::users(['event' => []])), 'Identifiant de spectacle malformé refusé');
    $render = SDR_Relances::preview($payload + ['user' => $ids[0]]);
    $assert(!is_wp_error($render) && str_contains($render['subject'], 'J-24'), 'Compte à rebours en jours calendaires');
    $assert(str_contains($render['html'], 'Aline &amp; Éloïse') && str_contains($render['html'], '<strong>'), 'Variables échappées et texte enrichi conservé');
    $assert(str_contains($render['html'], '/coulisses/?vue=profil'), 'Lien direct vers le profil');
    $assert(is_wp_error(SDR_Relances::message(array_merge($payload, ['event' => 0]))), 'Variables spectacle sans spectacle refusées');
    $assert(is_wp_error(SDR_Relances::message(array_merge($payload, ['subject' => '{{inconnue}}']))), 'Variable inconnue refusée');
    $assert(is_wp_error(SDR_Relances::message(array_merge($payload, ['body' => '   ']))), 'Message vide refusé');
    $safe = SDR_Relances::message(array_merge($payload, ['body' => '<p onclick="alert(1)">Bonjour</p><script>alert(1)</script>']));
    $assert(!str_contains($safe['body'], '<script') && !str_contains($safe['body'], 'onclick'), 'HTML dangereux supprimé');
    wp_set_current_user(0); $assert($rest('GET', 'bootstrap')->get_status() === 401, 'API privée sans authentification');
    wp_set_current_user($ids[0]); $assert($rest('GET', 'users')->get_status() === 403, 'Membres sans accès à l’API administrative');
    wp_set_current_user($admin->ID);
    $test = SDR_Relances::test_mail($payload + ['user' => $ids[0]]);
    $assert(!is_wp_error($test) && end($mails)['to'] === $admin->user_email, 'Email de test réservé à l’administrateur');
    $mails = [];
    $bad = SDR_Relances::create_campaign($payload + ['recipients' => [$admin->ID]]);
    $assert(is_wp_error($bad), 'Destinataire hors filtre refusé');
    $campaign = SDR_Relances::create_campaign($payload + ['recipients' => $ids]);
    $assert(!is_wp_error($campaign) && $campaign['total'] === 8, 'Création de campagne'); $campaigns[] = $campaign['id'];
    $assert(is_wp_error(SDR_Relances::create_campaign($payload + ['recipients' => $ids])), 'Nouvelle campagne bloquée tant qu’il reste des messages');
    $result = SDR_Relances::send_batch(['id' => $campaign['id']]);
    $assert($result['accepted'] === 5 && $result['pending'] === 3 && count($mails) === 5, 'Traitement par lot de cinq');
    // Relire hors cache pour vérifier que les statuts ont réellement été sauvegardés.
    wp_cache_delete('sdr_relances_campaign_' . $campaign['id'], 'options');
    $persisted = SDR_Relances::campaign_status(['id' => $campaign['id']]);
    $assert($persisted['accepted'] === 5 && $persisted['pending'] === 3, 'Statuts persistants après suppression du cache');
    wp_update_user(['ID' => $ids[6], 'user_email' => $prefix . '-modified@example.invalid']);
    $result = SDR_Relances::send_batch(['id' => $campaign['id']]);
    $assert($result['accepted'] === 6 && $result['failed'] === 2 && $result['pending'] === 0, 'Adresse modifiée et échec de transport signalés');
    $assert(count($mails) === 7, 'Adresse modifiée ignorée par le transport');
    SDR_Relances::send_batch(['id' => $campaign['id']]); $assert(count($mails) === 7, 'Rejouer un lot ne renvoie aucun message');
    $assert(count(array_unique(array_column($mails, 'to'))) === count($mails), 'Un seul email par destinataire');
    $assert(str_contains($mails[0]['message'], '<strong>Aline &amp;') && !str_contains($mails[1]['message'], 'Aline'), 'Personnalisation indépendante des destinataires');
    $c2 = SDR_Relances::create_campaign($payload + ['recipients' => array_slice($ids, 0, 2)]); $campaigns[] = $c2['id'];
    wp_set_current_user($ids[0]); $assert($rest('POST', 'campaigns/' . $c2['id'] . '/send')->get_status() === 403, 'Envoi interdit aux membres');
    wp_set_current_user($admin->ID);
    $cancel = SDR_Relances::cancel_campaign(['id' => $c2['id']]);
    $assert($cancel['cancelled'] === 2 && $cancel['pending'] === 0, 'Annulation des messages restants');
    $assert(count($mails) === 7, 'L’annulation ne transmet pas d’email');
    $c3 = SDR_Relances::create_campaign($payload + ['recipients' => [$ids[0], $ids[1]]]); $campaigns[] = $c3['id'];
    $stored = get_option('sdr_relances_campaign_' . $c3['id']); $stored['recipients'][$ids[0]]['status'] = 'processing'; update_option('sdr_relances_campaign_' . $c3['id'], $stored, false);
    $uncertain = SDR_Relances::send_batch(['id' => $c3['id']]);
    $assert($uncertain['uncertain'] === 1 && $uncertain['accepted'] === 1 && count($mails) === 8, 'Une transmission interrompue ne se renvoie pas automatiquement');
    $assert(str_contains($uncertain['issues'][0]['message'], 'vérifier'), 'Interruption clairement signalée');
    $save = SDR_Relances::save_draft(['subject' => 'Brouillon', 'body' => '<p>Texte {{prenom}}</p>', 'event' => $event]);
    $assert($save['saved'] && SDR_Relances::bootstrap()['draft']['subject'] === 'Brouillon', 'Restauration du brouillon');
    WP_CLI::success($checks . ' vérifications réussies. Aucun email réel envoyé.');
} finally {
    remove_filter('pre_wp_mail', $mail_filter, 10);
    foreach ($campaigns as $id) delete_option('sdr_relances_campaign_' . $id);
    foreach ($ids as $id) wp_delete_user($id);
    foreach ($posts as $id) { $wpdb->delete($wpdb->prefix . 'sdr_responses', ['event_id' => $id]); wp_delete_post($id, true); }
    update_user_meta($admin->ID, 'sdr_relances_active', $old_active);
    update_user_meta($admin->ID, 'sdr_relances_history', $old_history);
    update_user_meta($admin->ID, 'sdr_relances_draft', $old_draft);
    wp_set_current_user($admin->ID);
}
