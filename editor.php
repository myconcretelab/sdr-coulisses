<?php
defined('ABSPATH') || exit;

/** Native Gutenberg controls over the existing SDR metadata: no data migration. */
final class SDR_Editor {
    const TYPES = ['sdr_event', 'sdr_venue', 'sdr_team', 'sdr_format'];

    static function boot() {
        add_action('init', [__CLASS__, 'register'], 20);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'assets']);
    }

    static function register() {
        foreach (self::TYPES as $type) {
            add_post_type_support($type, 'custom-fields');
            foreach (SDR_Coulisses::fields($type) as $key => [$label, $control]) {
                $kind = $key === 'closed' ? 'boolean' : (($control === 'number' || str_starts_with($control, 'sdr_')) ? 'integer' : 'string');
                $schema = ['type' => $kind, 'context' => ['edit']];
                if ($kind === 'integer') $schema['minimum'] = 0;
                if ($control === 'number' && $key !== 'closed') $schema['maximum'] = 50;
                register_post_meta($type, '_sdr_'.$key, [
                    'type' => $kind, 'single' => true, 'description' => $label,
                    'default' => $kind === 'boolean' ? false : ($kind === 'integer' ? 0 : ''),
                    'show_in_rest' => ['schema' => $schema],
                    'auth_callback' => static fn($allowed, $meta_key, $post_id) => current_user_can('edit_post', $post_id),
                    'sanitize_callback' => static function($value) use ($kind, $control) {
                        if ($kind === 'boolean') return rest_sanitize_boolean($value);
                        if ($kind === 'integer') return absint($value);
                        return $control === 'url' ? esc_url_raw($value) : sanitize_text_field($value);
                    },
                ]);
            }
            add_filter('rest_pre_insert_'.$type, [__CLASS__, 'validate'], 10, 2);
        }
    }

    static function validate($post, $request) {
        if (is_wp_error($post)) return $post;
        $meta = $request->get_param('meta');
        if (!is_array($meta)) return $post;
        $id = (int) $request->get_param('id');
        $type = $id ? get_post_type($id) : $post->post_type;
        foreach (SDR_Coulisses::fields($type) as $key => [$label, $control]) {
            $name = '_sdr_'.$key;
            if (!array_key_exists($name, $meta)) continue;
            $value = $meta[$name];
            if ($value === null) return new WP_Error('sdr_invalid_meta', 'Utilise une valeur vide ou zéro pour effacer ce champ.', ['status' => 400]);
            if ($control === 'date' && $value !== '' && !SDR_Coulisses::valid_date($value))
                return new WP_Error('sdr_invalid_date', 'La date du spectacle est invalide.', ['status' => 400]);
            if ($control === 'time' && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value))
                return new WP_Error('sdr_invalid_time', 'L’horaire doit être compris entre 00:00 et 23:59.', ['status' => 400]);
            if ($control === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower(wp_parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)))
                return new WP_Error('sdr_invalid_url', 'Indique une adresse web complète commençant par https:// ou http://.', ['status' => 400]);
            if (str_starts_with($control, 'sdr_') && $value && (get_post_type($value) !== $control || !in_array(get_post_status($value), ['publish','draft','pending','future','private'], true) || !current_user_can('read_post', $value)))
                return new WP_Error('sdr_invalid_relation', 'La fiche choisie pour « '.$label.' » est introuvable ou inaccessible.', ['status' => 400]);
            if ($id && str_starts_with($key, 'capacity_')) {
                global $wpdb;
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role=%s", $id, substr($key, 9)));
                if ($value < $count) return new WP_Error('sdr_capacity_selected', 'Retire des sélections avant de réduire les places de ce rôle.', ['status' => 400]);
            }
        }
        return $post;
    }

    static function assets() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, self::TYPES, true)) return;
        wp_enqueue_script('sdr-editor', plugins_url('assets/editor.js', __FILE__), ['wp-plugins','wp-editor','wp-element','wp-components','wp-data','wp-api-fetch','wp-html-entities'], '1.3.0', true);
        wp_enqueue_style('sdr-editor', plugins_url('assets/editor.css', __FILE__), ['wp-components'], '1.1.1');
        global $post, $wpdb;
        $selected = array_fill_keys(array_keys(SDR_Coulisses::ROLES), 0);
        if ($post && $post->post_type === 'sdr_event') {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT selected_role,COUNT(*) AS total FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role<>'' GROUP BY selected_role", $post->ID)) as $row) {
                $selected[$row->selected_role] = (int) $row->total;
            }
        }
        wp_add_inline_script('sdr-editor', 'window.SDREditor='.wp_json_encode([
            'admin' => admin_url(), 'coulisses' => home_url('/coulisses/?vue=equipes'),
            'selected' => $selected,
            'manageRelations' => current_user_can('edit_sdr_items'),
        ]).';', 'before');
    }
}
SDR_Editor::boot();
