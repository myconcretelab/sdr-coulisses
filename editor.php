<?php
defined('ABSPATH') || exit;

/** Native Gutenberg controls over the existing SDR metadata: no data migration. */
final class SDR_Editor {
    const TYPES = ['sdr_event', 'sdr_venue', 'sdr_team', 'sdr_format'];

    static function boot() {
        add_action('init', [__CLASS__, 'register'], 20);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'assets']);
        add_filter('rest_prepare_sdr_event',static function($response,$post){
            if(isset($response->data['meta'])){
                $response->data['meta']['_sdr_teams']=SDR_Coulisses::event_team_ids($post->ID);
                $response->data['meta']['_sdr_team_title_style']=SDR_Coulisses::event_team_title_style($post->ID);
            }
            return $response;
        },10,2);
        add_action('rest_after_insert_sdr_event',static function($post,$request){
            $meta=$request->get_param('meta');
            if(!is_array($meta))return;
            if(array_key_exists('_sdr_teams',$meta)) SDR_Coulisses::sync_event_teams($post->ID,$meta['_sdr_teams']);
            elseif(array_key_exists('_sdr_team',$meta)) SDR_Coulisses::sync_event_teams($post->ID,$meta['_sdr_team']?[(int)$meta['_sdr_team']]:[]);
        },9,2);
    }

    static function register() {
        foreach (self::TYPES as $type) {
            add_post_type_support($type, 'custom-fields');
            foreach (SDR_Coulisses::fields($type) as $key => [$label, $control]) {
                $kind = $control === 'sdr_team_list' ? 'array' : ($control === 'boolean' ? 'boolean' : (($control === 'number' || str_starts_with($control, 'sdr_')) ? 'integer' : 'string'));
                $schema = ['type' => $kind, 'context' => ['edit']];
                if ($control==='team_title_style') $schema['enum']=['with','versus'];
                if ($kind === 'array') $schema+=['items'=>['type'=>'integer','minimum'=>1],'maxItems'=>20,'uniqueItems'=>true];
                if ($kind === 'integer') $schema['minimum'] = 0;
                if ($control === 'number') $schema['maximum'] = 50;
                register_post_meta($type, '_sdr_'.$key, [
                    'type' => $kind, 'single' => true, 'description' => $label,
                    'default' => $control==='team_title_style'?'with':($kind === 'array' ? [] : ($kind === 'boolean' ? false : ($kind === 'integer' ? 0 : ''))),
                    'show_in_rest' => ['schema' => $schema],
                    'auth_callback' => static fn($allowed, $meta_key, $post_id) => current_user_can('edit_post', $post_id),
                    'sanitize_callback' => static function($value) use ($kind, $control) {
                        if ($kind === 'boolean') return rest_sanitize_boolean($value);
                        if ($kind === 'array') return array_values(array_unique(array_map('absint',(array)$value)));
                        if ($kind === 'integer') return absint($value);
                        if ($control === 'textarea') return sanitize_textarea_field($value);
                        if ($control === 'email') return sanitize_email($value);
                        return $control === 'url' ? esc_url_raw($value) : sanitize_text_field($value);
                    },
                ]);
            }
            // Keep the former scalar relation readable and writable by existing installations.
            if($type==='sdr_event') register_post_meta($type,'_sdr_team',['type'=>'integer','single'=>true,'default'=>0,'show_in_rest'=>['schema'=>['type'=>'integer','minimum'=>0,'context'=>['edit']]],'auth_callback'=>static fn($allowed,$key,$id)=>current_user_can('edit_post',$id),'sanitize_callback'=>'absint']);
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
            if($control==='team_title_style' && !in_array($value,['with','versus'],true)) return new WP_Error('sdr_invalid_title_style','Choisis « Avec » ou « VS » pour le titre automatique.',['status'=>400]);
            if ($control==='sdr_team_list') {
                if(!is_array($value)||!array_is_list($value)||count($value)>20) return new WP_Error('sdr_invalid_relation','Sélectionne jusqu’à 20 troupes.',['status'=>400]);
                foreach($value as $team) if(get_post_type($team)!=='sdr_team' || get_post_status($team)!=='publish' || !current_user_can('read_post',$team)) return new WP_Error('sdr_invalid_relation','Une troupe choisie est introuvable ou inaccessible.',['status'=>400]);
                continue;
            }
            if ($control === 'date' && $value !== '' && !SDR_Coulisses::valid_date($value))
                return new WP_Error('sdr_invalid_date', 'La date du spectacle est invalide.', ['status' => 400]);
            if ($control === 'time' && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value))
                return new WP_Error('sdr_invalid_time', 'L’horaire doit être compris entre 00:00 et 23:59.', ['status' => 400]);
            if ($control === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower(wp_parse_url($value, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)))
                return new WP_Error('sdr_invalid_url', 'Indique une adresse web complète commençant par https:// ou http://.', ['status' => 400]);
            if ($control === 'email' && $value !== '' && !is_email($value))
                return new WP_Error('sdr_invalid_email', 'Indique une adresse e-mail valide pour le contact.', ['status' => 400]);
            if (str_starts_with($control, 'sdr_') && $value && (get_post_type($value) !== $control || !in_array(get_post_status($value), ['publish','draft','pending','future','private'], true) || !current_user_can('read_post', $value)))
                return new WP_Error('sdr_invalid_relation', 'La fiche choisie pour « '.$label.' » est introuvable ou inaccessible.', ['status' => 400]);
            if ($id && str_starts_with($key, 'capacity_')) {
                global $wpdb;
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role=%s", $id, substr($key, 9)));
                if ($value < $count) return new WP_Error('sdr_capacity_selected', 'Retire des sélections avant de réduire les places de ce rôle.', ['status' => 400]);
            }
        }
        if($type==='sdr_event' && !empty($meta['_sdr_team']) && (get_post_type($meta['_sdr_team'])!=='sdr_team' || get_post_status($meta['_sdr_team'])!=='publish' || !current_user_can('read_post',$meta['_sdr_team']))) return new WP_Error('sdr_invalid_relation','La troupe choisie est introuvable ou inaccessible.',['status'=>400]);
        return $post;
    }

    static function assets() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, self::TYPES, true)) return;
        wp_enqueue_script('sdr-editor', plugins_url('assets/editor.js', __FILE__), ['wp-plugins','wp-editor','wp-element','wp-components','wp-data','wp-api-fetch','wp-html-entities'], '1.14.0', true);
        wp_enqueue_style('sdr-editor', plugins_url('assets/editor.css', __FILE__), ['wp-components'], '1.1.1');
        global $post, $wpdb;
        $selected = array_fill_keys(array_keys(SDR_Coulisses::ROLES), 0);
        if ($post && $post->post_type === 'sdr_event') {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT selected_role,COUNT(*) AS total FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role<>'' GROUP BY selected_role", $post->ID)) as $row) {
                $selected[$row->selected_role] = (int) $row->total;
            }
        }
        wp_add_inline_script('sdr-editor', 'window.SDREditor='.wp_json_encode([
            'admin' => admin_url(), 'coulisses' => SDR_Settings::workspace_url(['vue'=>'equipes']),
            'selected' => $selected,
            'manageRelations' => current_user_can('edit_sdr_items'),
        ]).';', 'before');
    }
}
SDR_Editor::boot();
