<?php
/** Optional stable releases via WordPress' native plugin updater. */
defined('ABSPATH') || exit;

final class SDR_Updates {
    const REPOSITORY = 'https://github.com/myconcretelab/sdr-coulisses';
    const API = 'https://api.github.com/repos/myconcretelab/sdr-coulisses/releases/latest';
    const OPTION = 'sdr_coulisse_updates';
    const CACHE = 'sdr_coulisse_release';

    static function file() { return plugin_basename(dirname(__DIR__).'/sdr-coulisses.php'); }
    static function managed() { return file_exists(dirname(__DIR__).'/.git'); }
    static function enabled() { return !self::managed() && !is_multisite() && (bool) get_option(self::OPTION, false); }
    static function allowed() { return current_user_can('manage_options') && current_user_can('update_plugins') && !is_multisite(); }

    static function boot() {
        add_action('admin_init', static function () {
            register_setting('sdr_coulisse_updates', self::OPTION, ['type'=>'boolean', 'default'=>false, 'sanitize_callback'=>static fn($value)=>self::allowed() && !self::managed() && (bool)$value]);
        });
        add_action('update_option_'.self::OPTION, [__CLASS__, 'clear']);
        add_action('add_option_'.self::OPTION, [__CLASS__, 'clear']);
        add_action('admin_post_sdr_check_updates', [__CLASS__, 'check_now']);
        add_filter('update_plugins_github.com', [__CLASS__, 'update'], 10, 3);
        add_filter('plugins_api', [__CLASS__, 'information'], 10, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'download'], 10, 4);
    }

    static function clear() { delete_transient(self::CACHE); delete_site_transient('update_plugins'); }

    static function request($url) {
        return wp_safe_remote_get($url, ['timeout'=>8, 'redirection'=>3, 'limit_response_size'=>262144,
            'user-agent'=>'Coulisse-Updater/'.SDR_Setup::version(), 'headers'=>['Accept'=>'application/json']]);
    }

    static function failure($message) {
        $result=['state'=>'error', 'checked'=>time(), 'message'=>$message];
        set_transient(self::CACHE, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    static function release($force=false) {
        if (!self::enabled()) return ['state'=>'disabled'];
        $cached=get_transient(self::CACHE);
        if (!$force && is_array($cached)) return $cached;
        $response=self::request(self::API);
        if (is_wp_error($response)) return self::failure('GitHub est inaccessible. Réessaie plus tard ; la version installée reste utilisable.');
        $status=wp_remote_retrieve_response_code($response);
        if ($status===404) {
            $result=['state'=>'empty', 'checked'=>time(), 'message'=>'Aucune version stable publiée sur GitHub pour le moment.'];
            set_transient(self::CACHE, $result, 6 * HOUR_IN_SECONDS); return $result;
        }
        if ($status!==200) return self::failure('La recherche de version a échoué. Réessaie plus tard ou utilise le ZIP.');
        $data=json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !is_string($data['tag_name']??null) || !preg_match('/^v(\d+\.\d+\.\d+)$/D', $data['tag_name'], $match)
            || ($data['draft']??true)!==false || ($data['prerelease']??true)!==false || !is_array($data['assets']??null)) return self::failure('La version reçue est invalide. Aucune mise à jour proposée.');
        $version=$match[1]; $prefix=self::REPOSITORY.'/releases/download/v'.$version.'/';
        $assets=[];
        foreach ($data['assets'] as $asset) {
            if (is_array($asset) && is_string($asset['name']??null) && ($asset['state']??'')==='uploaded') $assets[$asset['name']]=$asset;
        }
        $filename='coulisse-'.$version.'.zip';
        if (($assets[$filename]['browser_download_url']??'')!==$prefix.$filename || ($assets['release.json']['browser_download_url']??'')!==$prefix.'release.json') return self::failure('La publication ne contient pas l’archive installable et son manifeste. Utilise le ZIP vérifié ou réessaie plus tard.');
        $manifest_response=self::request($prefix.'release.json');
        if (is_wp_error($manifest_response) || wp_remote_retrieve_response_code($manifest_response)!==200) return self::failure('Le manifeste de la version est inaccessible. Aucune mise à jour proposée.');
        $manifest=json_decode(wp_remote_retrieve_body($manifest_response), true);
        if (!is_array($manifest) || ($manifest['version']??'')!==$version || ($manifest['filename']??'')!==$filename
            || !is_string($manifest['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'])
            || !is_string($manifest['requires_wordpress']??null) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $manifest['requires_wordpress'])
            || !is_string($manifest['requires_php']??null) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $manifest['requires_php'])) return self::failure('Le manifeste est invalide. Aucune mise à jour proposée.');
        $digest=$assets[$filename]['digest']??null;
        if ($digest!==null && $digest!=='sha256:'.$manifest['sha256']) return self::failure('Les empreintes de la publication ne correspondent pas. Aucune mise à jour proposée.');
        $result=['state'=>'ready', 'checked'=>time(), 'version'=>$version, 'package'=>$prefix.$filename, 'sha256'=>$manifest['sha256'],
            'requires'=>$manifest['requires_wordpress'], 'requires_php'=>$manifest['requires_php'], 'url'=>self::REPOSITORY.'/releases/tag/v'.$version,
            'notes'=>is_string($data['body']??null)?mb_substr($data['body'],0,20000):''];
        set_transient(self::CACHE, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    static function update($update, $plugin, $file) {
        if ($file!==self::file() || ($plugin['UpdateURI']??'')!==self::REPOSITORY) return $update;
        $release=self::release();
        if (($release['state']??'')!=='ready') return false;
        return ['slug'=>'sdr-coulisses', 'version'=>$release['version'], 'url'=>$release['url'], 'package'=>$release['package'],
            'requires'=>$release['requires'], 'requires_php'=>$release['requires_php']];
    }

    static function information($result, $action, $args) {
        if ($action!=='plugin_information' || ($args->slug??'')!=='sdr-coulisses' || !self::enabled()) return $result;
        $release=self::release();
        if (($release['state']??'')!=='ready') return $result;
        return (object)['name'=>'Coulisse — Gestion de troupe', 'slug'=>'sdr-coulisses', 'version'=>$release['version'],
            'author'=>'myconcretelab', 'homepage'=>self::REPOSITORY, 'requires'=>$release['requires'], 'requires_php'=>$release['requires_php'],
            'download_link'=>$release['package'], 'sections'=>['description'=>'Spectacles, disponibilités, équipes et emails sur le WordPress de la troupe.',
                'changelog'=>nl2br(esc_html($release['notes']))]];
    }

    static function verify_archive($path, $sha256) {
        if (!is_file($path) || !hash_equals($sha256, hash_file('sha256', $path))) {
            if (is_file($path)) wp_delete_file($path);
            return new WP_Error('sdr_update_checksum', 'L’archive téléchargée ne correspond pas à l’empreinte publiée. Installation annulée ; réessaie le téléchargement.');
        }
        return $path;
    }

    static function download($reply, $package, $upgrader, $extra) {
        if ($reply!==false || ($extra['plugin']??'')!==self::file() || !is_string($package) || !str_starts_with($package, self::REPOSITORY.'/releases/download/')) return $reply;
        $release=self::release();
        if (($release['state']??'')!=='ready' || $package!==$release['package']) return new WP_Error('sdr_update_unavailable', 'Cette version ne peut pas être vérifiée. Relance la recherche dans Réglages → Coulisse avant de réessayer.');
        require_once ABSPATH.'wp-admin/includes/file.php';
        $path=download_url($package);
        return is_wp_error($path)?$path:self::verify_archive($path, $release['sha256']);
    }

    static function check_now() {
        if (!self::allowed() || !self::enabled()) wp_die('Action réservée à l’administration avec la recherche de versions activée.', '', ['response'=>403]);
        check_admin_referer('sdr_check_updates');
        self::release(true); delete_site_transient('update_plugins');
        wp_safe_redirect(admin_url('options-general.php?page=sdr-coulisse#sdr-updates')); exit;
    }

    static function render() {
        if (!current_user_can('manage_options')) return;
        echo '<section class="sdr-setup" id="sdr-updates" aria-labelledby="sdr-updates-title"><h2 id="sdr-updates-title">Mises à jour de Coulisse</h2>';
        if (self::managed() || is_multisite()) {
            echo '<p>'.esc_html(self::managed()?'Cette installation est gérée avec Git. Utilise son déploiement habituel pour conserver les changements du dépôt.':'Sur un réseau WordPress, les mises à jour sont gérées par l’administration du réseau. Utilise le ZIP de distribution.').'</p></section>'; return;
        }
        if (!self::allowed()) { echo '<p>La mise à jour des extensions nécessite les droits correspondants de WordPress.</p></section>'; return; }
        echo '<p>Les versions stables peuvent être proposées dans Extensions, puis installées avec « Mettre à jour maintenant ». L’activation de la recherche n’installe rien et ne change pas le choix des mises à jour automatiques de WordPress.</p>';
        echo '<form action="options.php" method="post">'; settings_fields('sdr_coulisse_updates');
        echo '<input type="hidden" name="'.self::OPTION.'" value="0"><label><input type="checkbox" name="'.self::OPTION.'" value="1" '.checked(self::enabled(),true,false).'> Vérifier les nouvelles versions sur GitHub</label><p class="description">Désactivé au départ. Une fois activé, le site consulte la dernière version stable et son manifeste sur GitHub, au plus toutes les six heures. Les contrôles n’envoient ni liste de membres, ni email, ni contenu de spectacle, ni adresse du site. GitHub reçoit l’adresse IP du serveur.</p>';
        submit_button('Enregistrer le choix des mises à jour','secondary','submit',false); echo '</form>';
        $cached=get_transient(self::CACHE);
        if (self::enabled()) {
            echo '<p role="status">';
            if (!is_array($cached)) echo 'La recherche est activée. Clique sur « Vérifier maintenant » pour consulter la dernière version.';
            elseif (($cached['state']??'')==='ready') {
                echo esc_html(version_compare($cached['version'],SDR_Setup::version(),'>')?'Version '.$cached['version'].' disponible.':'La version installée est à jour par rapport à la dernière publication stable.');
                echo ' <a href="'.esc_url($cached['url']).'" target="_blank" rel="noopener noreferrer">Lire les changements ↗</a> · <a href="'.esc_url(admin_url('plugins.php')).'">Ouvrir les extensions →</a>';
            } else echo esc_html($cached['message']??'La recherche reste à effectuer.');
            if (!empty($cached['checked'])) echo ' <small>Dernier contrôle : '.esc_html(wp_date('j/m/Y à H:i',$cached['checked'])).'.</small>';
            echo '</p><form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="sdr_check_updates">'; wp_nonce_field('sdr_check_updates'); submit_button('Vérifier maintenant','secondary','submit',false); echo '</form>';
        }
        echo '<p>Sauvegarde le site avant une mise à jour. L’archive et son empreinte sont vérifiées avant l’installation. La mise à jour par ZIP reste disponible.</p></section>';
    }
}
SDR_Updates::boot();
