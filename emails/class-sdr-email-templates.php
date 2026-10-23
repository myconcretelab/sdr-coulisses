<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/context.php';
require_once __DIR__.'/renderer.php';
require_once __DIR__.'/defaults.php';

final class SDR_Email_Templates {
    const VERSION='1.0.0';
    const TYPE='sdr_email_template';
    const BASE='sdr-email-templates';
    const ACTIVE='sdr_email_active_templates';
    static function kinds() { return ['announcement'=>'Annonce d’un spectacle','reminder'=>'Relance ciblée','team'=>'Confirmation d’équipe','message'=>'Messages libres']; }
    static function scenarios() { return ['missing_mc'=>'Il manque un MC','multiple'=>'Plusieurs rôles manquants','complete'=>'Équipe complète','no_capacity'=>'Besoins non définis','missing_details'=>'Informations pratiques absentes','closed'=>'Réponses fermées','no_roles'=>'Profil sans rôle','optout'=>'Profil non volontaire']; }
    static function allowed() { return current_user_can('manage_options'); }
    static function boot() {
        add_action('init',[__CLASS__,'register'],15);
        add_action('init',[__CLASS__,'install'],25);
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_filter('rest_pre_dispatch',[__CLASS__,'private_rest'],5,3);
        add_filter('rest_pre_insert_'.self::TYPE,[__CLASS__,'validate_save'],10,2);
        add_action('enqueue_block_editor_assets',[__CLASS__,'assets']);
        add_filter('allowed_block_types_all',static function($allowed,$ctx){return ($ctx->post->post_type??'')===self::TYPE?array_map(static fn($name)=>SDR_Email_Renderer::PREFIX.$name,array_keys(SDR_Email_Renderer::schemas())):$allowed;},10,2);
        add_action('admin_menu',static function(){add_submenu_page('edit.php?post_type='.self::TYPE,'Modèles utilisés','Modèles utilisés','manage_options','sdr-email-settings',[__CLASS__,'settings_page']);});
        add_action('admin_post_sdr_email_activate',[__CLASS__,'activate_form']);
        add_filter('manage_'.self::TYPE.'_posts_columns',static function($columns){$columns['sdr_email_kind']='Usage';$columns['sdr_email_active']='Utilisé pour les envois';return $columns;});
        add_action('manage_'.self::TYPE.'_posts_custom_column',static function($column,$id){if($column==='sdr_email_kind')echo esc_html(self::kinds()[get_post_meta($id,'_sdr_email_kind',true)]??'À préciser');if($column==='sdr_email_active')echo in_array($id,array_map('intval',(array)get_option(self::ACTIVE,[])),true)?'✓ Oui':'—';},10,2);
        add_filter('post_row_actions',static function($actions,$post){if($post->post_type===self::TYPE){unset($actions['view']);$actions['sdr_email_settings']='<a href="'.esc_url(admin_url('edit.php?post_type='.self::TYPE.'&page=sdr-email-settings')).'">Modèles utilisés</a>';}return $actions;},10,2);
    }
    static function register() {
        register_post_type(self::TYPE,['labels'=>['name'=>'Modèles d’emails','singular_name'=>'Modèle d’email','add_new_item'=>'Créer un modèle d’email','edit_item'=>'Modifier le modèle d’email'],'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_ui'=>true,'show_in_rest'=>true,'rest_base'=>self::BASE,'show_in_nav_menus'=>false,'rewrite'=>false,'query_var'=>false,'menu_icon'=>'dashicons-email-alt','supports'=>['title','editor','revisions','custom-fields'],'map_meta_cap'=>false,'capabilities'=>array_fill_keys(['edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts','delete_posts','delete_private_posts','delete_published_posts','delete_others_posts','edit_private_posts','edit_published_posts','create_posts'],'manage_options'),'template'=>[[SDR_Email_Renderer::PREFIX.'text',['content'=>'Bonjour {{prenom}},']]]]);
        foreach(['_sdr_email_kind'=>['type'=>'string','default'=>'reminder'],'_sdr_email_subject'=>['type'=>'string','default'=>'On joue ensemble ? {{spectacle|Prochaine soirée}}'],'_sdr_email_preheader'=>['type'=>'string','default'=>''],'_sdr_email_style'=>['type'=>'object','default'=>['background'=>'#eee9de','surface'=>'#fffdf6','color'=>'#302e28','font'=>'Arial','width'=>600,'padding'=>24]]] as $key=>$schema){
            $rest=$schema;if($schema['type']==='object')$rest['properties']=['background'=>['type'=>'string'],'surface'=>['type'=>'string'],'color'=>['type'=>'string'],'font'=>['type'=>'string'],'width'=>['type'=>'number'],'padding'=>['type'=>'number']];
            if($key==='_sdr_email_kind')$rest['enum']=array_keys(self::kinds());
            register_post_meta(self::TYPE,$key,array_merge($schema,['single'=>true,'revisions_enabled'=>true,'show_in_rest'=>['schema'=>$rest],'auth_callback'=>[__CLASS__,'allowed']]));
        }
        foreach(SDR_Email_Renderer::schemas() as $name=>$attrs)register_block_type(SDR_Email_Renderer::PREFIX.$name,['api_version'=>3,'attributes'=>$attrs,'render_callback'=>static fn()=>'<p>Ce bloc est réservé aux modèles d’emails.</p>']);
    }
    /** Idempotent seeding, with a database lock; publishing templates never sends emails. */
    static function install() {
        if(get_option('sdr_email_templates_version')===self::VERSION)return;
        global $wpdb;$lock='sdr_email_templates_install';
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return;
        try{
            if(get_option('sdr_email_templates_version')===self::VERSION)return;
            $active=(array)get_option(self::ACTIVE,[]);
            foreach(self::kinds() as $kind=>$label){
                $existing=get_posts(['post_type'=>self::TYPE,'post_status'=>['publish','draft','private'],'numberposts'=>1,'meta_key'=>'_sdr_email_seed','meta_value'=>$kind]);
                if($existing)$id=$existing[0]->ID;
                else{
                    $s=SDR_Email_Defaults::snapshot($kind);
                    $id=wp_insert_post(wp_slash(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>$label.' — SDR','post_content'=>$s['content'],'meta_input'=>['_sdr_email_seed'=>$kind,'_sdr_email_kind'=>$kind,'_sdr_email_subject'=>$s['subject'],'_sdr_email_preheader'=>$s['preheader'],'_sdr_email_style'=>$s['style']]]),true);
                    if(is_wp_error($id)||!$id)return;
                }
                if(empty($active[$kind]))$active[$kind]=(int)$id;
            }
            update_option(self::ACTIVE,$active,false);update_option('sdr_email_templates_version',self::VERSION,false);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    static function private_rest($result,$server,$request) {
        if(preg_match('#^/wp/v2/'.self::BASE.'(?:/|$)#',$request->get_route())&&!self::allowed())return new WP_Error('sdr_email_private','Les modèles d’emails sont réservés aux administrateurs.',['status'=>is_user_logged_in()?403:401]);
        return $result;
    }
    static function snapshot_post($post,$override=[]) {
        $id=$post->ID;
        $s=['id'=>$id,'kind'=>get_post_meta($id,'_sdr_email_kind',true)?:'reminder','content'=>$post->post_content,'subject'=>get_post_meta($id,'_sdr_email_subject',true),'preheader'=>get_post_meta($id,'_sdr_email_preheader',true),'style'=>get_post_meta($id,'_sdr_email_style',true)];
        return array_merge($s,$override);
    }
    static function snapshot($kind) {
        $id=(int)(((array)get_option(self::ACTIVE,[]))[$kind]??0);$post=$id?get_post($id):null;
        if($post&&$post->post_type===self::TYPE&&$post->post_status==='publish'){
            $s=self::snapshot_post($post);
            if($s['kind']===$kind&&!is_wp_error(SDR_Email_Renderer::validate($s)))return $s;
        }
        return SDR_Email_Defaults::snapshot($kind);
    }
    static function render_kind($kind,$event,$user,$extra=[],$snapshot=null) {
        if(!is_string($kind)||!isset(self::kinds()[$kind]))return SDR_Email_Renderer::error('Type d’email invalide.');
        $ctx=SDR_Email_Context::build($kind,$event,$user,$extra);if(is_wp_error($ctx))return $ctx;
        $s=$snapshot??self::snapshot($kind);
        $rendered=($s['kind']??'')===$kind?SDR_Email_Renderer::render($s,$ctx):new WP_Error('sdr_email_kind','Type de modèle incorrect.');
        if(!is_wp_error($rendered))return $rendered;
        $error=$rendered->get_error_message();$fallback=SDR_Email_Renderer::render(SDR_Email_Defaults::snapshot($kind),$ctx);
        if(!is_wp_error($fallback))$fallback['warnings'][]='Modèle de secours utilisé : '.$error;
        return $fallback;
    }
    static function transport($email,$mail) {
        if(is_wp_error($mail)||!is_email($email))return false;
        $plain=static function($mailer)use($mail){$mailer->AltBody=$mail['plain']??SDR_Email_Renderer::plain($mail['html']);};
        add_action('phpmailer_init',$plain);
        try{return wp_mail($email,$mail['subject'],$mail['html'],['Content-Type: text/html; charset=UTF-8']);}
        finally{remove_action('phpmailer_init',$plain);}
    }
    static function validate_save($post,$request) {
        if(is_wp_error($post))return $post;
        $id=(int)$request['id'];$stored=$id?get_post($id):null;$meta=(array)$request->get_param('meta');
        $status=$post->post_status??($stored->post_status??'draft');
        if($status!=='publish')return $post;
        $s=$stored?self::snapshot_post($stored):SDR_Email_Defaults::snapshot($meta['_sdr_email_kind']??'reminder');
        foreach(['kind','subject','preheader','style'] as $key)if(array_key_exists('_sdr_email_'.$key,$meta))$s[$key]=$meta['_sdr_email_'.$key];
        if(isset($post->post_content))$s['content']=$post->post_content;
        $valid=SDR_Email_Renderer::validate($s);if(is_wp_error($valid))return $valid;
        $test=SDR_Email_Renderer::render($s,SDR_Email_Context::demo($s['kind']));
        return is_wp_error($test)?$test:$post;
    }
    static function routes() {
        foreach(['preview','test','activate'] as $action)register_rest_route('sdr/v1','/email-templates/'.$action,['methods'=>'POST','permission_callback'=>[__CLASS__,'allowed'],'callback'=>[__CLASS__,$action]]);
    }
    static function valid_id($value) { return (is_int($value)||(is_string($value)&&ctype_digit($value)))&&(int)$value>=0; }
    static function request_snapshot($request) {
        if(!self::valid_id($request['template']??0))return SDR_Email_Renderer::error('Modèle invalide.');
        $id=(int)($request['template']??0);$post=$id?get_post($id):null;
        if($id&&(!$post||$post->post_type!==self::TYPE||!current_user_can('edit_post',$id)))return new WP_Error('sdr_email_missing','Modèle introuvable.',['status'=>404]);
        $kind=$request['kind']??($post?get_post_meta($id,'_sdr_email_kind',true):'reminder');
        if(!is_string($kind)||!isset(self::kinds()[$kind]))return SDR_Email_Renderer::error('Type d’email invalide.');
        $s=$post?self::snapshot_post($post):SDR_Email_Defaults::snapshot($kind);$s['kind']=$kind;
        foreach(['content','subject','preheader','style'] as $key)if(isset($request[$key]))$s[$key]=$request[$key];
        $valid=SDR_Email_Renderer::validate($s);return is_wp_error($valid)?$valid:$s;
    }
    static function preview($request) {
        $s=self::request_snapshot($request);if(is_wp_error($s))return $s;
        foreach(['event','user'] as $key)if(isset($request[$key])&&!self::valid_id($request[$key]))return SDR_Email_Renderer::error('Choisis une fiche valide pour l’aperçu.');
        $event=absint($request['event']??0);$uid=absint($request['user']??0);$user=$uid?get_userdata($uid):null;
        if($uid&&!$user)return new WP_Error('sdr_email_user','Destinataire introuvable.',['status'=>404]);
        if($event){
            if(get_post_type($event)!=='sdr_event'||!current_user_can('read_post',$event))return new WP_Error('sdr_email_event','Spectacle introuvable.',['status'=>404]);
            $ctx=SDR_Email_Context::build($s['kind'],$event,$user,['preview'=>true,'message'=>'<p>Exemple du message libre composé dans les relances utilisateurs.</p>','subject'=>'Exemple de message libre','expires'=>time()+7*DAY_IN_SECONDS]);
            if(!is_wp_error($ctx))$ctx['warnings'][]='Aperçu uniquement : les liens de réponse sont inactifs et aucun lien existant n’est modifié.';
        }else{
            $scenario=$request['scenario']??'missing_mc';if(!is_string($scenario)||!isset(self::scenarios()[$scenario]))return SDR_Email_Renderer::error('Situation d’aperçu inconnue.');
            $ctx=SDR_Email_Context::demo($s['kind'],$scenario);
            if($user){$ctx['vars']['prenom']=SDR_Coulisses::email_name($user);$ctx['vars']['nom']=$user->last_name;$ctx['vars']['nom_affiche']=$user->display_name;$ctx['vars']['nom_complet']=trim($user->first_name.' '.$user->last_name)?:$user->display_name;$ctx['vars']['email']=$user->user_email;}
        }
        $rendered=SDR_Email_Renderer::render($s,$ctx);if(is_wp_error($rendered))return $rendered;
        $rendered['testRecipient']=wp_get_current_user()->user_email;
        $response=rest_ensure_response($rendered);$response->header('Cache-Control','no-store, private');return $response;
    }
    static function test($request) {
        $preview=self::preview($request);if(is_wp_error($preview))return $preview;$mail=$preview->get_data();$email=wp_get_current_user()->user_email;
        if(!SDR_Coulisses::email_available(wp_get_current_user()))return SDR_Email_Renderer::error('Ton compte doit avoir une adresse email personnelle utilisable.');
        $request_id=$request['requestId']??'';if(!is_string($request_id)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request_id))return SDR_Email_Renderer::error('Identifiant d’aperçu invalide.');
        $key='sdr_email_test_'.get_current_user_id().'_'.hash('sha256',$request_id);
        if(get_transient($key))return ['message'=>'Cet aperçu a déjà été transmis ou sa transmission doit être vérifiée. Aucun nouvel envoi.'];
        $rate_key='sdr_email_test_rate_'.get_current_user_id();$rate=(int)get_transient($rate_key);if($rate>=10)return new WP_Error('sdr_email_rate','Dix aperçus ont déjà été envoyés pendant cette heure. Réessaie plus tard.',['status'=>429]);
        global $wpdb;$lock='sdr_email_test_'.get_current_user_id();if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('sdr_email_busy','Un aperçu est déjà en cours d’envoi.',['status'=>409]);
        try{
            if(get_transient($key))return ['message'=>'Cet aperçu a déjà été transmis. Aucun nouvel envoi.'];
            $rate=(int)get_transient($rate_key);if($rate>=10)return new WP_Error('sdr_email_rate','Dix aperçus ont déjà été envoyés pendant cette heure. Réessaie plus tard.',['status'=>429]);
            set_transient($key,'processing',HOUR_IN_SECONDS);set_transient($rate_key,$rate+1,HOUR_IN_SECONDS);
            $mail['subject']='[APERÇU] '.$mail['subject'];
            if(!self::transport($email,$mail)){delete_transient($key);return new WP_Error('sdr_email_transport','WordPress n’a pas accepté cet aperçu.',['status'=>502]);}
            set_transient($key,'sent',HOUR_IN_SECONDS);return ['message'=>'Aperçu transmis uniquement à '.$email.'. Les boutons de réponse restent inactifs.'];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    static function activate($request) {
        if(!self::valid_id($request['template']??0))return SDR_Email_Renderer::error('Modèle invalide.');
        $id=(int)($request['template']??0);$post=$id?get_post($id):null;
        if(!$post||$post->post_type!==self::TYPE||$post->post_status!=='publish')return SDR_Email_Renderer::error('Publie un modèle valide avant de l’utiliser.');
        $s=self::snapshot_post($post);$valid=SDR_Email_Renderer::validate($s);if(is_wp_error($valid))return $valid;
        foreach(['missing_mc','multiple','missing_details','closed','no_capacity'] as $scenario){$test=SDR_Email_Renderer::render($s,SDR_Email_Context::demo($s['kind'],$scenario));if(is_wp_error($test))return SDR_Email_Renderer::error('Modèle inutilisable dans la situation « '.self::scenarios()[$scenario].' » : '.$test->get_error_message());}
        $active=(array)get_option(self::ACTIVE,[]);$active[$s['kind']]=$id;update_option(self::ACTIVE,$active,false);return ['message'=>'Ce modèle est maintenant utilisé pour : '.self::kinds()[$s['kind']].'.'];
    }
    static function activate_form() {
        if(!self::allowed())wp_die('Accès réservé.',403);check_admin_referer('sdr_email_activate');
        $result=self::activate(['template'=>absint($_POST['template']??0)]);$error=is_wp_error($result)?$result->get_error_message():'';
        wp_safe_redirect(add_query_arg(['post_type'=>self::TYPE,'page'=>'sdr-email-settings','sdr_email_error'=>$error,'sdr_email_saved'=>$error?'0':'1'],admin_url('edit.php')));exit;
    }
    static function settings_page() {
        if(!self::allowed())return;$active=(array)get_option(self::ACTIVE,[]);
        echo '<div class="wrap"><h1>Modèles utilisés pour les emails</h1><p>Les textes, variables, conditions et mises en page se modifient avec Gutenberg. Publier un modèle n’envoie aucun email. Les campagnes déjà commencées gardent leur modèle.</p>';
        if(!empty($_GET['sdr_email_error']))echo '<div class="notice notice-error"><p>'.esc_html(wp_unslash($_GET['sdr_email_error'])).'</p></div>';
        if(($_GET['sdr_email_saved']??'')==='1')echo '<div class="notice notice-success"><p>Le modèle utilisé a été mis à jour.</p></div>';
        foreach(self::kinds() as $kind=>$label){
            $post=!empty($active[$kind])?get_post($active[$kind]):null;$valid=$post&&get_post_meta($post->ID,'_sdr_email_kind',true)===$kind&&$post->post_status==='publish'&&!is_wp_error(SDR_Email_Renderer::validate(self::snapshot_post($post)));
            echo '<section style="padding:20px;background:white;border:1px solid #dcdcde;margin:18px 0;max-width:900px"><h2>'.esc_html($label).'</h2><p>Modèle actuel : '.($valid?'<a href="'.esc_url(get_edit_post_link($post->ID,'raw')).'">'.esc_html($post->post_title).'</a>':'<strong>Modèle de secours intégré</strong>').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sdr_email_activate');echo '<input type="hidden" name="action" value="sdr_email_activate"><label>Utiliser un autre modèle publié <select name="template">';
            foreach(get_posts(['post_type'=>self::TYPE,'post_status'=>'publish','numberposts'=>-1,'meta_key'=>'_sdr_email_kind','meta_value'=>$kind]) as $candidate)echo '<option value="'.$candidate->ID.'" '.selected((int)($active[$kind]??0),$candidate->ID,false).'>'.esc_html($candidate->post_title).'</option>';
            echo '</select></label> <button class="button button-primary">Utiliser ce modèle</button></form></section>';
        }
        echo '<p><a class="button" href="'.esc_url(admin_url('post-new.php?post_type='.self::TYPE)).'">Créer un autre modèle</a></p></div>';
    }
    static function assets() {
        if((get_current_screen()->post_type??'')!==self::TYPE||!self::allowed())return;
        wp_enqueue_media();
        wp_enqueue_script('sdr-email-editor',plugins_url('editor.js',__FILE__),['wp-blocks','wp-block-editor','wp-element','wp-components','wp-data','wp-plugins','wp-editor','wp-api-fetch'],self::VERSION,true);
        wp_enqueue_style('sdr-email-editor',plugins_url('editor.css',__FILE__),['wp-components'],self::VERSION);
        $events=[];foreach(get_posts(['post_type'=>'sdr_event','post_status'=>['publish','draft','private'],'numberposts'=>100,'meta_key'=>'_sdr_date','orderby'=>'meta_value','order'=>'DESC']) as $event)$events[]=['id'=>$event->ID,'label'=>SDR_Coulisses::meta($event->ID,'date').' · '.$event->post_title];
        $users=array_map(static fn($u)=>['id'=>$u->ID,'name'=>$u->display_name],get_users(['role__in'=>['sdr_member','sdr_organizer','administrator'],'number'=>1000,'orderby'=>'display_name']));
        wp_add_inline_script('sdr-email-editor','window.SDREmailEditor='.wp_json_encode(['schemas'=>SDR_Email_Renderer::schemas(),'variables'=>SDR_Email_Context::variables(),'conditions'=>SDR_Email_Context::conditions(),'kinds'=>self::kinds(),'scenarios'=>self::scenarios(),'events'=>$events,'users'=>$users,'active'=>(array)get_option(self::ACTIVE,[]),'settingsUrl'=>admin_url('edit.php?post_type='.self::TYPE.'&page=sdr-email-settings'),'defaultUrl'=>admin_url('edit.php?post_type='.self::TYPE),'defaults'=>array_map(static fn($kind)=>SDR_Email_Defaults::snapshot($kind),array_combine(array_keys(self::kinds()),array_keys(self::kinds())))]) . ';','before');
    }
}
SDR_Email_Templates::boot();
