<?php
/** .local only: troupe creation, multiple associations and title precedence; no email leaves the test. */
if(!defined('WP_CLI')||!WP_CLI||!str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'.local'))throw new RuntimeException('Installation .local uniquement.');
$users=[];$posts=[];$checks=0;$mails=0;$original=get_current_user_id();$fail_insert=null;
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$rest=static function($method,$path,$data=[]){$r=new WP_REST_Request($method,$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return rest_do_request($r);};
$block=static function()use(&$mails){$mails++;return true;};add_filter('pre_wp_mail',$block,PHP_INT_MAX);
try{
 require_once ABSPATH.'wp-admin/includes/user.php';global $wpdb;
 foreach(['sdr_organizer','sdr_member','administrator'] as $role){$login='troupe-check-'.wp_generate_password(12,false,false);$id=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(40),'user_email'=>$login.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$users[]=$id;}
 [$org,$member,$admin]=$users;wp_set_current_user($org);
 $assert(!current_user_can('edit_sdr_items')&&!current_user_can('publish_sdr_items'),'Création bornée sans droits généraux sur les fiches');
 $name="Les étoiles d'essai ".wp_generate_password(8,false,false);
 $fields=['name'=>$name,'city'=>'Néant-sur-Yvel','website'=>'https://troupe.example.invalid/'];
 $created=$rest('POST','/sdr/v1/teams',$fields);$assert($created->get_status()===200&&$created->get_data()['created'],'Organisation autorisée à créer une troupe');
 $one=$created->get_data()['team'];$posts[]=$one['id'];
 $assert($one['name']===$name&&$one['city']===$fields['city']&&get_post_meta($one['id'],'_sdr_website',true)===$fields['website'],'Nom, accents, ville et site conservés');
 $same=$rest('POST','/sdr/v1/teams',['name'=>'  '.mb_strtoupper(str_replace(' ','   ',$name)).'  ','city'=>' NÉANT-SUR-YVEL ','website'=>'https://autre.example.invalid']);
 $assert(!$same->get_data()['created']&&$same->get_data()['team']['id']===$one['id'],'Doublon réutilisé malgré casse et espaces');
 $assert(get_post_meta($one['id'],'_sdr_website',true)===$fields['website'],'Doublon sans écrasement de la fiche');
 $other=$rest('POST','/sdr/v1/teams',array_replace($fields,['city'=>'Ploërmel']));$two=$other->get_data()['team'];$posts[]=$two['id'];
 $assert($other->get_data()['created']&&$one['id']!==$two['id'],'Même nom dans deux villes distinctes');
 $options=$rest('GET','/sdr/v1/event-options')->get_data()['teams'];$cities=array_column($options,'city','id');$assert($cities[$one['id']]===$fields['city']&&$cities[$two['id']]==='Ploërmel','Villes disponibles après rechargement pour distinguer les homonymes');
 foreach([[],['name'=>' '],['name'=>[]],['name'=>str_repeat('é',201)],['name'=>'Essai','city'=>false],['name'=>'Essai','city'=>str_repeat('é',121)],['name'=>'Essai','website'=>'javascript:alert(1)'],['name'=>'Essai','website'=>'ftp://example.org'],['name'=>'Essai','website'=>[]]] as $bad)$assert($rest('POST','/sdr/v1/teams',$bad)->get_status()===400,'Données de troupe invalides refusées');
 $date=(new DateTimeImmutable('today',wp_timezone()))->modify('+37 days')->format('Y-m-d');
 $base=['title'=>'Titre choisi en priorité','date'=>$date,'time'=>'20:30','callTime'=>'19:00','venue'=>0,'format'=>0,'teams'=>[$one['id'],$two['id']],'capacity'=>['joueur'=>3,'mc'=>1,'dj'=>1],'closed'=>false,'intermission'=>false,'bookingUrl'=>'','description'=>''];
 $event=$rest('POST','/sdr/v1/events',$base);$assert($event->get_status()===200,'Spectacle avec plusieurs troupes');$id=$event->get_data()['id'];$posts[]=$id;
 $assert(SDR_Coulisses::event_team_ids($id)===$base['teams']&&(int)get_post_meta($id,'_sdr_team',true)===$one['id'],'Liste complète et première troupe historique conservées');
 $data=$event->get_data()['event'];$assert($data['title']===$base['title']&&get_the_title($id)===$base['title'],'Titre saisi prioritaire partout');
 $assert(count($data['teams'])===2&&$data['teamUrl']===''&&str_contains($data['team'],$name),'Toutes les troupes exposées sans lien unique trompeur');
 foreach([$one,$two] as $team){$related=SDR_Public_Blocks::related_events([],'',(object)['context'=>['postId'=>$team['id']]]);$assert(str_contains($related,get_permalink($id)),'Spectacle présent sur chaque fiche de troupe');}
 $details=SDR_Public_Blocks::details([],'',(object)['context'=>['postId'=>$id]]);foreach([$one,$two] as $team)$assert(str_contains($details,get_permalink($team['id'])),'Lien public vers chaque troupe');
 $assert(str_contains(SDR_Coulisses::agenda(),$base['title']),'Agenda utilise le titre saisi');
 $auto=$rest('POST','/sdr/v1/events',array_replace($base,['title'=>'   ']));$assert($auto->get_status()===200,'Création avec titre et contenu vides');$auto_id=$auto->get_data()['id'];$posts[]=$auto_id;
 $expected=SDR_Settings::match_name().' VS '.$name.' VS '.$name;
 $assert(get_post_field('post_title',$auto_id,'raw')===''&&$auto->get_data()['event']['title']===$expected&&get_the_title($auto_id)===$expected,'Titre automatique sans remplir le champ brut');
 $assert(SDR_Coulisses::event_email_details(get_post($auto_id))['title']===$expected,'Même titre automatique dans les emails');
 $public=$rest('GET','/wp/v2/sdr_event/'.$auto_id)->get_data();$assert($public['title']['rendered']===$expected,'Titre rendu dans le REST WordPress');
 $edit=$rest('GET','/sdr/v1/events/'.$auto_id.'/edit')->get_data();$assert($edit['title']===''&&$edit['teams']===$base['teams'],'Édition conserve le mode automatique et les deux choix');
 foreach([null,1,'bad',[-1],[0],[$id],[$one['id'],false],array_fill(0,21,$one['id'])] as $teams)$assert($rest('POST','/sdr/v1/events',array_replace($base,['teams'=>$teams]))->get_status()===400,'Association invalide refusée');
 $legacy=$base;unset($legacy['teams']);$legacy['team']=$two['id'];$r=$rest('POST','/sdr/v1/events',$legacy);$legacy_id=$r->get_data()['id'];$posts[]=$legacy_id;$assert(SDR_Coulisses::event_team_ids($legacy_id)===[$two['id']],'Ancien paramètre team toujours accepté');
 remove_action('transition_post_status',['SDR_Coulisses','queue_new_event_email'],10);
 $old=wp_insert_post(['post_type'=>'sdr_event','post_status'=>'publish','post_title'=>'Ancienne fiche','meta_input'=>['_sdr_date'=>$date,'_sdr_team'=>$one['id']]]);$posts[]=$old;
 $assert(SDR_Coulisses::event_team_ids($old)===[$one['id']]&&SDR_Coulisses::event_data(get_post($old))['title']==='Ancienne fiche','Anciennes données lues sans migration destructive');
 wp_set_current_user($admin);
 $request=new WP_REST_Request('GET','/wp/v2/sdr_event/'.$old);$request->set_query_params(['context'=>'edit']);$old_edit=rest_do_request($request)->get_data();$assert($old_edit['meta']['_sdr_teams']===[$one['id']],'Gutenberg charge le choix historique dans la liste multiple');
 $revision=SDR_Coulisses::event_edit_revision(get_post($auto_id));
 $r=$rest('POST','/wp/v2/sdr_event/'.$auto_id,['meta'=>['_sdr_teams'=>[$two['id']]]]);$assert($r->get_status()===200&&SDR_Coulisses::event_team_ids($auto_id)===[$two['id']]&&(int)get_post_meta($auto_id,'_sdr_team',true)===$two['id'],'Gutenberg met à jour liste et lien historique');
 $assert(SDR_Coulisses::event_edit_revision(get_post($auto_id))!==$revision,'Changement des troupes détecté par la révision');
 $r=$rest('POST','/sdr/v1/events/'.$auto_id.'/edit',array_replace($base,['title'=>'','revision'=>$revision]));$assert($r->get_status()===409,'Formulaire devenu obsolète refusé');
 $r=$rest('POST','/wp/v2/sdr_event/'.$auto_id,['meta'=>['_sdr_team'=>$one['id']]]);$assert($r->get_status()===200&&SDR_Coulisses::event_team_ids($auto_id)===[$one['id']],'Ancien champ REST scalaire synchronisé');
 $r=$rest('POST','/wp/v2/sdr_event/'.$auto_id,['meta'=>['_sdr_teams'=>[$id]]]);$assert($r->get_status()===400,'Mauvais type de fiche refusé dans Gutenberg');
 $r=$rest('POST','/wp/v2/sdr_event/'.$auto_id,['meta'=>['_sdr_teams'=>[],'_sdr_team'=>0]]);$assert($r->get_status()===200&&SDR_Coulisses::event_team_ids($auto_id)===[]&&get_post_meta($auto_id,'_sdr_event_team')===[],'Retrait complet sans réapparition de la troupe historique');
 $assert(str_starts_with(SDR_Coulisses::event_data(get_post($auto_id))['title'],'Spectacle du '),'Sans troupe ni format : spectacle et date');
 $format=wp_insert_post(['post_type'=>'sdr_format','post_status'=>'publish','post_title'=>'Cabaret test']);$posts[]=$format;update_post_meta($auto_id,'_sdr_format',$format);
 $assert(str_starts_with(SDR_Coulisses::event_data(get_post($auto_id))['title'],'Cabaret test du '),'Sans troupe : format et date');
 foreach([$member,0] as $uid){wp_set_current_user($uid);$assert($rest('POST','/sdr/v1/teams',$fields)->get_status()===($uid?403:401),'Création interdite au membre et au visiteur');}
 wp_set_current_user($admin);$minimal=$rest('POST','/sdr/v1/teams',['name'=>$name.' minimale']);$posts[]=$minimal->get_data()['team']['id'];$assert($minimal->get_status()===200,'Administration autorisée avec le seul nom');
 $fail_insert=static fn($empty,$post)=>($post['post_type']??'')==='sdr_team'?true:$empty;add_filter('wp_insert_post_empty_content',$fail_insert,20,2);
 $assert($rest('POST','/sdr/v1/teams',['name'=>$name.' échec'])->get_status()===500,'Échec de stockage sans faux succès');
 $assert($mails===0,'Aucun email déclenché par les ajouts ou modifications');
 WP_CLI::success($checks.' vérifications réussies.');
}finally{
 if($fail_insert)remove_filter('wp_insert_post_empty_content',$fail_insert,20);
 foreach(array_reverse($posts) as $id)if($id){$wpdb->delete($wpdb->prefix.'sdr_responses',['event_id'=>$id]);wp_clear_scheduled_hook('sdr_notify_new_event',[$id]);wp_delete_post($id,true);}
 foreach($users as $uid)wp_delete_user($uid);wp_set_current_user($original);remove_filter('pre_wp_mail',$block,PHP_INT_MAX);add_action('transition_post_status',['SDR_Coulisses','queue_new_event_email'],10,3);
}
