<?php
/** Event invitations and narrowly scoped, passwordless response links. */
defined('ABSPATH') || exit;

final class SDR_Event_Reminders {
    const VERSION = '1.0.0';
    const PREFIX = 'sdr_event_reminder_';

    static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('template_redirect', [__CLASS__, 'response_page'], -1);
        add_filter('bulk_actions-users', [__CLASS__, 'stage_bulk_actions']);
        add_filter('handle_bulk_actions-users', [__CLASS__, 'stage_bulk_update'], 10, 3);
        add_filter('manage_users_columns', [__CLASS__, 'stage_column']);
        add_filter('manage_users_custom_column', [__CLASS__, 'stage_column_value'], 10, 3);
        add_action('restrict_manage_users', [__CLASS__, 'stage_bulk_nonce']);
        add_action('admin_notices', [__CLASS__, 'stage_bulk_notice']);
    }
    static function stage_bulk_allowed() { return current_user_can('manage_options') && current_user_can('edit_users'); }
    static function stage_bulk_nonce($which) {
        if ($which === 'top' && self::stage_bulk_allowed()) wp_nonce_field('bulk-users', 'sdr_stage_nonce', false);
    }
    static function stage_bulk_actions($actions) {
        if (self::stage_bulk_allowed()) {
            $actions['sdr_stage_enable'] = 'OK pour la scène : activer';
            $actions['sdr_stage_disable'] = 'OK pour la scène : désactiver';
        }
        return $actions;
    }
    static function stage_column($columns) {
        if (self::stage_bulk_allowed()) $columns['sdr_stage_opt_in'] = 'OK pour la scène';
        return $columns;
    }
    static function stage_column_value($value, $column, $uid) {
        if ($column !== 'sdr_stage_opt_in' || !self::stage_bulk_allowed()) return $value;
        return get_user_meta($uid, 'sdr_stage_opt_in', true) ? 'Oui' : 'Non';
    }
    static function stage_bulk_update($redirect, $action, $ids) {
        if (!in_array($action, ['sdr_stage_enable', 'sdr_stage_disable'], true)) return $redirect;
        if (!self::stage_bulk_allowed()) wp_die('La modification groupée des profils est réservée à l’administration.', 'Accès réservé', ['response' => 403]);
        // users.php strips _wpnonce before custom GET actions; this field survives that redirect.
        check_admin_referer('bulk-users', 'sdr_stage_nonce');
        $enabled = $action === 'sdr_stage_enable';
        $counts = ['changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (array_unique(array_map('absint', (array) $ids)) as $uid) {
            if (!$uid || !get_userdata($uid) || !current_user_can('edit_user', $uid)) { $counts['skipped']++; continue; }
            if ((bool) get_user_meta($uid, 'sdr_stage_opt_in', true) === $enabled) { $counts['unchanged']++; continue; }
            $saved = update_user_meta($uid, 'sdr_stage_opt_in', $enabled);
            $counts[$saved !== false ? 'changed' : ((bool) get_user_meta($uid, 'sdr_stage_opt_in', true) === $enabled ? 'unchanged' : 'failed')]++;
        }
        $args = ['sdr_stage_result' => $enabled ? 'enabled' : 'disabled'];
        foreach ($counts as $key => $count) $args['sdr_stage_' . $key] = $count;
        return add_query_arg($args, remove_query_arg(['sdr_stage_result', 'sdr_stage_changed', 'sdr_stage_unchanged', 'sdr_stage_skipped', 'sdr_stage_failed', 'sdr_stage_nonce', '_wpnonce', '_wp_http_referer', 'action', 'action2', 'users'], $redirect ?: admin_url('users.php')));
    }
    static function stage_bulk_notice() {
        if (!self::stage_bulk_allowed() || get_current_screen()?->id !== 'users' || !in_array($_GET['sdr_stage_result'] ?? '', ['enabled', 'disabled'], true)) return;
        $changed = absint($_GET['sdr_stage_changed'] ?? 0);
        $unchanged = absint($_GET['sdr_stage_unchanged'] ?? 0);
        $skipped = absint($_GET['sdr_stage_skipped'] ?? 0);
        $failed = absint($_GET['sdr_stage_failed'] ?? 0);
        $message = '« OK pour la scène » ' . ($_GET['sdr_stage_result'] === 'enabled' ? 'activé' : 'désactivé') . ' pour ' . $changed . ' compte(s).';
        if ($unchanged) $message .= ' ' . $unchanged . ' déjà dans cet état.';
        if ($skipped) $message .= ' ' . $skipped . ' non modifié(s) : compte introuvable ou droits insuffisants.';
        if ($failed) $message .= ' ' . $failed . ' modification(s) en échec : réessaie pour ces comptes.';
        echo '<div class="notice ' . ($failed || $skipped ? 'notice-warning' : 'notice-success') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
    static function routes() {
        register_rest_route('sdr/v1', '/events/(?P<id>\d+)/reminders', [
            ['methods' => 'GET', 'permission_callback' => ['SDR_Coulisses', 'organizer'], 'callback' => [__CLASS__, 'audience']],
            ['methods' => 'POST', 'permission_callback' => ['SDR_Coulisses', 'organizer'], 'callback' => [__CLASS__, 'create']],
        ]);
        foreach (['send', 'cancel'] as $action) register_rest_route('sdr/v1', '/events/(?P<id>\d+)/reminders/(?P<campaign>[a-f0-9-]{36})/' . $action, [
            'methods' => 'POST', 'permission_callback' => ['SDR_Coulisses', 'organizer'], 'callback' => [__CLASS__, $action],
        ]);
        register_rest_route('sdr/v1', '/email-response', [
            'methods' => 'POST', 'permission_callback' => [__CLASS__, 'link_permission'], 'callback' => [__CLASS__, 'answer'],
        ]);
    }
    static function event_error($id) {
        if (!SDR_Coulisses::editable_event($id)) return SDR_Coulisses::error('Ce spectacle n’est plus ouvert aux réponses.', 404);
        if (SDR_Coulisses::meta($id, 'closed', false)) return SDR_Coulisses::error('Les réponses sont fermées pour ce spectacle.', 409);
        return null;
    }
    static function roles($uid) {
        $roles = get_user_meta($uid, 'sdr_roles', true);
        return is_array($roles) ? array_values(array_intersect(array_keys(SDR_Coulisses::ROLES), $roles)) : [];
    }
    static function personal_email($user) {
        return SDR_Coulisses::email_available($user) && !in_array(strtolower(trim($user->user_email)), ['sdr@sdr.fr', 'sdr@sdr.com'], true);
    }
    static function response($event, $user) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT availability,roles,selected_role FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND user_id=%d", $event, $user));
    }
    static function candidates($event) {
        $users = []; $excluded = 0;
        foreach (SDR_Coulisses::member_email_recipients() as $user) {
            $roles = self::roles($user->ID);
            if (!$roles || !get_user_meta($user->ID, 'sdr_stage_opt_in', true) || !user_can($user, 'sdr_member') || self::response($event, $user->ID)) continue;
            if (!self::personal_email($user)) { $excluded++; continue; }
            $users[$user->ID] = ['id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email, 'roles' => $roles];
        }
        return ['users' => $users, 'excluded' => $excluded];
    }
    static function meta_key($event) { return self::PREFIX . 'active_' . $event; }
    static function audience($request) {
        $id = (int) $request['id'];
        if ($error = self::event_error($id)) return $error;
        $audience = self::candidates($id);
        $last = get_user_meta(get_current_user_id(), self::meta_key($id), true);
        $campaign = $last ? get_option(self::PREFIX . $last) : false;
        $result = rest_ensure_response([
            'users' => array_values($audience['users']), 'excluded' => $audience['excluded'],
            'event' => SDR_Coulisses::event_email_details(get_post($id)),
            'campaign' => $campaign && (int) $campaign['author'] === get_current_user_id() ? self::summary($campaign) : null,
        ]);
        $result->header('Cache-Control', 'no-store, private');
        return $result;
    }
    static function create($request) {
        $event = (int) $request['id'];
        return SDR_Coulisses::locked($event, static function () use ($event, $request) {
            if ($error = self::event_error($event)) return $error;
            $request_id = $request['requestId'] ?? '';
            if (!is_string($request_id) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $request_id)) return SDR_Coulisses::error('La demande d’envoi est invalide.');
            $author = get_current_user_id();
            $key = self::meta_key($event);
            $previous_id = get_user_meta($author, $key, true);
            $previous = $previous_id ? get_option(self::PREFIX . $previous_id) : false;
            if ($previous && $previous['requestId'] === $request_id) return self::summary($previous);
            if ($previous && self::summary($previous)['pending']) return SDR_Coulisses::error('Une relance est déjà en attente. Reprends-la ou annule-la.', 409);
            $ids = $request['recipients'] ?? [];
            if (!is_array($ids) || !$ids || count($ids) > 5000) return SDR_Coulisses::error('Sélectionne au moins une personne à relancer.');
            foreach ($ids as $id) if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) return SDR_Coulisses::error('La sélection est invalide.');
            $audience = self::candidates($event)['users'];
            $recipients = [];
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                if (!isset($audience[$id])) return SDR_Coulisses::error('Une personne a répondu ou son profil a changé. Actualise la liste avant l’envoi.', 409);
                $recipients[$id] = ['status' => 'pending', 'name' => $audience[$id]['name'], 'email' => $audience[$id]['email']];
            }
            $campaign = ['id' => wp_generate_uuid4(), 'event' => $event, 'author' => $author, 'created' => time(), 'requestId' => $request_id, 'recipients' => $recipients];
            if (!add_option(self::PREFIX . $campaign['id'], $campaign, '', false)) return SDR_Coulisses::error('Impossible de préparer les relances.', 500);
            update_user_meta($author, $key, $campaign['id']);
            $history_key = $key . '_history';
            $history = (array) get_user_meta($author, $history_key, true);
            array_unshift($history, $campaign['id']);
            foreach (array_slice($history, 10) as $old) delete_option(self::PREFIX . $old);
            update_user_meta($author, $history_key, array_slice($history, 0, 10));
            return self::summary($campaign);
        });
    }
    static function summary($campaign) {
        $counts = array_count_values(array_column($campaign['recipients'], 'status'));
        $issues = [];
        foreach ($campaign['recipients'] as $row) if (in_array($row['status'], ['failed', 'processing', 'skipped'], true)) {
            $issues[] = ['name' => $row['name'], 'message' => $row['status'] === 'processing' ? 'Transmission interrompue : vérifier la réception avant de relancer à nouveau.' : ($row['error'] ?? 'WordPress n’a pas accepté cet email.')];
        }
        return ['id' => $campaign['id'], 'total' => count($campaign['recipients']), 'pending' => $counts['pending'] ?? 0, 'accepted' => $counts['accepted'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'uncertain' => $counts['processing'] ?? 0, 'skipped' => $counts['skipped'] ?? 0, 'cancelled' => $counts['cancelled'] ?? 0, 'issues' => $issues];
    }
    static function send($request) { return self::process($request); }
    static function cancel($request) { return self::process($request, true); }
    static function process($request, $cancel = false) {
        $event = (int) $request['id'];
        return SDR_Coulisses::locked($event, static function () use ($event, $request, $cancel) {
            $campaign = get_option(self::PREFIX . $request['campaign']);
            if (!$campaign || (int) $campaign['author'] !== get_current_user_id() || (int) $campaign['event'] !== $event) return SDR_Coulisses::error('Cette relance n’est pas accessible.', 404);
            if (!$cancel && ($error = self::event_error($event))) return $error;
            $processed = 0;
            foreach (array_keys($campaign['recipients']) as $id) {
                $recipient = $campaign['recipients'][$id];
                if ($recipient['status'] !== 'pending') continue;
                if ($cancel) { $campaign['recipients'][$id]['status'] = 'cancelled'; continue; }
                $user = get_userdata($id);
                if (!$user || $user->user_email !== $recipient['email'] || !user_can($user, 'sdr_member') || !self::roles($id) || !get_user_meta($id, 'sdr_stage_opt_in', true) || !self::personal_email($user) || get_user_meta($id, 'sdr_demo', true) || self::response($event, $id)) {
                    $recipient['status'] = 'skipped';
                    $recipient['error'] = 'Déjà répondu ou profil modifié depuis la sélection : email non envoyé.';
                } else {
                    // Persist before transport: a lost connection must never cause a duplicate email.
                    $recipient['status'] = 'processing';
                    $campaign['recipients'][$id] = $recipient;
                    if (!update_option(self::PREFIX . $campaign['id'], $campaign, false)) return SDR_Coulisses::error('L’état de l’envoi n’a pas pu être sauvegardé. Aucun email supplémentaire n’a été transmis.', 500);
                    $token = self::issue_token($event, $user);
                    if (is_wp_error($token)) {
                        $recipient['status'] = 'failed'; $recipient['error'] = $token->get_error_message();
                    } else {
                        $mail = self::email($event, $user, $token['secret'], $token['expires']);
                        $plain = static function ($mailer) use ($mail) { $mailer->AltBody = $mail['plain']; };
                        add_action('phpmailer_init', $plain);
                        try { $recipient['status'] = wp_mail($user->user_email, $mail['subject'], $mail['html'], ['Content-Type: text/html; charset=UTF-8']) ? 'accepted' : 'failed'; }
                        finally { remove_action('phpmailer_init', $plain); }
                    }
                }
                $campaign['recipients'][$id] = $recipient;
                update_option(self::PREFIX . $campaign['id'], $campaign, false);
                if (++$processed >= 5) break;
            }
            update_option(self::PREFIX . $campaign['id'], $campaign, false);
            return self::summary($campaign);
        });
    }
    static function token_key($event) { return '_sdr_response_link_' . $event; }
    static function issue_token($event, $user) {
        $secret = bin2hex(random_bytes(32));
        $end = (new DateTimeImmutable(SDR_Coulisses::meta($event, 'date') . ' 23:59:59', wp_timezone()))->getTimestamp();
        $expires = min(time() + 14 * DAY_IN_SECONDS, $end);
        if (!update_user_meta($user->ID, self::token_key($event), ['hash' => hash('sha256', $secret), 'expires' => $expires, 'email' => $user->user_email])) return SDR_Coulisses::error('Le lien personnel n’a pas pu être créé.', 500);
        return ['secret' => $secret, 'expires' => $expires];
    }
    static function link($event, $user, $secret, $choice = 'yes') {
        return add_query_arg(['sdr-reponse' => '1', 'spectacle' => $event, 'membre' => $user, 'cle' => $secret, 'choix' => $choice], home_url('/'));
    }
    static function context($request) {
        $event = $request['event'] ?? null; $uid = $request['user'] ?? null; $token = $request['token'] ?? null;
        $invalid = static fn() => SDR_Coulisses::error('Ce lien personnel est invalide ou a expiré. Contacte l’organisation pour une nouvelle invitation.', 403);
        if ((!is_int($event) && !(is_string($event) && ctype_digit($event))) || (!is_int($uid) && !(is_string($uid) && ctype_digit($uid))) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return $invalid();
        $event = (int) $event; $uid = (int) $uid;
        $stored = get_user_meta($uid, self::token_key($event), true);
        if (!is_array($stored) || empty($stored['hash']) || (int) ($stored['expires'] ?? 0) < time() || !hash_equals($stored['hash'], hash('sha256', $token))) return $invalid();
        $user = get_userdata($uid);
        if (!$user || !user_can($user, 'sdr_member') || get_user_meta($uid, 'sdr_demo', true) || !self::roles($uid) || !self::personal_email($user) || $stored['email'] !== $user->user_email) return $invalid();
        if ($error = self::event_error($event)) return $error;
        return ['event' => get_post($event), 'user' => $user, 'roles' => self::roles($uid), 'expires' => $stored['expires'], 'used' => !empty($stored['used'])];
    }
    static function link_permission($request) {
        $context = self::context($request);
        return is_wp_error($context) ? $context : true;
    }
    static function answer($request) {
        $context = self::context($request);
        if (is_wp_error($context)) return $context;
        return SDR_Coulisses::locked($context['event']->ID, static function () use ($request) {
            // Recheck the secret inside the same lock as the write and its consumption.
            $context = self::context($request);
            if (is_wp_error($context)) return $context;
            if ($context['used']) return SDR_Coulisses::error('Ce lien a déjà servi. Pour modifier ta réponse, retrouve le spectacle dans les Coulisses.', 409);
            $status = $request['availability'] ?? '';
            if (!in_array($status, ['yes', 'no'], true)) return SDR_Coulisses::error('Choisis ta disponibilité.');
            $roles = $request['roles'] ?? [];
            if (!is_array($roles) || count(array_filter($roles, 'is_string')) !== count($roles) || array_diff($roles, $context['roles'])) return SDR_Coulisses::error('Choisis uniquement les rôles de ton profil.');
            $result = SDR_Coulisses::write_response($context['event']->ID, $context['user']->ID, ['availability' => $status, 'roles' => $roles], true);
            if (!is_wp_error($result)) {
                $key = self::token_key($context['event']->ID);
                $stored = get_user_meta($context['user']->ID, $key, true);
                $stored['used'] = true;
                update_user_meta($context['user']->ID, $key, $stored);
            }
            return $result;
        });
    }
    static function email($event, $user, $secret, $expires) {
        $details = SDR_Coulisses::event_email_details(get_post($event));
        $name = SDR_Coulisses::email_name($user);
        $roles = array_map(static fn($key) => SDR_Coulisses::ROLES[$key], self::roles($user->ID));
        $yes = self::link($event, $user->ID, $secret);
        $no = self::link($event, $user->ID, $secret, 'no');
        ob_start(); require __DIR__ . '/email.php'; $html = ob_get_clean();
        $plain = "Bonjour $name,\n\nOn te garde une place dans l’imprévu ?\n\n" . $details['title'] . "\n" . $details['date'] . "\n" . $details['venue'] . "\nSpectacle : " . $details['time'] . " — Rendez-vous troupe : " . $details['callTime'] . "\n\nEnvie de participer comme " . implode(', ', $roles) . " ?\n\nOK, je me lance : $yes\n\nJe ne suis pas dispo : $no\n\nAucun mot de passe : tu confirmes ta réponse sur la petite fiche. Ce lien personnel expire le " . wp_date('d/m/Y', $expires) . ". Ne le transfère pas.\n\nUne disponibilité n’est pas une sélection : l’organisation compose ensuite l’équipe.\nÀ bientôt,\nLes Sorties de Route";
        return ['subject' => 'On joue ensemble ? ' . sanitize_text_field($details['title']) . ' — ' . $details['date'], 'html' => $html, 'plain' => $plain];
    }
    static function response_page() {
        if (!isset($_GET['sdr-reponse'])) return;
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $credentials = ['event' => wp_unslash($_GET['spectacle'] ?? ''), 'user' => wp_unslash($_GET['membre'] ?? ''), 'token' => wp_unslash($_GET['cle'] ?? '')];
        $context = self::context($credentials);
        $error = is_wp_error($context) ? $context : null;
        $saved = false; $existing = null;
        $choice = ($_GET['choix'] ?? '') === 'no' ? 'no' : 'yes';
        if (!$error) {
            $existing = self::response($context['event']->ID, $context['user']->ID);
            if ($context['used'] && !$existing) $error = SDR_Coulisses::error('Ce lien a déjà servi. Pour modifier ta réponse, retrouve le spectacle dans les Coulisses.', 409);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$existing) {
                $result = self::answer($credentials + ['availability' => wp_unslash($_POST['availability'] ?? ''), 'roles' => wp_unslash($_POST['roles'] ?? [])]);
                if (is_wp_error($result)) $error = $result;
                else $saved = true;
            }
            $details = SDR_Coulisses::event_email_details($context['event']);
        }
        status_header($error ? $error->get_error_data()['status'] ?? 400 : 200);
        require __DIR__ . '/response.php';
        exit;
    }
}
