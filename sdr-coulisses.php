<?php
/**
 * Plugin Name: SDR — Les Coulisses
 * Description: Agenda public, disponibilités et composition des équipes des Sorties de Route.
 * Version: 1.4.7
 * Requires PHP: 8.1
 * Text Domain: sdr
 */
defined('ABSPATH') || exit;

final class SDR_Coulisses {
    const ROLES = ['joueur'=>'Joueur·se', 'mc'=>'MC', 'dj'=>'DJ'];
    const ITEM_CAPS = ['edit_sdr_item','read_sdr_item','delete_sdr_item','edit_sdr_items','edit_others_sdr_items','publish_sdr_items','read_private_sdr_items','delete_sdr_items','delete_private_sdr_items','delete_published_sdr_items','delete_others_sdr_items','edit_private_sdr_items','edit_published_sdr_items','upload_files'];
    const EVENT_CAPS = ['edit_sdr_events','edit_others_sdr_events','publish_sdr_events','read_private_sdr_events','delete_sdr_events','delete_private_sdr_events','delete_published_sdr_events','delete_others_sdr_events','edit_private_sdr_events','edit_published_sdr_events'];
    static function boot() {
        add_action('init', [__CLASS__, 'sync_roles'], 1);
        add_action('init', [__CLASS__, 'register']);
        add_action('transition_post_status', [__CLASS__, 'queue_new_event_email'], 10, 3);
        add_action('rest_after_insert_sdr_event', [__CLASS__, 'send_pending_event_email'], 10, 3);
        add_action('sdr_notify_new_event', [__CLASS__, 'notify_new_event']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'editor_block_data']);
        add_action('show_user_profile', [__CLASS__, 'user_fields']);
        add_action('edit_user_profile', [__CLASS__, 'user_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_user_fields']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_user_fields']);
        add_filter('get_avatar_data', [__CLASS__, 'avatar_data'], 20, 2);
        add_filter('nsl_is_register_allowed','__return_false',100);
        add_filter('nsl_disabled_register_error_message',fn()=> 'L’accès est réservé à la troupe. Connecte-toi à ton compte membre pour y associer Facebook.');
        add_filter('wp_mail_from',function($from){$email=sanitize_email(get_option('admin_email'));return is_email($email)?$email:$from;});
        add_filter('wp_mail_from_name',fn()=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES));
        add_action('admin_menu',function(){add_management_page('Import SDR','Import SDR','manage_options','sdr-import',[__CLASS__,'import_report']);});
        add_action('admin_menu',function(){
            $hook=add_submenu_page('edit.php?post_type=sdr_event','Composer les équipes','Composer les équipes','sdr_organize','sdr-equipes','__return_null');
            add_action('load-'.$hook,function(){wp_safe_redirect(home_url('/coulisses/?vue=equipes'));exit;});
        });
        add_filter('post_row_actions',function($actions,$post){if($post->post_type==='sdr_event' && self::organizer())$actions['sdr_team']='<a href="'.esc_url(add_query_arg(['vue'=>'equipes','spectacle'=>$post->ID],home_url('/coulisses/'))).'">Composer l’équipe</a>';return $actions;},10,2);
        add_action('template_redirect', function() {
            if (is_page(['coulisses','connexion-facebook'])) { nocache_headers(); if (!is_user_logged_in()) { auth_redirect(); exit; } if(!self::member()) wp_die('Cet espace est réservé aux membres de la troupe.','Accès réservé',['response'=>403]); }
        });
        add_filter('login_redirect', function($to,$requested,$user) { return !is_wp_error($user) && $user instanceof WP_User && !user_can($user,'manage_options') ? home_url('/coulisses/') : $to; },10,3);
        add_filter('login_message',function($message){return isset($_GET['sdr_admin'])?'<div class="message"><p>L’administration WordPress est réservée aux comptes « Administrateur ». Les membres et l’organisation utilisent les Coulisses.</p></div>'.$message:$message;});
        add_filter('show_admin_bar', function($show) { return current_user_can('manage_options') ? $show : false; });
        add_action('admin_init', function(){
            if(wp_doing_ajax() || !current_user_can('sdr_member') || current_user_can('manage_options')) return;
            global $pagenow;
            $id=absint($_GET['post']??$_POST['post_ID']??0);
            if($pagenow==='post.php' && $id && get_post_type($id)==='sdr_event' && self::organizer() && current_user_can('edit_post',$id)) return;
            wp_safe_redirect(home_url('/coulisses/')); exit;
        });
        add_action('wp_enqueue_scripts', function(){ wp_enqueue_style('sdr-ui',plugins_url('assets/app.css',__FILE__),[], '1.4.7'); });
        add_shortcode('sdr_agenda', [__CLASS__, 'agenda']);
        add_shortcode('sdr_coulisses', [__CLASS__, 'app']);
        add_shortcode('sdr_facebook', [__CLASS__, 'facebook']);
    }
    static function activate() {
        add_role('sdr_member','Membre de la troupe',['read'=>true,'sdr_member'=>true]);
        add_role('sdr_organizer','Organisation SDR',['read'=>true,'sdr_member'=>true,'sdr_organize'=>true]);
        self::sync_roles(true);
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$wpdb->prefix}sdr_responses (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            availability varchar(20) NOT NULL DEFAULT 'yes',
            roles text NOT NULL,
            selected_role varchar(20) NOT NULL DEFAULT '',
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_user (event_id,user_id)
        ) {$wpdb->get_charset_collate()};");
        self::register(); flush_rewrite_rules();
    }
    static function sync_roles($force=false) {
        if(!$force && get_option('sdr_role_permissions_version')==='1.4.5') return;
        $organizer=get_role('sdr_organizer');
        if($organizer){
            foreach(['read','sdr_member','sdr_organize'] as $cap) $organizer->add_cap($cap);
            foreach(self::ITEM_CAPS as $cap) $organizer->remove_cap($cap);
            foreach(['edit_sdr_events','edit_others_sdr_events','publish_sdr_events','edit_published_sdr_events'] as $cap) $organizer->add_cap($cap);
        }
        $admin=get_role('administrator');
        if($admin){
            foreach(array_merge(['sdr_member','sdr_organize'],self::ITEM_CAPS,self::EVENT_CAPS) as $cap) $admin->add_cap($cap);
        }
        update_option('sdr_role_permissions_version','1.4.5',false);
    }
    static function register() {
        foreach(['sdr_event'=>['Spectacles','Spectacle','calendar-alt','spectacles'], 'sdr_venue'=>['Lieux','Lieu','location-alt','lieux'], 'sdr_team'=>['Troupes invitées','Troupe','groups','troupes'], 'sdr_format'=>['Formats','Format','format-video','formats']] as $type=>$d) {
            register_post_type($type,['labels'=>['name'=>$d[0],'singular_name'=>$d[1],'add_new_item'=>'Ajouter : '.$d[1],'edit_item'=>'Modifier : '.$d[1]],'capability_type'=>$type==='sdr_event'?['sdr_event','sdr_events']:['sdr_item','sdr_items'],'map_meta_cap'=>true,'public'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-'.$d[2],'supports'=>['title','editor','thumbnail','excerpt'],'rewrite'=>['slug'=>$d[3]],'has_archive'=>false]);
        }
        wp_register_style('sdr-roster',plugins_url('assets/roster.css',__FILE__),[], '1.4.1');
        register_block_type(__DIR__.'/blocks/agenda',['render_callback'=>[__CLASS__,'agenda']]);
        register_block_type(__DIR__.'/blocks/coulisses',['render_callback'=>[__CLASS__,'app']]);
        register_block_type(__DIR__.'/blocks/trombinoscope',['render_callback'=>[__CLASS__,'roster']]);
        wp_register_script('sdr-blocks',plugins_url('assets/blocks.js',__FILE__),['wp-blocks','wp-element','wp-block-editor','wp-components'],'1.4.1',true);
    }
    static function editor_block_data(){ wp_add_inline_script('sdr-blocks','window.SDRBlocks='.wp_json_encode(['members'=>self::roster_member_options()]).';','before'); }
    static function member(){ return is_user_logged_in() && current_user_can('sdr_member'); }
    static function organizer(){ return self::member() && current_user_can('sdr_organize'); }
    static function error($text,$status=400){ return new WP_Error('sdr_error',$text,['status'=>$status]); }
    static function meta($id,$key,$default=''){ $v=get_post_meta($id,'_sdr_'.$key,true); return $v==='' ? $default : $v; }
    static function fields($type) {
        if($type==='sdr_event') return ['date'=>['Date','date'],'time'=>['Début du spectacle','time'],'call_time'=>['Rendez-vous des membres','time'],'venue'=>['Lieu','sdr_venue'],'team'=>['Troupe invitée','sdr_team'],'format'=>['Format','sdr_format'],'capacity_joueur'=>['Places de joueurs SDR','number'],'capacity_mc'=>['Places MC','number'],'capacity_dj'=>['Places DJ','number'],'booking_url'=>['Lien de billetterie (facultatif)','url'],'closed'=>['Réponses fermées','boolean'],'intermission'=>['Avec entracte','boolean']];
        if($type==='sdr_venue') return ['address'=>['Adresse publique','text'],'city'=>['Commune','text'],'website'=>['Site internet','url'],'contact'=>['Contact interne (réservé à l’administration)','text']];
        if($type==='sdr_team') return ['city'=>['Ville','text'],'website'=>['Site internet','url']];
        return [];
    }
    static function valid_date($v){ $d=DateTime::createFromFormat('!Y-m-d',$v); return $d && $d->format('Y-m-d')===$v; }
    static function profile($uid){
        $user=get_userdata($uid); $photo=(int)get_user_meta($uid,'sdr_profile_photo',true);
        $description=$user?trim((string)$user->description):'';
        if($description==='' && $user){
            $legacy=trim((string)get_user_meta($uid,'sdr_profile_description',true));
            if($legacy!==''){
                $migrated=wp_update_user(['ID'=>$uid,'description'=>$legacy]);
                if(!is_wp_error($migrated)) delete_user_meta($uid,'sdr_profile_description');
                $description=$legacy;
            }
        }
        return ['name'=>$user?$user->display_name:'','roles'=>get_user_meta($uid,'sdr_roles',true)?:['joueur'],'diet'=>get_user_meta($uid,'sdr_diet',true)?:'unspecified','gender'=>get_user_meta($uid,'sdr_gender',true),'experience'=>get_user_meta($uid,'sdr_experience',true),'description'=>$description,'likes'=>self::profile_list(get_user_meta($uid,'sdr_likes',true)),'dislikes'=>self::profile_list(get_user_meta($uid,'sdr_dislikes',true)),'photoId'=>$photo,'photoUrl'=>$photo?wp_get_attachment_image_url($photo,'medium'):'','rosterVisible'=>(bool)get_user_meta($uid,'sdr_roster_visible',true)];
    }
    static function profile_list($items){ return array_values(array_filter(array_map('sanitize_text_field',(array)$items),static fn($item)=>$item!=='')); }
    static function event_title($title){
        $parts=explode(' VS ',(string)$title,2);
        if(count($parts)!==2) return esc_html($title);
        return esc_html($parts[0]).' <span class="event-vs" aria-label="versus">VS</span> '.esc_html($parts[1]);
    }
    static function avatar_data($args,$id_or_email){
        $user=false;
        if($id_or_email instanceof WP_User) $user=$id_or_email;
        elseif($id_or_email instanceof WP_Comment){
            $user=$id_or_email->user_id?get_user_by('id',$id_or_email->user_id):false;
            if(!$user && $id_or_email->comment_author_email) $user=get_user_by('email',$id_or_email->comment_author_email);
        } elseif($id_or_email instanceof WP_Post){
            $user=$id_or_email->post_author?get_user_by('id',$id_or_email->post_author):false;
        } elseif(is_numeric($id_or_email)) $user=get_user_by('id',absint($id_or_email));
        elseif(is_string($id_or_email) && is_email($id_or_email)) $user=get_user_by('email',$id_or_email);
        if(!$user) return $args;
        $photo=(int)get_user_meta($user->ID,'sdr_profile_photo',true);
        if(!$photo || !wp_attachment_is_image($photo)) return $args;
        $size=max(32,min(512,(int)($args['size']??96)));
        $image=wp_get_attachment_image_src($photo,[$size,$size]);
        if(!$image) $image=wp_get_attachment_image_src($photo,'thumbnail');
        if(!$image) return $args;
        $args['url']=$image[0];
        $args['found_avatar']=true;
        return $args;
    }
    static function user_fields($user){
        if(!current_user_can('manage_options')) return; echo '<h2>Profil SDR (privé)</h2><p><strong>Rôles artistiques</strong> — plusieurs choix possibles.</p>'; wp_nonce_field('sdr_user','sdr_user_nonce'); $p=self::profile($user->ID);
        foreach(self::ROLES as $k=>$label) echo '<label style="margin-right:20px"><input type="checkbox" name="sdr_roles[]" value="'.$k.'" '.checked(in_array($k,$p['roles']),true,false).'> '.esc_html($label).'</label>';
        echo '<p><label>Téléphone <input type="tel" name="sdr_phone" inputmode="tel" autocomplete="tel" maxlength="30" value="'.esc_attr(get_user_meta($user->ID,'sdr_phone',true)).'" placeholder="06 12 34 56 78"></label></p><p><label>Repas <select name="sdr_diet">';foreach(['unspecified'=>'À préciser','standard'=>'Sans préférence','vegetarian'=>'Végétarien','vegan'=>'Végétalien'] as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($p['diet'],$k,false).'>'.esc_html($v).'</option>';echo '</select></label></p><p><label>Années d’impro <input type="number" min="0" max="80" name="sdr_experience" value="'.esc_attr($p['experience']).'"></label></p><p><label>Genre / désignation (facultatif) <input name="sdr_gender" maxlength="80" value="'.esc_attr($p['gender']).'"></label></p><p>Le téléphone et ces informations restent privés. Les membres peuvent compléter leurs préférences depuis leur espace personnel.</p>';
    }
    static function save_user_fields($uid){
        if(!current_user_can('manage_options') || !isset($_POST['sdr_user_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sdr_user_nonce'])),'sdr_user')) return;
        delete_user_meta($uid,'sdr_profile_description');
        update_user_meta($uid,'sdr_roles',array_values(array_intersect(array_keys(self::ROLES),(array)($_POST['sdr_roles']??[]))));
        $diet=sanitize_key($_POST['sdr_diet']??'unspecified');if(in_array($diet,['unspecified','standard','vegetarian','vegan'],true))update_user_meta($uid,'sdr_diet',$diet);
        update_user_meta($uid,'sdr_experience',($_POST['sdr_experience']??'')===''?'':min(80,absint($_POST['sdr_experience'])));
        update_user_meta($uid,'sdr_gender',sanitize_text_field(mb_substr(wp_unslash($_POST['sdr_gender']??''),0,80)));
        update_user_meta($uid,'sdr_phone',sanitize_text_field(mb_substr(wp_unslash($_POST['sdr_phone']??''),0,30)));
    }
    static function routes(){
        register_rest_route('sdr/v1','/dashboard',['methods'=>'GET','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'dashboard']]);
        register_rest_route('sdr/v1','/archives',['methods'=>'GET','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'archives']]);
        register_rest_route('sdr/v1','/event-options',['methods'=>'GET','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'event_options']]);
        register_rest_route('sdr/v1','/events',['methods'=>'POST','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'create_event']]);
        register_rest_route('sdr/v1','/events/(?P<id>\d+)/edit',[
            ['methods'=>'GET','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'edit_event_data']],
            ['methods'=>'POST','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'update_event']],
        ]);
        register_rest_route('sdr/v1','/profile',['methods'=>'POST','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'save_profile']]);
        register_rest_route('sdr/v1','/profile-photo',['methods'=>'POST','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'save_profile_photo']]);
        register_rest_route('sdr/v1','/password',['methods'=>'POST','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'save_password']]);
        register_rest_route('sdr/v1','/events/(?P<id>\d+)/response',['methods'=>'POST','permission_callback'=>[__CLASS__,'member'],'callback'=>[__CLASS__,'respond']]);
        register_rest_route('sdr/v1','/events/(?P<id>\d+)/selection',['methods'=>'POST','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'select']]);
        register_rest_route('sdr/v1','/events/(?P<id>\d+)/settings',['methods'=>'POST','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'event_settings']]);
        register_rest_route('sdr/v1','/events/(?P<id>\d+)/finalize',['methods'=>'POST','permission_callback'=>[__CLASS__,'organizer'],'callback'=>[__CLASS__,'finalize_team']]);
    }
    static function event_options(){
        $result=['today'=>current_time('Y-m-d')];
        foreach(['venues'=>'sdr_venue','teams'=>'sdr_team','formats'=>'sdr_format'] as $key=>$type){
            $result[$key]=array_map(fn($post)=>['id'=>$post->ID,'name'=>$post->post_title,'address'=>$type==='sdr_venue'?self::meta($post->ID,'address'):'','city'=>$type==='sdr_venue'?self::meta($post->ID,'city'):''],get_posts(['post_type'=>$type,'post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']));
        }
        return $result;
    }
    static function event_form_fields($data){
        if(!is_array($data)) return self::error('Le formulaire est incomplet.');
        foreach(['title','date','time','callTime','bookingUrl','description'] as $key){
            if(array_key_exists($key,$data) && !is_string($data[$key])) return self::error('Le champ « '.$key.' » est invalide.');
        }
        $title=sanitize_text_field($data['title']??'');
        if($title==='' || mb_strlen($title)>200) return self::error('Indique un titre de 1 à 200 caractères.');
        $date=$data['date']??'';
        if(!self::valid_date($date)) return self::error('Indique une date de spectacle valide.');
        $meta=['_sdr_date'=>$date];
        foreach(['time'=>'time','callTime'=>'call_time'] as $field=>$key){
            $value=$data[$field]??'';
            if($value!=='' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$value)) return self::error('Les horaires doivent être compris entre 00:00 et 23:59.');
            $meta['_sdr_'.$key]=$value;
        }
        foreach(['venue'=>'sdr_venue','team'=>'sdr_team','format'=>'sdr_format'] as $key=>$type){
            $id=$data[$key]??0;
            if(!is_int($id) || $id<0 || ($id && (get_post_type($id)!==$type || get_post_status($id)!=='publish'))) return self::error('La fiche choisie pour « '.$key.' » n’est plus disponible.');
            $meta['_sdr_'.$key]=$id;
        }
        if(!is_array($data['capacity']??null)) return self::error('Précise le nombre de places pour chaque rôle.');
        foreach(self::ROLES as $key=>$label){
            $value=$data['capacity'][$key]??null;
            if(!is_int($value) || $value<0 || $value>30) return self::error('Les places doivent être comprises entre 0 et 30.');
            $meta['_sdr_capacity_'.$key]=$value;
        }
        $url=trim($data['bookingUrl']??'');
        if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(wp_parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true))) return self::error('Indique un lien de billetterie commençant par https:// ou http://.');
        $meta['_sdr_booking_url']=esc_url_raw($url);
        if(!is_bool($data['closed']??false)) return self::error('L’état des inscriptions est invalide.');
        $meta['_sdr_closed']=!empty($data['closed']);
        if(!is_bool($data['intermission']??false)) return self::error('L’option d’entracte est invalide.');
        $meta['_sdr_intermission']=!empty($data['intermission']);
        return ['title'=>$title,'meta'=>$meta,'content'=>array_key_exists('description',$data)?wp_kses_post($data['description']):null];
    }
    static function create_event($req){
        if(!self::organizer() || !current_user_can('publish_sdr_events')) return self::error('Seule l’organisation peut créer un spectacle.',403);
        $data=$req->get_json_params();
        $fields=self::event_form_fields($data);
        if(is_wp_error($fields)) return $fields;
        if(isset($data['status']) && !is_string($data['status'])) return self::error('Choisis un statut valide.');
        $status=$data['status']??'publish';
        if(!in_array($status,['publish','draft'],true)) return self::error('Choisis un statut valide.');
        // Saving from the popup does not send a troupe announcement.
        remove_action('transition_post_status',[__CLASS__,'queue_new_event_email'],10);
        try {
            $id=wp_insert_post(wp_slash(['post_type'=>'sdr_event','post_status'=>$status,'post_author'=>get_current_user_id(),'post_title'=>$fields['title'],'post_content'=>$fields['content']??'','meta_input'=>$fields['meta']]),true);
        } finally {
            add_action('transition_post_status',[__CLASS__,'queue_new_event_email'],10,3);
        }
        if(is_wp_error($id)) return self::error('Impossible d’enregistrer le spectacle. Réessaie.',500);
        return ['id'=>$id,'status'=>$status,'event'=>self::event_data(get_post($id)),'editUrl'=>get_edit_post_link($id,'raw')];
    }
    static function event_edit_revision($post){
        $values=[$post->post_title,$post->post_content,$post->post_modified_gmt];
        foreach(['date','time','call_time','venue','team','format','booking_url','closed','intermission','capacity_joueur','capacity_mc','capacity_dj'] as $key) $values[]=self::meta($post->ID,$key);
        return hash('sha256',wp_json_encode($values));
    }
    static function edit_event_data($req){
        $id=(int)$req['id'];
        if(!self::editable_event($id) || !current_user_can('edit_post',$id)) return self::error('Spectacle introuvable.',404);
        $post=get_post($id);
        $hasBlocks=has_blocks($post);
        $result=['id'=>$id,'title'=>$post->post_title,'date'=>self::meta($id,'date'),'time'=>self::meta($id,'time'),'callTime'=>self::meta($id,'call_time'),'venue'=>(int)self::meta($id,'venue'),'team'=>(int)self::meta($id,'team'),'format'=>(int)self::meta($id,'format'),'capacity'=>[],'closed'=>(bool)self::meta($id,'closed',false),'intermission'=>(bool)self::meta($id,'intermission',false),'bookingUrl'=>self::meta($id,'booking_url'),'hasBlocks'=>$hasBlocks,'revision'=>self::event_edit_revision($post),'editUrl'=>get_edit_post_link($id,'raw')];
        if(!$hasBlocks) $result['description']=$post->post_content;
        foreach(self::ROLES as $key=>$label) $result['capacity'][$key]=(int)self::meta($id,'capacity_'.$key,0);
        $response=rest_ensure_response($result);
        $response->header('Cache-Control','no-store, private');
        return $response;
    }
    static function update_event($req){
        $id=(int)$req['id'];
        if(!self::editable_event($id) || !current_user_can('edit_post',$id)) return self::error('Spectacle introuvable.',404);
        return self::locked($id,function()use($req,$id){
            global $wpdb;
            $data=$req->get_json_params();
            $fields=self::event_form_fields($data);
            if(is_wp_error($fields)) return $fields;
            $post=get_post($id);
            if(!is_string($data['revision']??null) || $data['revision']!==self::event_edit_revision($post)) return self::error('Ce spectacle a changé depuis l’ouverture de la fenêtre. Ferme-la puis rouvre-la avant de réessayer.',409);
            if(has_blocks($post) && array_key_exists('description',$data)) return self::error('La présentation en blocs se modifie dans Gutenberg.',400);
            foreach(self::ROLES as $key=>$label){
                $assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role=%s",$id,$key));
                if($fields['meta']['_sdr_capacity_'.$key]<$assigned) return self::error('Retire des sélections avant de réduire les places de '.$label.'.');
            }
            $changes=['ID'=>$id,'post_title'=>$fields['title']];
            if($fields['content']!==null) $changes['post_content']=$fields['content'];
            $saved=wp_update_post(wp_slash($changes),true);
            if(is_wp_error($saved) || !$saved) return self::error('Impossible de modifier le spectacle. Réessaie.',500);
            foreach($fields['meta'] as $key=>$value) update_post_meta($id,$key,$value);
            delete_post_meta($id,'_sdr_team_finalized_hash');
            return ['saved'=>true,'event'=>self::event_data(get_post($id))];
        });
    }
    static function save_profile($req){
        $uid=get_current_user_id(); $data=$req->get_json_params();
        if(!is_array($data['roles']??null) || array_diff($data['roles'],array_keys(self::ROLES))) return self::error('Choisis des rôles valides.');
        if(!in_array($data['diet']??'', ['unspecified','standard','vegetarian','vegan'],true)) return self::error('Repas invalide.');
        $experience=$data['experience']??''; if($experience!=='' && (filter_var($experience,FILTER_VALIDATE_INT)===false || $experience<0 || $experience>80)) return self::error('Indique entre 0 et 80 années d’impro.');
        if(!is_string($data['description']??'') || mb_strlen($data['description']??'')>600) return self::error('La description ne peut pas dépasser 600 caractères.');
        foreach(['likes','dislikes'] as $key){ if(!is_array($data[$key]??null) || count($data[$key])>12) return self::error('Ajoute jusqu’à 12 éléments par liste.'); foreach($data[$key] as $item) if(!is_string($item) || mb_strlen($item)>60) return self::error('Chaque élément doit faire 60 caractères maximum.'); }
        $updated=wp_update_user(['ID'=>$uid,'description'=>sanitize_textarea_field($data['description'])]);
        if(is_wp_error($updated)) return self::error('La biographie du compte n’a pas pu être enregistrée. Réessaie.',500);
        delete_user_meta($uid,'sdr_profile_description');
        foreach(['roles'=>array_values(array_unique($data['roles'])),'diet'=>$data['diet'],'gender'=>sanitize_text_field(mb_substr($data['gender']??'',0,80)),'experience'=>$experience,'likes'=>self::profile_list($data['likes']),'dislikes'=>self::profile_list($data['dislikes']),'roster_visible'=>!empty($data['rosterVisible'])] as $k=>$v) update_user_meta($uid,'sdr_'.$k,$v);
        return self::profile($uid);
    }
    static function save_profile_photo($req){
        $files=$req->get_file_params(); $file=$files['photo']??null;
        if(!$file || !empty($file['error'])) return self::error('Choisis une photo avant de continuer.');
        if(($file['size']??0)>5*1024*1024) return self::error('La photo doit peser moins de 5 Mo.');
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $checked=wp_check_filetype_and_ext($file['tmp_name'],$file['name']);
        if(!in_array($checked['type']??'', ['image/jpeg','image/png','image/webp'],true)) return self::error('Utilise une image JPG, PNG ou WebP.');
        $upload=wp_handle_upload($file,['test_form'=>false,'mimes'=>['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']]);
        if(isset($upload['error'])) return self::error('La photo n’a pas pu être envoyée. Réessaie.');
        $attachment=wp_insert_attachment(['post_mime_type'=>$upload['type'],'post_title'=>sanitize_file_name(pathinfo($upload['file'],PATHINFO_FILENAME)),'post_status'=>'inherit'],$upload['file']);
        if(is_wp_error($attachment)){ @unlink($upload['file']); return self::error('La photo n’a pas pu être enregistrée. Réessaie.',500); }
        wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
        $old=(int)get_user_meta(get_current_user_id(),'sdr_profile_photo',true);
        update_user_meta(get_current_user_id(),'sdr_profile_photo',$attachment);
        if($old && $old!==$attachment) wp_delete_attachment($old,true);
        return self::profile(get_current_user_id());
    }
    static function save_password($req){
        $uid=get_current_user_id(); $user=get_userdata($uid); $data=$req->get_json_params();
        $current=$data['currentPassword']??''; $new=$data['newPassword']??'';
        if(!is_string($current)||!is_string($new)) return self::error('Informations de mot de passe invalides.');
        $attempts=(int)get_transient('sdr_password_attempts_'.$uid);
        if($attempts>=5) return self::error('Trop d’essais. Attends quinze minutes avant de recommencer.',429);
        if(!wp_check_password($current,$user->user_pass,$uid)){
            set_transient('sdr_password_attempts_'.$uid,$attempts+1,15*MINUTE_IN_SECONDS);
            return self::error('Le mot de passe actuel est incorrect.',403);
        }
        if(strlen($new)>4096) return self::error('Le nouveau mot de passe est trop long.');
        if(mb_strlen($new)<12) return self::error('Choisis au moins 12 caractères.');
        if(wp_check_password($new,$user->user_pass,$uid)) return self::error('Choisis un mot de passe différent de l’actuel.');
        $lower=mb_strtolower($new);
        foreach(array_filter([$user->user_login,$user->user_email,$user->display_name]) as $personal){
            if(mb_strlen($personal)>=4 && str_contains($lower,mb_strtolower($personal))) return self::error('Évite d’utiliser ton identifiant, ton nom ou ton adresse email dans le mot de passe.');
        }
        delete_transient('sdr_password_attempts_'.$uid);
        wp_set_password($new,$uid);
        // wp_set_password invalidates every session. Create one fresh session for this browser only.
        $expiration=time()+14*DAY_IN_SECONDS;
        $token=WP_Session_Tokens::get_instance($uid)->create($expiration);
        wp_set_auth_cookie($uid,true,is_ssl(),$token);
        $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($uid,$expiration,'logged_in',$token);
        wp_set_current_user($uid);
        return ['saved'=>true,'nonce'=>wp_create_nonce('wp_rest')];
    }
    static function events($upcoming=true){
        return get_posts(['post_type'=>'sdr_event','post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_sdr_date','orderby'=>'meta_value','order'=>'ASC','meta_query'=>$upcoming ? [['key'=>'_sdr_date','value'=>current_time('Y-m-d'),'compare'=>'>=','type'=>'DATE']] : []]);
    }
    static function event_data($p){
        $id=$p->ID; $venue=(int)self::meta($id,'venue'); $format=(int)self::meta($id,'format'); $team=(int)self::meta($id,'team');
        $d=['id'=>$id,'title'=>$p->post_title,'date'=>self::meta($id,'date'),'time'=>self::meta($id,'time'),'callTime'=>self::meta($id,'call_time'),'venue'=>$venue?get_post_field('post_title',$venue):'Lieu à préciser','city'=>$venue?self::meta($venue,'city'):'','address'=>$venue?self::meta($venue,'address'):'','venueUrl'=>$venue?get_permalink($venue):'','format'=>$format?get_post_field('post_title',$format):'Format à inventer','formatUrl'=>$format?get_permalink($format):'','team'=>$team?get_post_field('post_title',$team):'','teamUrl'=>$team?get_permalink($team):'','closed'=>(bool)self::meta($id,'closed',false),'intermission'=>(bool)self::meta($id,'intermission',false),'intermissionSet'=>metadata_exists('post',$id,'_sdr_intermission'),'url'=>get_permalink($id),'capacity'=>[]];
        foreach(self::ROLES as $k=>$v) $d['capacity'][$k]=(int)self::meta($id,'capacity_'.$k,0);
        return $d;
    }
    static function email_available($user){
        $email=strtolower(trim($user->user_email??''));
        return $email!=='' && is_email($email) && !str_ends_with($email,'.invalid') && !str_ends_with($email,'.test');
    }
    static function email_name($user){ return trim($user->first_name) ?: $user->display_name; }
    static function member_email_recipients(){
        $recipients=[];
        foreach(get_users(['orderby'=>'display_name','order'=>'ASC']) as $user){
            $memberRole=(bool)array_intersect($user->roles,['sdr_member','sdr_organizer']);
            $adminMember=in_array('administrator',$user->roles,true) && (bool)get_user_meta($user->ID,'sdr_roles',true);
            if((!$memberRole && !$adminMember) || get_user_meta($user->ID,'sdr_demo',true) || !self::email_available($user)) continue;
            $recipients[$user->ID]=$user;
        }
        return $recipients;
    }
    static function event_email_details($event){
        $date=self::meta($event->ID,'date');
        $stamp=$date && self::valid_date($date) ? (new DateTimeImmutable($date.' 12:00:00',wp_timezone()))->getTimestamp() : 0;
        $data=self::event_data($event);
        return [
            'date'=>$stamp?wp_date('l j F Y',$stamp):'Date à préciser',
            'title'=>$event->post_title,
            'venue'=>$data['venue'],
            'time'=>$data['time']?str_replace(':','h',$data['time']):'à préciser',
            'callTime'=>$data['callTime']?str_replace(':','h',$data['callTime']):'à préciser',
            'team'=>$data['team'],
            'url'=>home_url('/coulisses/'),
        ];
    }
    static function send_plain_email($user,$subject,$lines){
        $body='Bonjour '.self::email_name($user).",\n\n".implode("\n",$lines)."\n\nÀ bientôt,\nLes Sorties de Route";
        return wp_mail($user->user_email,$subject,$body,['Content-Type: text/plain; charset=UTF-8']);
    }
    static function queue_new_event_email($newStatus,$oldStatus,$post){
        if($post->post_type!=='sdr_event' || $newStatus!=='publish' || $oldStatus==='publish') return;
        update_post_meta($post->ID,'_sdr_date_announcement_pending',1);
        if(!wp_next_scheduled('sdr_notify_new_event',[$post->ID])) wp_schedule_single_event(time()+60,'sdr_notify_new_event',[$post->ID]);
    }
    static function send_pending_event_email($post,$request,$creating){
        if($post->post_status==='publish' && get_post_meta($post->ID,'_sdr_date_announcement_pending',true)) self::notify_new_event($post->ID);
    }
    static function notify_new_event($eventId){
        $event=get_post($eventId);
        if(!$event || $event->post_type!=='sdr_event' || $event->post_status!=='publish' || !get_post_meta($eventId,'_sdr_date_announcement_pending',true)) return ['sent'=>0,'failed'=>0];
        $details=self::event_email_details($event); $sentIds=array_map('intval',(array)get_post_meta($eventId,'_sdr_date_announcement_sent_users',true)); $sent=0; $failed=0;
        foreach(self::member_email_recipients() as $user){
            if(in_array($user->ID,$sentIds,true)) continue;
            $subject='Nouvelle date : '.$details['date'].' — Les Sorties de Route';
            $lines=['Une nouvelle date vient d’être ajoutée au planning.','','Spectacle : '.$details['title'],'Date : '.$details['date'],'Lieu : '.$details['venue'],'Début du spectacle : '.$details['time'],'Rendez-vous de la troupe : '.$details['callTime']];
            if($details['team']) $lines[]='Troupe invitée : '.$details['team'];
            $lines[]=''; $lines[]='Indique ta disponibilité dans les Coulisses : '.$details['url'];
            if(self::send_plain_email($user,$subject,$lines)){ $sentIds[]=$user->ID; $sent++; update_post_meta($eventId,'_sdr_date_announcement_sent_users',array_values(array_unique($sentIds))); }
            else $failed++;
        }
        update_post_meta($eventId,'_sdr_date_announcement_last_attempt',current_time('mysql'));
        if($failed===0){ delete_post_meta($eventId,'_sdr_date_announcement_pending'); update_post_meta($eventId,'_sdr_date_announcement_sent',current_time('mysql')); wp_clear_scheduled_hook('sdr_notify_new_event',[$eventId]); }
        return ['sent'=>$sent,'failed'=>$failed];
    }
    static function season($today){
        // August keeps the completed season visible until September's rollover.
        $year=(int)substr($today,0,4)-((int)substr($today,5,2)<9?1:0);
        return ['start'=>$year.'-09-01','end'=>($year+1).'-07-31','label'=>$year.' — '.($year+1)];
    }
    static function season_stats($season,$today){
        if(!self::organizer()) return null;
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT r.event_id,r.user_id,r.availability,r.roles,r.selected_role,d.meta_value AS event_date
             FROM {$wpdb->prefix}sdr_responses r
             INNER JOIN {$wpdb->posts} p ON p.ID=r.event_id AND p.post_type='sdr_event' AND p.post_status='publish'
             INNER JOIN {$wpdb->postmeta} d ON d.post_id=p.ID AND d.meta_key='_sdr_date'
             WHERE d.meta_value BETWEEN %s AND %s",
            $season['start'],$season['end']
        ),ARRAY_A);
        $players=[];
        $users=get_users(['role__in'=>['sdr_member','sdr_organizer','administrator'],'orderby'=>'display_name']);
        foreach($users as $user){
            if(get_user_meta($user->ID,'sdr_demo',true)) continue;
            $roles=get_user_meta($user->ID,'sdr_roles',true);
            // Administrative accounts without a troupe profile are not players.
            if(!$roles && in_array('administrator',$user->roles,true)) continue;
            if(!in_array('joueur',is_array($roles)?$roles:['joueur'],true)) continue;
            $players[$user->ID]=['id'=>(int)$user->ID,'name'=>$user->display_name,'proposed'=>0,'played'=>0];
        }
        foreach($rows as $row){
            $roles=json_decode($row['roles'],true);
            $proposed=in_array($row['availability'],['yes','maybe'],true) && is_array($roles) && in_array('joueur',$roles,true);
            $played=$row['selected_role']==='joueur' && $row['event_date']<$today;
            if(!$proposed && !$played) continue;
            $uid=(int)$row['user_id'];
            if(!isset($players[$uid])){
                $user=get_userdata($uid);
                if(!$user || get_user_meta($uid,'sdr_demo',true)) continue;
                $players[$uid]=['id'=>$uid,'name'=>$user->display_name,'proposed'=>0,'played'=>0];
            }
            if($proposed) $players[$uid]['proposed']++;
            if($played) $players[$uid]['played']++;
        }
        return ['players'=>array_values($players)];
    }
    static function dashboard(){
        global $wpdb; $uid=get_current_user_id(); $events=[];
        foreach(self::events() as $p){ $e=self::event_data($p); $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d",$p->ID),ARRAY_A); $e['responses']=[]; $e['mine']=null;
            foreach($rows as $r){ $u=get_userdata($r['user_id']); if(!$u) continue; $item=['userId'=>(int)$r['user_id'],'name'=>$u->display_name,'availability'=>$r['availability'],'roles'=>json_decode($r['roles'],true)?:[],'selectedRole'=>$r['selected_role']]; if((int)$r['user_id']===$uid) $e['mine']=$item; if(self::organizer()) $item['diet']=get_user_meta($r['user_id'],'sdr_diet',true)?:'unspecified'; $e['responses'][]=$item; } $events[]=$e;
        }
        $result=['user'=>array_merge(['id'=>$uid],self::profile($uid)),'organizer'=>self::organizer(),'administrator'=>current_user_can('manage_options'),'events'=>$events,'facebookReady'=>class_exists('NextendSocialLogin') && isset(NextendSocialLogin::$enabledProviders['facebook'])];
        if(self::organizer()) $result['members']=array_values(array_map(fn($u)=>['id'=>$u->ID,'name'=>$u->display_name],array_filter(get_users(['role__in'=>['sdr_member','sdr_organizer'],'orderby'=>'display_name']),fn($u)=>!get_user_meta($u->ID,'sdr_demo',true))));
        $today=current_time('Y-m-d');
        $result['season']=self::season($today);
        if(self::organizer()) $result['seasonStats']=self::season_stats($result['season'],$today);
        $res=rest_ensure_response($result); $res->header('Cache-Control','no-store, private'); return $res;
    }
    static function archives(){
        global $wpdb;
        $today=current_time('Y-m-d');$uid=get_current_user_id();$events=[];$seasons=[];
        $posts=get_posts(['post_type'=>'sdr_event','post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_sdr_date','orderby'=>['meta_value'=>'DESC','ID'=>'DESC'],'meta_query'=>[['key'=>'_sdr_date','value'=>$today,'compare'=>'<','type'=>'DATE']]]);
        $responses=[];
        if($posts){
            $ids=implode(',',array_map(fn($post)=>(int)$post->ID,$posts));
            foreach($wpdb->get_results("SELECT event_id,user_id,availability,roles,selected_role FROM {$wpdb->prefix}sdr_responses WHERE event_id IN ($ids)",ARRAY_A) as $row) $responses[$row['event_id']][]=$row;
        }
        foreach($posts as $post){
            $event=self::event_data($post);
            if(!self::valid_date($event['date'])) continue;
            $season=self::season($event['date']);
            // Keep August dates accessible without including them in a September–July season.
            $summer=substr($event['date'],5,2)==='08';
            $key=$summer?'summer-'.substr($event['date'],0,4):$season['start'];
            $seasons[$key]=['id'=>$key,'label'=>$summer?'Août '.substr($event['date'],0,4).' · hors saison':$season['label']];
            $event['season']=$key;$event['responses']=[];$event['mine']=null;
            foreach($responses[$post->ID]??[] as $row){
                $user=get_userdata($row['user_id']);if(!$user) continue;
                $item=['userId'=>(int)$user->ID,'name'=>$user->display_name,'availability'=>$row['availability'],'roles'=>json_decode($row['roles'],true)?:[],'selectedRole'=>$row['selected_role']];
                if((int)$user->ID===$uid) $event['mine']=$item;
                if(self::organizer() || $item['selectedRole'] || (int)$user->ID===$uid) $event['responses'][]=$item;
            }
            $events[]=$event;
        }
        $res=rest_ensure_response(['events'=>$events,'seasons'=>array_values($seasons)]);
        $res->header('Cache-Control','no-store, private');return $res;
    }
    static function editable_event($id){ return get_post_type($id)==='sdr_event' && get_post_status($id)==='publish' && self::meta($id,'date')>=current_time('Y-m-d'); }
    static function locked($id,$fn){ global $wpdb; $key='sdr_event_'.$id; if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$key))!==1) return self::error('Une mise à jour est en cours. Réessaie.',409); try{return $fn();} finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));} }
    static function respond($req){
        $id=(int)$req['id']; if(!self::editable_event($id)) return self::error('Ce spectacle n’est pas ouvert aux réponses.',404);
        return self::locked($id,function()use($req,$id){
            if(self::meta($id,'closed',false)) return self::error('Les réponses sont fermées. Contacte l’organisation.',409);
            global $wpdb; $uid=get_current_user_id(); $d=$req->get_json_params(); $status=$d['availability']??''; $roles=$d['roles']??[];
            if(!in_array($status,['yes','maybe','no','clear'],true) || !is_array($roles) || array_diff($roles,array_keys(self::ROLES))) return self::error('Réponse invalide.');
            if($status==='clear'){ $wpdb->delete($wpdb->prefix.'sdr_responses',['event_id'=>$id,'user_id'=>$uid]); return ['saved'=>true]; }
            $roles=$status==='no'?[]:array_values(array_unique($roles));
            if($status!=='no' && !$roles) return self::error('Choisis au moins un rôle.');
            if(array_diff($roles,self::profile($uid)['roles'])) return self::error('Ajoute d’abord ce rôle à ton profil.');
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND user_id=%d",$id,$uid));
            $selected=$old && in_array($old->selected_role,$roles,true)?$old->selected_role:'';
            $ok=$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}sdr_responses (event_id,user_id,availability,roles,selected_role,updated_at) VALUES (%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE availability=VALUES(availability),roles=VALUES(roles),selected_role=VALUES(selected_role),updated_at=VALUES(updated_at)",$id,$uid,$status,wp_json_encode($roles),$selected,current_time('mysql')));
            return $ok===false?self::error('La réponse n’a pas pu être enregistrée.',500):['saved'=>true];
        });
    }
    static function select($req){
        $id=(int)$req['id']; if(!self::editable_event($id)) return self::error('Spectacle introuvable.',404);
        return self::locked($id,function()use($req,$id){global $wpdb; $d=$req->get_json_params(); $uid=absint($d['userId']??0); $role=$d['role']??'';
            if($role!=='' && !isset(self::ROLES[$role])) return self::error('Rôle invalide.');
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND user_id=%d",$id,$uid));
            if(!$row) return self::error('Ce membre n’a pas répondu.');
            if($role!=='' && (!in_array($row->availability,['yes','maybe'],true) || !in_array($role,json_decode($row->roles,true)?:[],true))) return self::error('Ce membre ne s’est pas proposé pour ce rôle.');
            $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role=%s AND user_id<>%d",$id,$role,$uid));
            if($role!=='' && $count>=(int)self::meta($id,'capacity_'.$role,0)) return self::error('Toutes les places de ce rôle sont attribuées.',409);
            $wpdb->update($wpdb->prefix.'sdr_responses',['selected_role'=>$role,'updated_at'=>current_time('mysql')],['id'=>$row->id]);
            delete_post_meta($id,'_sdr_team_finalized_hash');
            return ['saved'=>true];
        });
    }
    static function finalize_team($req){
        $id=(int)$req['id']; if(!self::editable_event($id)) return self::error('Spectacle introuvable.',404);
        return self::locked($id,function()use($req,$id){
            global $wpdb; $data=$req->get_json_params();
            if(!array_key_exists('notify',$data) || !is_bool($data['notify'])) return self::error('Choisis si les joueurs doivent être informés.');
            $rows=$wpdb->get_results($wpdb->prepare("SELECT user_id,selected_role FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role<>'' ORDER BY user_id ASC",$id),ARRAY_A);
            if(!$rows) return self::error('Sélectionne au moins une personne avant de finaliser l’équipe.');
            $event=get_post($id); $details=self::event_email_details($event);
            $signature=hash('sha256',wp_json_encode(['date'=>$details['date'],'venue'=>$details['venue'],'time'=>$details['time'],'call'=>$details['callTime'],'team'=>$details['team'],'selected'=>$rows]));
            update_post_meta($id,'_sdr_team_finalized_hash',$signature);
            update_post_meta($id,'_sdr_team_finalized_at',current_time('mysql'));
            update_post_meta($id,'_sdr_team_finalized_by',get_current_user_id());
            if(!$data['notify']) return ['saved'=>true,'notified'=>0,'failed'=>0,'skipped'=>0,'silent'=>true];
            $wasNotified=hash_equals((string)get_post_meta($id,'_sdr_team_notified_hash',true),$signature);
            $state=(array)get_post_meta($id,'_sdr_team_notification_state',true);
            $sentIds=($state['hash']??'')===$signature?array_map('intval',(array)($state['sent']??[])):[];
            $notified=0; $failed=0; $skipped=[];
            foreach($rows as $row){
                $user=get_userdata((int)$row['user_id']); if(!$user) continue;
                if(!self::email_available($user)){ $skipped[]=$user->display_name; continue; }
                if(in_array($user->ID,$sentIds,true)) continue;
                $role=self::ROLES[$row['selected_role']]??$row['selected_role'];
                $subject='Équipe finalisée : '.$details['date'].' — Les Sorties de Route';
                $lines=['L’équipe de cette soirée est finalisée : tu es retenu·e en tant que '.$role.'.','','Spectacle : '.$details['title'],'Date : '.$details['date'],'Lieu : '.$details['venue'],'Rendez-vous de la troupe : '.$details['callTime'],'Début du spectacle : '.$details['time']];
                if($details['team']) $lines[]='Troupe invitée : '.$details['team'];
                $lines[]=''; $lines[]='Retrouve la date dans les Coulisses : '.$details['url'];
                if(self::send_plain_email($user,$subject,$lines)){ $sentIds[]=$user->ID; $notified++; update_post_meta($id,'_sdr_team_notification_state',['hash'=>$signature,'sent'=>array_values(array_unique($sentIds))]); }
                else $failed++;
            }
            if($failed===0) update_post_meta($id,'_sdr_team_notified_hash',$signature);
            update_post_meta($id,'_sdr_team_notified_at',current_time('mysql'));
            if($wasNotified && $notified===0 && $failed===0) return ['saved'=>true,'notified'=>0,'failed'=>0,'skipped'=>count($skipped),'skippedNames'=>$skipped,'alreadyNotified'=>true];
            return ['saved'=>true,'notified'=>$notified,'failed'=>$failed,'skipped'=>count($skipped),'skippedNames'=>$skipped];
        });
    }
    static function event_settings($req){
        $id=(int)$req['id']; if(!self::editable_event($id)) return self::error('Spectacle introuvable.',404);
        return self::locked($id,function()use($req,$id){global $wpdb; $d=$req->get_json_params(); $caps=[];
            foreach(self::ROLES as $k=>$v){$n=$d['capacity'][$k]??null; if(!is_int($n)||$n<0||$n>30) return self::error('Les places doivent être comprises entre 0 et 30.'); $assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sdr_responses WHERE event_id=%d AND selected_role=%s",$id,$k)); if($n<$assigned) return self::error('Retire des sélections avant de réduire les places.'); $caps[$k]=$n;}
            foreach($caps as $k=>$v) update_post_meta($id,'_sdr_capacity_'.$k,$v); update_post_meta($id,'_sdr_closed',!empty($d['closed'])?1:0); delete_post_meta($id,'_sdr_team_finalized_hash'); return ['saved'=>true];
        });
    }
    static function agenda(){
        ob_start(); echo '<div class="public-agenda">'; $events=self::events();
        foreach($events as $i=>$p){ $e=self::event_data($p); $date=new DateTime($e['date']); $format=$e['format']; $detail=$e['venue'].' · '.($e['time']?str_replace(':','h',$e['time']):'Horaire à venir');if($e['intermissionSet'])$detail.=$e['intermission']?' · Avec entracte':' · Sans entracte';$title=$e['team']?"Sortie d'route VS ".$e['team']:$p->post_title;echo '<a class="public-event" href="'.esc_url($e['url']).'"><div class="date-stamp"><span>'.esc_html(wp_date('M',$date->getTimestamp())).'</span><strong>'.esc_html($date->format('d')).'</strong><small>'.esc_html($date->format('Y')).'</small></div><div class="event-line-main"><span class="tag tag-'.($i%3).'">'.esc_html($format).'</span><h3>'.self::event_title($title).'</h3><p>'.esc_html($detail).'</p></div><span class="round-arrow" aria-label="Voir le spectacle">↗</span></a>'; }
        if(!$events) echo '<p>Les prochaines dates arrivent bientôt. On vous prépare de belles surprises.</p>';
        echo '</div>'; return ob_get_clean();
    }
    static function roster($attributes=[]){
        $attributes=wp_parse_args($attributes,['title'=>'La troupe au grand complet','layout'=>'cards','columns'=>3,'showDescription'=>true,'showLikes'=>true,'showDislikes'=>true,'includeOrganizers'=>true,'order'=>'name','excludedUserIds'=>[]]);
        $users=self::roster_users(!empty($attributes['includeOrganizers']));
        $excluded=array_fill_keys(array_map('absint',(array)$attributes['excludedUserIds']),true);
        $users=array_values(array_filter($users,static fn($user)=>empty($excluded[$user->ID])));
        if($attributes['order']==='random') shuffle($users);
        $layout=in_array($attributes['layout'],['cards','polaroid'],true)?$attributes['layout']:'cards';
        $columns=max(2,min(5,absint($attributes['columns'])));
        ob_start();
        echo '<section class="sdr-roster sdr-roster--'.esc_attr($layout).'" style="--sdr-roster-columns:'.$columns.'">';
        if(trim((string)$attributes['title'])!=='') echo '<h2 class="sdr-roster-title">'.esc_html($attributes['title']).'</h2>';
        if(!$users){echo '<p class="sdr-roster-empty">Les portraits se préparent en coulisses…</p></section>';return ob_get_clean();}
        $doodles=['✳','〰','☀','♫','↗','♡','♧','✦'];
        echo '<div class="sdr-roster-grid">';
        foreach($users as $index=>$user){
            $profile=self::profile($user->ID); $photo=$profile['photoId']?wp_get_attachment_image($profile['photoId'],'medium',false,['class'=>'sdr-roster-photo','loading'=>'lazy','alt'=>'Portrait de '.$user->display_name]):'<span class="sdr-roster-initial" aria-hidden="true">'.esc_html(mb_substr($user->display_name,0,1)).'</span>';
            $labels=array_map(static fn($key)=>self::ROLES[$key]??'',(array)$profile['roles']);$labels=array_values(array_filter($labels));
            $isOrganizer=in_array('sdr_organizer',$user->roles,true)||user_can($user,'sdr_organize');
            echo '<article class="sdr-roster-card"><div class="sdr-roster-image">'.$photo.'<span class="sdr-roster-doodle" aria-hidden="true">'.esc_html($doodles[$index%count($doodles)]).'</span></div><div class="sdr-roster-copy"><div class="sdr-roster-kicker">'.($isOrganizer?'ORGANISATION':'DANS LA TROUPE').'</div><h3>'.esc_html($user->display_name).'</h3>';
            if($labels) echo '<p class="sdr-roster-roles">'.esc_html(implode(' · ',$labels)).'</p>';
            if(!empty($attributes['showDescription']) && $profile['description']!=='') echo '<p class="sdr-roster-description">'.nl2br(esc_html($profile['description'])).'</p>';
            if(!empty($attributes['showLikes']) && $profile['likes']) echo '<div class="sdr-roster-tags sdr-roster-likes"><b>J’aime</b>'.esc_html(implode(' · ',$profile['likes'])).'</div>';
            if(!empty($attributes['showDislikes']) && $profile['dislikes']) echo '<div class="sdr-roster-tags sdr-roster-dislikes"><b>J’aime pas</b>'.esc_html(implode(' · ',$profile['dislikes'])).'</div>';
            echo '</div></article>';
        }
        echo '</div></section>'; return ob_get_clean();
    }
    static function roster_users($includeOrganizers=true){
        $roles=$includeOrganizers?['sdr_member','sdr_organizer']:['sdr_member'];
        $users=get_users(['role__in'=>$roles,'orderby'=>'display_name','order'=>'ASC','number'=>500]);
        if($includeOrganizers) foreach(get_users(['role'=>'administrator','orderby'=>'display_name','order'=>'ASC','number'=>200]) as $admin) if(get_user_meta($admin->ID,'sdr_roles',true)) $users[]=$admin;
        $unique=[];
        foreach($users as $user) if(!isset($unique[$user->ID]) && !get_user_meta($user->ID,'sdr_demo',true) && get_user_meta($user->ID,'sdr_roster_visible',true)) $unique[$user->ID]=$user;
        uasort($unique,static fn($a,$b)=>strnatcasecmp($a->display_name,$b->display_name));
        return array_values($unique);
    }
    static function roster_member_options(){
        return array_map(static fn($user)=>['id'=>(int)$user->ID,'name'=>$user->display_name,'organizer'=>in_array('sdr_organizer',$user->roles,true)||user_can($user,'sdr_organize')],self::roster_users(true));
    }
    static function app(){
        if(!self::member()) return '<div class="login-callout"><h2>Bienvenue dans les coulisses.</h2><p>Cet espace est réservé aux membres de la troupe.</p><a class="button primary" href="'.esc_url(wp_login_url(home_url('/coulisses/'))).'">Se connecter →</a></div>';
        wp_enqueue_script('sdr-app',plugins_url('assets/app.js',__FILE__),[], '1.4.7',true);
        wp_add_inline_script('sdr-app','window.SDR='.wp_json_encode(['api'=>rest_url('sdr/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'home'=>home_url('/'),'admin'=>admin_url(),'adminLogin'=>add_query_arg('sdr_admin','1',wp_login_url(admin_url(),true)),'logout'=>html_entity_decode(wp_logout_url(home_url('/')),ENT_QUOTES,'UTF-8')]).';','before');
        return '<div id="sdr-app"><div class="app-loading"><span class="loading-star">✳</span><p>On ouvre les coulisses…</p></div></div><noscript>Active JavaScript pour gérer tes disponibilités.</noscript>';
    }
    static function facebook(){
        if(!self::member()) return '<p>Connecte-toi d’abord à ton compte membre.</p>';
        if(!class_exists('NextendSocialLogin') || !isset(NextendSocialLogin::$enabledProviders['facebook'])) return '<p>Facebook n’est pas encore activé. La connexion WordPress reste disponible.</p><p>L’organisation doit renseigner l’application Meta dans Réglages → Nextend Social Login.</p>';
        return '<p>Associe ton compte Facebook à ton compte de la troupe. Tu pourras ensuite utiliser le bouton Facebook sur la page de connexion.</p>'.do_shortcode('[nextend_social_login provider="facebook" link="1" unlink="1"]');
    }
    static function import_report(){
        if(!current_user_can('manage_options'))return; $counts=get_option('sdr_import_completed',[]);echo '<div class="wrap"><h1>Import du tableur SDR</h1><p>Source lue le 9 octobre 2026. Le tableur original n’a pas été modifié.</p><h2>Éléments importés</h2><pre>'.esc_html(wp_json_encode($counts,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><h2>Points à vérifier</h2><ul>';foreach(get_option('sdr_import_issues',[]) as $issue)echo '<li>• '.esc_html($issue).'</li>';echo '</ul><h2>Anciennes réponses (archive privée)</h2><p>Les réponses nommées ont été reprises sur les dates correspondantes de 2026–2027 à ta demande. Les lignes sans prénom sont conservées ici sans attribution.</p><div style="overflow:auto"><table class="widefat striped">';$archive=get_option('sdr_source_archive',[]);foreach($archive['rows']??[] as $i=>$row){echo '<tr><th>'.($i+1).'</th>';for($c=0;$c<51;$c++)echo '<td>'.esc_html($row[$c]??'').'</td>';echo '</tr>';}echo '</table></div></div>';
    }
}
require_once __DIR__.'/editor.php';
SDR_Coulisses::boot();
register_activation_hook(__FILE__,['SDR_Coulisses','activate']);
