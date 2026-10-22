<?php
/** New-date invitations — .local only, every email intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string)wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$ids=[]; $events=[]; $campaigns=[]; $mails=[]; $checks=0;
$original_user=get_current_user_id(); $restrict=null;
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$rest=static function($method,$path,$data=[]){$r=new WP_REST_Request($method,'/sdr/v1/'.$path);if($method==='GET')$r->set_query_params($data);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}return rest_do_request($r);};
$block=static function($pre,$mail)use(&$mails){$mails[]=$mail;return true;};
add_filter('pre_wp_mail',$block,PHP_INT_MAX,2);
try{
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(['sdr_organizer','sdr_member','sdr_member','sdr_member','sdr_member'] as $i=>$role){
        $login='sr-announce-'.wp_generate_password(12,false,false);
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.org','user_pass'=>wp_generate_password(40),'display_name'=>'Invitation '.$i,'role'=>$role,'meta_input'=>['sdr_roles'=>$i===0?[]:($i===2?['dj']:['joueur','mc']),'sdr_stage_opt_in'=>$i>0&&$i<4]]);
        if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$ids[]=$id;
    }
    [$organizer,$alice,$boris,$clara,$optout]=$ids;
    wp_set_current_user($organizer);
    $date=(new DateTimeImmutable('today',wp_timezone()))->modify('+20 days')->format('Y-m-d');
    $fields=['title'=>'Une nouvelle date & des amis','date'=>$date,'time'=>'20:30','callTime'=>'19:00','venue'=>0,'team'=>0,'format'=>0,'capacity'=>['joueur'=>4,'mc'=>1,'dj'=>1],'bookingUrl'=>'','description'=>'Une belle soirée.','closed'=>false,'intermission'=>false];
    $before=count($mails);
    $created=$rest('POST','events',$fields);
    $assert($created->get_status()===200,'Création depuis les Coulisses autorisée');
    $event=$created->get_data()['id'];$events[]=$event;$root='events/'.$event.'/reminders';
    $assert(count($mails)===$before&&!get_post_meta($event,'_sdr_date_announcement_pending',true)&&!wp_next_scheduled('sdr_notify_new_event',[$event]),'Enregistrement simple sans email ni envoi différé');
    $audience=$rest('GET',$root,['kind'=>'announcement']);
    $users=array_column($audience->get_data()['users'],'id');
    $assert($audience->get_status()===200&&count(array_intersect($users,[$alice,$boris,$clara]))===3&&!in_array($optout,$users,true),'Liste de création limitée aux profils OK pour la scène');
    $assert(count($mails)===$before&&!get_user_meta($organizer,SDR_Event_Reminders::meta_key($event),true),'Ouvrir ou annuler la sélection n’envoie rien et ne prépare pas de campagne');
    $assert($rest('GET',$root,['kind'=>'unexpected'])->get_status()===400,'Contexte de sélection invalide refusé');
    $request=['kind'=>'announcement','requestId'=>'announcement-selection-123','recipients'=>[$alice,$boris]];
    $result=$rest('POST',$root,$request);
    $assert($result->get_status()===200&&$result->get_data()['kind']==='announcement'&&$result->get_data()['total']===2,'Campagne d’annonce avec sélection confirmée');
    $campaign=$result->get_data()['id'];$campaigns[]=$campaign;
    $assert($rest('POST',$root,$request)->get_data()['id']===$campaign,'Annonce idempotente après une répétition réseau');
    $assert($rest('POST',$root,array_replace($request,['kind'=>'reminder']))->get_status()===409,'Une même demande ne change pas de modèle après confirmation');
    $batch=$rest('POST',$root.'/'.$campaign.'/send');
    $assert($batch->get_status()===200&&$batch->get_data()['accepted']===2&&count($mails)===$before+2,'Seules les deux personnes cochées sont prévenues');
    $assert(!in_array(get_userdata($clara)->user_email,array_column(array_slice($mails,$before),'to'),true),'Personne décochée non contactée');
    $mail=$mails[$before];
    $assert(str_starts_with($mail['subject'],'Nouvelle date : ')&&str_contains($mail['message'],'LA PROCHAINE SORTIE')&&str_contains($mail['message'],'Une nouvelle date.<br>Et si tu en étais'),'Texte d’annonce adapté à une nouvelle date');
    $assert(str_contains($mail['message'],'OK, je me lance')&&str_contains($mail['message'],'Je ne suis pas dispo')&&in_array('Content-Type: text/html; charset=UTF-8',$mail['headers'],true),'Même email HTML et mêmes boutons que les relances');
    preg_match('/href="([^"]*sdr-reponse[^"]*)"/',$mail['message'],$link);
    parse_str(wp_parse_url(html_entity_decode($link[1],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);
    $credentials=['event'=>$query['spectacle'],'user'=>$query['membre'],'token'=>$query['cle']];
    wp_set_current_user(0);
    $assert(!is_wp_error(SDR_Event_Reminders::context($credentials))&&!SDR_Event_Reminders::response($event,$alice),'Lien d’annonce consultable sans connexion et sans réponse automatique');
    $answer=$rest('POST','email-response',$credentials+['availability'=>'yes','roles'=>['joueur','mc']]);
    $assert($answer->get_status()===200&&SDR_Event_Reminders::response($event,$alice)->availability==='yes'&&get_current_user_id()===0,'Réponse multirôle à l’annonce sans ouvrir de session');
    wp_set_current_user($organizer);
    $rest('POST',$root.'/'.$campaign.'/send');$assert(count($mails)===$before+2,'Reprise d’une annonce sans double envoi');
    $closed=$rest('POST','events',array_replace($fields,['title'=>'Fermé','closed'=>true]));$closed_id=$closed->get_data()['id'];$events[]=$closed_id;
    $assert($rest('GET','events/'.$closed_id.'/reminders',['kind'=>'announcement'])->get_status()===409,'Pas d’invitation pour un spectacle fermé');
    wp_set_current_user($alice);$assert($rest('POST','events',$fields)->get_status()===403&&$rest('GET',$root,['kind'=>'announcement'])->get_status()===403,'Création et sélection réservées à l’organisation');
    // Legacy WordPress publication keeps its automatic trigger, with the shared opt-in template.
    $restrict=static function($q)use($ids){$q->set('include',$ids);};add_action('pre_get_users',$restrict);
    wp_set_current_user(0);
    $automatic=wp_insert_post(['post_type'=>'sdr_event','post_status'=>'publish','post_title'=>'Publication WordPress','meta_input'=>['_sdr_date'=>$date,'_sdr_time'=>'21:00']]);$events[]=$automatic;
    $assert((bool)get_post_meta($automatic,'_sdr_date_announcement_pending',true),'Déclenchement de publication WordPress conservé');
    wp_clear_scheduled_hook('sdr_notify_new_event',[$automatic]);$before_auto=count($mails);
    $auto=SDR_Coulisses::notify_new_event($automatic);
    $assert($auto['sent']===3&&$auto['failed']===0&&count($mails)===$before_auto+3,'Annonce WordPress limitée à tous les profils volontaires');
    foreach(array_slice($mails,$before_auto) as $auto_mail)$assert(str_starts_with($auto_mail['subject'],'Nouvelle date : ')&&str_contains($auto_mail['message'],'sdr-reponse')&&str_contains($auto_mail['message'],'LA PROCHAINE SORTIE'),'Même modèle avec réponse personnelle pour les publications WordPress');
    SDR_Coulisses::notify_new_event($automatic);$assert(count($mails)===$before_auto+3&&!get_post_meta($automatic,'_sdr_date_announcement_pending',true),'Annonce WordPress terminée sans nouvel envoi');
    $interrupted=wp_insert_post(['post_type'=>'sdr_event','post_status'=>'publish','post_title'=>'Envoi interrompu','meta_input'=>['_sdr_date'=>$date]]);$events[]=$interrupted;wp_clear_scheduled_hook('sdr_notify_new_event',[$interrupted]);
    update_post_meta($interrupted,'_sdr_date_announcement_processing_users',[$alice]);$before_uncertain=count($mails);
    $uncertain=SDR_Coulisses::notify_new_event($interrupted);
    $assert($uncertain['uncertain']===1&&$uncertain['sent']===2&&count($mails)===$before_uncertain+2,'Annonce WordPress interrompue sans renvoi au destinataire incertain');
    $sample=SDR_Event_Reminders::email($event,get_userdata($boris),str_repeat('a',64),time()+3600,'reminder');
    $assert(str_starts_with($sample['subject'],'On joue ensemble ?')&&str_contains($sample['html'],'LE PETIT RAPPEL'),'Modèle de relance existant conservé');
    WP_CLI::success($checks.' vérifications réussies. Tous les emails interceptés, aucun envoi réel.');
}finally{
    if($restrict)remove_action('pre_get_users',$restrict);
    foreach($campaigns as $id)delete_option(SDR_Event_Reminders::PREFIX.$id);
    global $wpdb;foreach($events as $id){$wpdb->delete($wpdb->prefix.'sdr_responses',['event_id'=>$id]);wp_clear_scheduled_hook('sdr_notify_new_event',[$id]);wp_delete_post($id,true);}
    foreach($ids as $id)wp_delete_user($id);
    wp_set_current_user($original_user);remove_filter('pre_wp_mail',$block,PHP_INT_MAX);
}
