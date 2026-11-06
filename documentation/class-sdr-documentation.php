<?php
/** Member guides, shipped with the plugin and enriched from its field registry. */
defined('ABSPATH') || exit;

final class SDR_Documentation {
    static function boot() {
        add_action('rest_api_init', static function () {
            register_rest_route('sdr/v1', '/documentation', [
                'methods' => 'GET',
                'permission_callback' => ['SDR_Coulisses', 'member'],
                'callback' => [__CLASS__, 'read'],
            ]);
        });
    }

    static function read() {
        try {
            $data = json_decode(file_get_contents(__DIR__.'/guides.json'), true, 512, JSON_THROW_ON_ERROR);
            if (empty($data['domains'])) throw new RuntimeException('Missing guides.');
        } catch (Throwable $error) {
            return new WP_Error('sdr_documentation_unavailable', 'La documentation ne peut pas être chargée. Réessaie dans un instant.', ['status' => 500]);
        }
        foreach ($data['domains'] as &$domain) {
            foreach ($domain['guides'] as &$guide) {
                if (in_array($guide['reference'] ?? '', ['sdr_event', 'sdr_venue'], true)) {
                    $guide['fields'] = array_values(array_map(static fn($field) => $field[0], SDR_Coulisses::fields($guide['reference'])));
                }
            }
            unset($guide);
        }
        unset($domain);
        $data['updatedLabel'] = wp_date('j F Y', strtotime($data['updated'].' 12:00:00 UTC'));
        $response = rest_ensure_response($data);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
SDR_Documentation::boot();
