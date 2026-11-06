<?php
/** Functional blocks belong to the plugin; old block names remain readable. */
defined('ABSPATH') || exit;

final class SDR_Public_Blocks {
    static function boot() {
        add_filter('the_content', [__CLASS__, 'public_content'], 12);
        add_filter('register_post_type_args', static function ($args, $type) {
            $templates = ['sdr_event' => [['sdr/details']], 'sdr_venue' => [['sdr/details'], ['sdr/related-events']], 'sdr_team' => [['sdr/details'], ['sdr/related-events']], 'sdr_format' => [['sdr/related-events']]];
            if (isset($templates[$type])) $args['template'] = $templates[$type];
            return $args;
        }, 10, 2);
        add_action('init', static function () {
            wp_register_style('sdr-public', plugins_url('../assets/public.css', __FILE__), [], '1.11.0');
            wp_register_script('sdr-public-block-editor', plugins_url('../assets/public-blocks.js', __FILE__), ['wp-blocks', 'wp-block-editor', 'wp-element', 'wp-server-side-render'], '1.11.0', true);
            foreach (['details', 'related-events', 'legacy-details', 'legacy-related-events'] as $folder) {
                register_block_type(__DIR__.'/../blocks/'.$folder, ['render_callback' => [__CLASS__, str_contains($folder, 'related-events') ? 'related_events' : 'details']]);
            }
        }, 15);
    }
    /** App-created fiches have plain content; practical information must still be visible. */
    static function public_content($content) {
        if (is_admin() || !is_singular(['sdr_event', 'sdr_venue', 'sdr_team', 'sdr_format']) || !in_the_loop() || !is_main_query()) return $content;
        $post = get_post();
        if (!$post) return $content;
        $block = (object) ['context' => ['postId' => $post->ID]];
        $source = $post->post_content;
        if ($post->post_type !== 'sdr_format' && !has_block('sdr/details', $source) && !has_block('sdr-scene/details', $source)) $content = self::details([], '', $block).$content;
        if ($post->post_type !== 'sdr_event' && !has_block('sdr/related-events', $source) && !has_block('sdr-scene/related-events', $source)) $content .= self::related_events([], '', $block);
        return $content;
    }
static function details($attributes, $content, $block) {
    $post_id = (int) ($block->context['postId'] ?? get_the_ID());
    $post = get_post($post_id);
    if (!$post || !class_exists('SDR_Coulisses')) return '';
    $type = $post->post_type;
    if ($type === 'sdr_event') {
        $event = SDR_Coulisses::event_data($post);
        $date = !empty($event['date']) ? wp_date('l j F Y', strtotime($event['date'].' 12:00:00')) : 'Date à venir';
        $output = '<section class="sdr-detail-ticket" aria-label="Informations pratiques du spectacle">';
        $output .= '<p class="sdr-detail-ticket-date">'.esc_html($date).'</p>';
        $output .= '<div class="sdr-detail-facts">';
        if (!empty($event['venue'])) {
            $venue = !empty($event['venueUrl']) ? '<a href="'.esc_url($event['venueUrl']).'">'.esc_html($event['venue']).'</a>' : esc_html($event['venue']);
            $output .= '<p><span aria-hidden="true">⌖</span><span>'.$venue;
            if (!empty($event['address'])) $output .= '<small>'.esc_html($event['address']).'</small>';
            $output .= '</span></p>';
        }
        $output .= '<p><span aria-hidden="true">◷</span><span>'.esc_html(!empty($event['time']) ? str_replace(':', 'h', $event['time']) : 'Horaire à venir').'</span></p>';
        if (!empty($event['intermissionSet'])) $output .= '<p><span aria-hidden="true">⏸</span><span>'.($event['intermission'] ? 'Avec entracte' : 'Sans entracte').'</span></p>';
        if (!empty($event['format'])) {
            $format = !empty($event['formatUrl']) ? '<a href="'.esc_url($event['formatUrl']).'">'.esc_html($event['format']).'</a>' : esc_html($event['format']);
            $output .= '<p><span aria-hidden="true">✳</span><span>'.$format.'</span></p>';
        }
        if (!empty($event['team'])) {
            $team = !empty($event['teamUrl']) ? '<a href="'.esc_url($event['teamUrl']).'">'.esc_html($event['team']).'</a>' : esc_html($event['team']);
            $output .= '<p><span aria-hidden="true">↗</span><span>Avec '.$team.'</span></p>';
        }
        $output .= '</div></section>';
        $booking = SDR_Coulisses::meta($post_id, 'booking_url');
        if ($booking) $output .= '<p class="sdr-detail-actions"><a class="button primary" href="'.esc_url($booking).'">Réserver ma place ↗</a></p>';
        $output .= '<p class="sdr-detail-members"><a href="'.esc_url(SDR_Settings::workspace_url()).'">Je suis membre de la troupe ↗</a></p>';
        return $output;
    }
    if (!in_array($type, ['sdr_venue', 'sdr_team'], true)) return '';
    $rows = [];
    foreach (['address', 'city'] as $key) {
        $value = SDR_Coulisses::meta($post_id, $key);
        if ($value) $rows[] = '<p>'.esc_html($value).'</p>';
    }
    $url = SDR_Coulisses::meta($post_id, 'website');
    if ($url) $rows[] = '<p><a href="'.esc_url($url).'">Visiter le site internet ↗</a></p>';
    if ($rows) return '<section class="sdr-detail-ticket sdr-detail-ticket-simple" aria-label="Informations pratiques"><div class="sdr-detail-facts">'.implode('', $rows).'</div></section>';
    return defined('REST_REQUEST') && REST_REQUEST
        ? '<p class="sdr-detail-editor-empty">Ajoutez une adresse ou un site à cette fiche pour afficher son billet pratique.</p>'
        : '';
}

static function related_events($attributes, $content, $block) {
    $post_id = (int) ($block->context['postId'] ?? get_the_ID());
    $post = get_post($post_id);
    $meta = ['sdr_venue' => 'venue', 'sdr_team' => 'team', 'sdr_format' => 'format'];
    if (!$post || !isset($meta[$post->post_type]) || !class_exists('SDR_Coulisses')) return '';
    $events = get_posts([
        'post_type' => 'sdr_event', 'post_status' => 'publish', 'numberposts' => 6,
        'meta_key' => '_sdr_date', 'orderby' => 'meta_value', 'order' => 'ASC',
        'meta_query' => [
            ['key' => '_sdr_'.$meta[$post->post_type], 'value' => (string) $post_id],
            ['key' => '_sdr_date', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE'],
        ],
    ]);
    $output = '<section class="sdr-related-events" aria-label="Prochains spectacles"><h2 class="sdr-related-heading">À l’affiche</h2>';
    if (!$events) return $output.'<p class="sdr-related-empty">Aucune date annoncée pour le moment. Revenez bientôt&nbsp;!</p></section>';
    $output .= '<div class="sdr-related-list">';
    foreach ($events as $event) {
        $data = SDR_Coulisses::event_data($event);
        $date = !empty($data['date']) ? strtotime($data['date'].' 12:00:00') : false;
        $output .= '<a class="sdr-related-card" href="'.esc_url(get_permalink($event)).'">';
        $output .= '<span class="sdr-related-date">'.esc_html($date ? wp_date('j M Y', $date) : 'Date à venir').'</span>';
        $output .= '<strong>'.esc_html(get_the_title($event)).'</strong>';
        $output .= '<span class="sdr-related-arrow" aria-hidden="true">↗</span></a>';
    }
    return $output.'</div></section>';
}

}
SDR_Public_Blocks::boot();
