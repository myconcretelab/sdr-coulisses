<?php
/** Administration et API du plugin indépendant SDR — Relances utilisateurs. */
defined('ABSPATH') || exit;

final class SDR_Relances {
    const VERSION = '1.0.1';
    const API = 'sdr-relances/v1';
    const CAPABILITY = 'manage_options';

    static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_login', static function ($login, $user) { self::record_login($user->ID); }, 10, 2);
        add_action('user_register', static function ($id) { update_user_meta($id, 'sdr_relances_tracked_account', 1); });
        add_action('init', static function () {
            $id = get_current_user_id();
            if ($id && !get_user_meta($id, 'sdr_relances_last_login', true)) self::record_login($id);
        });
    }
    static function record_login($id) { update_user_meta($id, 'sdr_relances_last_login', time()); }
    static function allowed() { return current_user_can(self::CAPABILITY) && current_user_can('list_users'); }
    static function error($message, $status = 400) { return new WP_Error('sdr_relances_error', $message, ['status' => $status]); }
    static function menu() {
        add_users_page('Relances utilisateurs', 'Relances utilisateurs', self::CAPABILITY, 'sdr-relances', [__CLASS__, 'page']);
    }
    static function assets($hook) {
        if ($hook !== 'users_page_sdr-relances' || !self::allowed()) return;
        wp_enqueue_editor();
        wp_enqueue_style('sdr-relances', plugins_url('admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('sdr-relances', plugins_url('admin.js', __FILE__), ['wp-editor', 'wp-api-fetch'], self::VERSION, true);
        wp_add_inline_script('sdr-relances', 'window.SDRRelances=' . wp_json_encode([
            'api' => rest_url(self::API . '/'), 'nonce' => wp_create_nonce('wp_rest'),
            'email' => wp_get_current_user()->user_email,
        ]) . ';', 'before');
    }
    static function page() {
        if (!self::allowed()) wp_die('Accès réservé à l’administration.', '', ['response' => 403]);
        require __DIR__ . '/page.php';
    }
    static function routes() {
        foreach ([
            '/bootstrap' => ['GET', 'bootstrap'], '/users' => ['GET', 'users'],
            '/preview' => ['POST', 'preview'], '/draft' => ['POST', 'save_draft'],
            '/test' => ['POST', 'test_mail'], '/campaigns' => ['POST', 'create_campaign'],
            '/campaigns/(?P<id>[a-f0-9-]{36})' => ['GET', 'campaign_status'],
            '/campaigns/(?P<id>[a-f0-9-]{36})/send' => ['POST', 'send_batch'],
            '/campaigns/(?P<id>[a-f0-9-]{36})/cancel' => ['POST', 'cancel_campaign'],
        ] as $path => [$method, $callback]) {
            register_rest_route(self::API, $path, ['methods' => $method, 'permission_callback' => [__CLASS__, 'allowed'], 'callback' => [__CLASS__, $callback]]);
        }
    }
    /** Extension : ajouter une clé, un libellé et un callback matches(WP_User, ?WP_Post). */
    static function filters() {
        return apply_filters('sdr_relances_filters', [
            'no_bio' => ['label' => 'Sans biographie', 'matches' => static fn($u) => trim(wp_strip_all_tags($u->description . get_user_meta($u->ID, 'sdr_profile_description', true))) === ''],
            'no_photo' => ['label' => 'Sans photo de profil', 'matches' => static fn($u) => !wp_attachment_is_image((int) get_user_meta($u->ID, 'sdr_profile_photo', true))],
            'no_login' => ['label' => 'Aucune connexion connue', 'matches' => static fn($u) => !self::last_login($u->ID)],
            'never_login' => ['label' => 'Jamais connecté (comptes suivis)', 'matches' => static fn($u) => get_user_meta($u->ID, 'sdr_relances_tracked_account', true) && !self::last_login($u->ID)],
            'no_first_name' => ['label' => 'Sans prénom', 'matches' => static fn($u) => trim($u->first_name) === ''],
            'no_last_name' => ['label' => 'Sans nom', 'matches' => static fn($u) => trim($u->last_name) === ''],
            'no_phone' => ['label' => 'Sans téléphone', 'matches' => static fn($u) => trim((string) get_user_meta($u->ID, 'sdr_phone', true)) === ''],
            'no_diet' => ['label' => 'Repas non renseigné', 'matches' => static fn($u) => in_array(get_user_meta($u->ID, 'sdr_diet', true), ['', 'unspecified'], true)],
            'no_response' => ['label' => 'Sans réponse au spectacle', 'event' => true, 'matches' => static function ($u, $event) {
                if (!$event) return false;
                global $wpdb;
                return !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sdr_responses WHERE user_id = %d AND event_id = %d", $u->ID, $event->ID));
            }],
        ]);
    }
    static function last_login($id) {
        $last = (int) get_user_meta($id, 'sdr_relances_last_login', true);
        // Historique du plugin Invitations et suivi utilisateurs déjà présent en production.
        foreach (['_iu_derniere_connexion', '_iu_premiere_connexion'] as $key) {
            $value = (string) get_user_meta($id, $key, true);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
            if ($date && $date->format('Y-m-d H:i:s') === $value) $last = max($last, $date->getTimestamp());
        }
        // Les sessions WordPress constituent une preuve positive, jamais une preuve d'absence.
        foreach ((array) get_user_meta($id, 'session_tokens', true) as $session) $last = max($last, (int) ($session['login'] ?? 0));
        return $last;
    }
    static function event($id) {
        if (!is_scalar($id)) return self::error('Le spectacle sélectionné est invalide.');
        if (!$id) return null;
        $post = get_post(absint($id));
        if (!$post || $post->post_type !== 'sdr_event' || $post->post_status !== 'publish') return self::error('Le spectacle sélectionné n’est plus disponible.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) get_post_meta($post->ID, '_sdr_date', true), wp_timezone());
        if (!$date || $date->format('Y-m-d') !== get_post_meta($post->ID, '_sdr_date', true)) return self::error('Ce spectacle n’a pas de date valide.');
        return $post;
    }
    /** Les titres WordPress sont formatés pour HTML ; les variables attendent du texte brut. */
    static function text($value) {
        return html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    static function definitions() {
        return apply_filters('sdr_relances_variables', [
            'prenom' => ['label' => 'Prénom (nom affiché si absent)', 'resolve' => static fn($u) => $u->first_name ?: $u->display_name],
            'nom' => ['label' => 'Nom de famille', 'resolve' => static fn($u) => $u->last_name],
            'nom_complet' => ['label' => 'Nom complet', 'resolve' => static fn($u) => trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name],
            'nom_affiche' => ['label' => 'Nom affiché', 'resolve' => static fn($u) => $u->display_name],
            'email' => ['label' => 'Adresse email', 'resolve' => static fn($u) => $u->user_email],
            'lien_profil' => ['label' => 'Lien vers le profil dans les Coulisses', 'resolve' => static fn() => home_url('/coulisses/?vue=profil')],
            'lien_coulisses' => ['label' => 'Lien vers les Coulisses', 'resolve' => static fn() => home_url('/coulisses/')],
            'nom_site' => ['label' => 'Nom du site', 'resolve' => static fn() => self::text(get_bloginfo('name'))],
            'date_du_jour' => ['label' => 'Date du jour', 'resolve' => static fn() => wp_date(get_option('date_format'))],
            'spectacle' => ['label' => 'Titre du spectacle', 'event' => true, 'resolve' => static fn($u, $e) => self::text(get_the_title($e))],
            'date_spectacle' => ['label' => 'Date du spectacle', 'event' => true, 'resolve' => static fn($u, $e) => wp_date(get_option('date_format'), (new DateTimeImmutable(get_post_meta($e->ID, '_sdr_date', true), wp_timezone()))->getTimestamp())],
            'jours_avant_spectacle' => ['label' => 'Jours restants (0 aujourd’hui, négatif si passé)', 'event' => true, 'resolve' => static fn($u, $e) => (new DateTimeImmutable('today', wp_timezone()))->diff(new DateTimeImmutable(get_post_meta($e->ID, '_sdr_date', true), wp_timezone()))->format('%r%a')],
            'heure_spectacle' => ['label' => 'Heure du spectacle', 'event' => true, 'resolve' => static fn($u, $e) => get_post_meta($e->ID, '_sdr_time', true)],
            'heure_rendez_vous' => ['label' => 'Heure de rendez-vous', 'event' => true, 'resolve' => static fn($u, $e) => get_post_meta($e->ID, '_sdr_call_time', true)],
            'lieu_spectacle' => ['label' => 'Lieu du spectacle', 'event' => true, 'resolve' => static fn($u, $e) => ($id = (int) get_post_meta($e->ID, '_sdr_venue', true)) ? self::text(get_the_title($id)) : 'À préciser'],
            'lien_spectacle' => ['label' => 'Lien du spectacle', 'event' => true, 'resolve' => static fn($u, $e) => get_permalink($e)],
        ]);
    }
    static function bootstrap() {
        $events = [];
        if (post_type_exists('sdr_event')) foreach (get_posts(['post_type' => 'sdr_event', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_sdr_date', 'orderby' => 'meta_value', 'order' => 'ASC', 'meta_query' => [['key' => '_sdr_date', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]) as $e) {
            if (!is_wp_error(self::event($e->ID))) $events[] = ['id' => $e->ID, 'label' => self::text(get_the_title($e)) . ' — ' . get_post_meta($e->ID, '_sdr_date', true)];
        }
        $filters = [];
        foreach (self::filters() as $key => $f) {
            if ($key === 'no_response' && !class_exists('SDR_Coulisses')) continue;
            $filters[] = ['key' => $key, 'label' => $f['label'], 'event' => !empty($f['event'])];
        }
        $variables = [];
        foreach (self::definitions() as $key => $v) $variables[] = ['key' => $key, 'label' => $v['label'], 'event' => !empty($v['event'])];
        $roles = [];
        foreach (wp_roles()->roles as $key => $r) $roles[] = ['key' => $key, 'label' => translate_user_role($r['name'])];
        $active = get_user_meta(get_current_user_id(), 'sdr_relances_active', true);
        $campaign = $active ? get_option('sdr_relances_campaign_' . $active) : false;
        return ['filters' => $filters, 'variables' => $variables, 'events' => $events, 'roles' => $roles, 'draft' => get_user_meta(get_current_user_id(), 'sdr_relances_draft', true) ?: null, 'campaign' => $campaign ? self::summary($campaign) : null];
    }
    static function users($request) {
        $event = self::event($request['event'] ?? 0);
        if (is_wp_error($event)) return $event;
        $active = $request['filters'] ?? [];
        if (is_string($active)) $active = $active === '' ? [] : explode(',', $active);
        if (!is_array($active)) return self::error('Les filtres sont invalides.');
        $definitions = self::filters();
        foreach ($active as $key) {
            if (!is_string($key) || !isset($definitions[$key])) return self::error('Un filtre n’est plus disponible.');
            if (!empty($definitions[$key]['event']) && !$event) return self::error('Choisis un spectacle pour ce filtre.');
        }
        if (!is_string($request['role'] ?? 'members') || !is_string($request['search'] ?? '')) return self::error('La recherche ou le rôle est invalide.');
        $role = sanitize_key($request['role'] ?? 'members');
        $query = ['orderby' => 'display_name', 'order' => 'ASC'];
        if ($role === 'members') $query['role__in'] = ['sdr_member', 'sdr_organizer'];
        elseif ($role !== 'all') {
            if (!get_role($role)) return self::error('Ce rôle n’existe pas.');
            $query['role'] = $role;
        }
        $search = mb_strtolower(sanitize_text_field($request['search'] ?? ''));
        $mode = ($request['mode'] ?? 'all') === 'any' ? 'any' : 'all';
        $rows = []; $counts = array_fill_keys(array_keys($definitions), 0);
        foreach (get_users($query) as $u) {
            if (get_user_meta($u->ID, 'sdr_demo', true)) continue;
            if ($search !== '' && !str_contains(mb_strtolower($u->display_name . ' ' . $u->first_name . ' ' . $u->last_name . ' ' . $u->user_email . ' ' . $u->user_login), $search)) continue;
            $missing = [];
            foreach ($definitions as $key => $definition) {
                if (!empty($definition['event']) && !$event) continue;
                if (call_user_func($definition['matches'], $u, $event)) { $missing[] = $key; $counts[$key]++; }
            }
            $matches = array_intersect($active, $missing);
            if ($active && ($mode === 'all' ? count($matches) !== count($active) : !$matches)) continue;
            $last = self::last_login($u->ID);
            $rows[] = ['id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'missing' => $missing, 'login' => $last ? wp_date('d/m/Y H:i', $last) : (get_user_meta($u->ID, 'sdr_relances_tracked_account', true) ? 'Jamais connecté' : 'Historique inconnu'), 'roles' => array_values(array_map(static fn($r) => translate_user_role(wp_roles()->roles[$r]['name'] ?? $r), $u->roles))];
        }
        return ['users' => $rows, 'counts' => $counts];
    }
    static function message($request) {
        foreach (['subject', 'body'] as $key) if (!is_string($request[$key] ?? null)) return self::error('Le message est incomplet.');
        if (strlen($request['body']) > 100000 || mb_strlen($request['subject']) > 200) return self::error('Le message ou son objet est trop long.');
        $subject = sanitize_text_field($request['subject']);
        $body = wp_kses_post($request['body']);
        if (trim($subject) === '' || trim(wp_strip_all_tags($body)) === '') return self::error('Renseigne l’objet et le texte du message.');
        $event = self::event($request['event'] ?? 0);
        if (is_wp_error($event)) return $event;
        $definitions = self::definitions();
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $subject . ' ' . $body, $tokens);
        foreach ($tokens[1] as $key) {
            if (!isset($definitions[$key])) return self::error('Variable inconnue : {{' . $key . '}}.');
            if (!empty($definitions[$key]['event']) && !$event) return self::error('Choisis un spectacle pour utiliser {{' . $key . '}}.');
        }
        return ['subject' => $subject, 'body' => $body, 'event' => $event ? $event->ID : 0];
    }
    static function render($message, $user) {
        $event = self::event($message['event']);
        if (is_wp_error($event)) return $event;
        $map = [];
        foreach (self::definitions() as $key => $definition) {
            $map[$key] = !empty($definition['event']) && !$event ? '' : (string) call_user_func($definition['resolve'], $user, $event);
        }
        $replace = static function ($text, $html) use ($map) {
            return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', static fn($m) => $html ? esc_html($map[$m[1]] ?? '') : sanitize_text_field($map[$m[1]] ?? ''), $text);
        };
        $body = wp_kses_post(wpautop($replace($message['body'], true)));
        $document = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:24px;background:#f0f0f1;color:#1d2327;font:16px/1.65 Arial,sans-serif"><div style="max-width:600px;margin:auto;padding:28px;background:#fff;border:1px solid #dcdcde;border-radius:4px">' . $body . '</div></body></html>';
        return ['subject' => $replace($message['subject'], false), 'html' => $document];
    }
    static function preview($request) {
        $message = self::message($request);
        if (is_wp_error($message)) return $message;
        $user = get_userdata(absint($request['user'] ?? 0));
        if (!$user) return self::error('Choisis un destinataire pour la prévisualisation.');
        $rendered = self::render($message, $user);
        if (is_wp_error($rendered)) return $rendered;
        return array_merge($rendered, ['to' => $user->display_name . ' <' . $user->user_email . '>']);
    }
    static function save_draft($request) {
        if (!is_string($request['subject'] ?? null) || !is_string($request['body'] ?? null) || strlen($request['body']) > 100000) return self::error('Brouillon invalide.');
        $draft = ['subject' => sanitize_text_field(mb_substr($request['subject'], 0, 200)), 'body' => wp_kses_post($request['body']), 'event' => absint($request['event'] ?? 0)];
        update_user_meta(get_current_user_id(), 'sdr_relances_draft', $draft);
        return ['saved' => true];
    }
    static function test_mail($request) {
        $rendered = self::preview($request);
        if (is_wp_error($rendered)) return $rendered;
        $email = wp_get_current_user()->user_email;
        if (!wp_mail($email, '[TEST] ' . $rendered['subject'], $rendered['html'], ['Content-Type: text/html; charset=UTF-8'])) return self::error('WordPress n’a pas pu transmettre l’email de test.', 502);
        return ['message' => 'Email de test transmis à ' . $email . '. Vérifie sa réception.'];
    }
    static function create_campaign($request) {
        global $wpdb;
        $lock = 'sdr_relances_create_' . get_current_user_id();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) return self::error('Un envoi est en cours de création dans un autre onglet.', 409);
        try { return self::prepare_campaign($request); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    private static function prepare_campaign($request) {
        $message = self::message($request);
        if (is_wp_error($message)) return $message;
        $ids = $request['recipients'] ?? null;
        if (!is_array($ids) || !$ids || count($ids) > 5000) return self::error('Sélectionne entre 1 et 5 000 destinataires.');
        foreach ($ids as $id) if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) return self::error('La sélection contient un identifiant invalide.');
        $ids = array_unique(array_map('absint', $ids));
        $matches = self::users($request);
        if (is_wp_error($matches)) return $matches;
        $eligible = array_column($matches['users'], null, 'id');
        $recipients = [];
        foreach ($ids as $id) {
            if (!isset($eligible[$id]) || !is_email($eligible[$id]['email'])) return self::error('La sélection a changé ou contient une adresse invalide. Actualise les destinataires.');
            $recipients[$id] = ['status' => 'pending', 'name' => $eligible[$id]['name'], 'email' => $eligible[$id]['email']];
        }
        $active = get_user_meta(get_current_user_id(), 'sdr_relances_active', true);
        $previous = $active ? get_option('sdr_relances_campaign_' . $active) : false;
        if ($previous && in_array('pending', array_column($previous['recipients'], 'status'), true)) return self::error('Un envoi est déjà en cours. Reprends-le ou annule-le avant de créer un nouvel envoi.', 409);
        $campaign = ['id' => wp_generate_uuid4(), 'author' => get_current_user_id(), 'created' => time(), 'message' => $message, 'recipients' => $recipients];
        add_option('sdr_relances_campaign_' . $campaign['id'], $campaign, '', false);
        update_user_meta(get_current_user_id(), 'sdr_relances_active', $campaign['id']);
        $history = (array) get_user_meta(get_current_user_id(), 'sdr_relances_history', true);
        array_unshift($history, $campaign['id']);
        foreach (array_slice($history, 10) as $old) delete_option('sdr_relances_campaign_' . $old);
        update_user_meta(get_current_user_id(), 'sdr_relances_history', array_slice($history, 0, 10));
        return self::summary($campaign);
    }
    static function load_campaign($request) {
        $campaign = get_option('sdr_relances_campaign_' . $request['id']);
        if (!$campaign || $campaign['author'] !== get_current_user_id()) return self::error('Cet envoi n’est pas accessible.', 404);
        return $campaign;
    }
    static function summary($campaign) {
        $counts = array_count_values(array_column($campaign['recipients'], 'status'));
        $issues = [];
        foreach ($campaign['recipients'] as $recipient) if (in_array($recipient['status'], ['failed', 'processing'], true)) $issues[] = ['name' => $recipient['name'], 'email' => $recipient['email'], 'message' => $recipient['status'] === 'processing' ? 'Transmission interrompue : vérifier la réception avant toute nouvelle relance.' : ($recipient['error'] ?? 'Transmission refusée par WordPress.')];
        return ['id' => $campaign['id'], 'subject' => $campaign['message']['subject'], 'total' => count($campaign['recipients']), 'accepted' => $counts['accepted'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'uncertain' => $counts['processing'] ?? 0, 'pending' => $counts['pending'] ?? 0, 'cancelled' => $counts['cancelled'] ?? 0, 'issues' => $issues];
    }
    static function campaign_status($request) {
        $c = self::load_campaign($request);
        return is_wp_error($c) ? $c : self::summary($c);
    }
    /** Verrou partagé entre onglets ; statut sauvegardé AVANT l'appel mail pour éviter un double envoi. */
    static function mutate_campaign($request, $cancel = false) {
        global $wpdb;
        $lock = 'sdr_relances_' . $request['id'];
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) return self::error('Un autre onglet traite cet envoi. Patiente puis reprends.', 409);
        try {
            $c = self::load_campaign($request);
            if (is_wp_error($c)) return $c;
            $processed = 0;
            foreach (array_keys($c['recipients']) as $id) {
                if ($c['recipients'][$id]['status'] !== 'pending') continue;
                if ($cancel) { $c['recipients'][$id]['status'] = 'cancelled'; continue; }
                $user = get_userdata($id);
                if (!$user || $user->user_email !== $c['recipients'][$id]['email']) {
                    $c['recipients'][$id]['status'] = 'failed';
                    $c['recipients'][$id]['error'] = 'Compte supprimé ou adresse modifiée depuis la sélection.';
                } else {
                    $rendered = self::render($c['message'], $user);
                    if (is_wp_error($rendered)) {
                        $c['recipients'][$id]['status'] = 'failed';
                        $c['recipients'][$id]['error'] = $rendered->get_error_message();
                    } else {
                        $c['recipients'][$id]['status'] = 'processing';
                        update_option('sdr_relances_campaign_' . $c['id'], $c, false);
                        $c['recipients'][$id]['status'] = wp_mail($c['recipients'][$id]['email'], $rendered['subject'], $rendered['html'], ['Content-Type: text/html; charset=UTF-8']) ? 'accepted' : 'failed';
                    }
                }
                update_option('sdr_relances_campaign_' . $c['id'], $c, false);
                if (++$processed >= 5) break;
            }
            update_option('sdr_relances_campaign_' . $c['id'], $c, false);
            return self::summary($c);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    static function send_batch($request) { return self::mutate_campaign($request); }
    static function cancel_campaign($request) { return self::mutate_campaign($request, true); }
}
