<?php
/** wp eval-file tests/venue-creation.php — .local only, no real email. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string)wp_parse_url(home_url(), PHP_URL_HOST), '.local')) throw new RuntimeException('Installation .local uniquement.');
$users=[]; $posts=[]; $checks=0; $mails=0; $original_user=get_current_user_id(); $fail_insert=null;
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$rest=static function($method,$path,$data=[]){$r=new WP_REST_Request($method,'/sdr/v1/'.$path);if($method==='GET')$r->set_query_params($data);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}return rest_do_request($r);};
$block=static function()use(&$mails){$mails++;return true;};
add_filter('pre_wp_mail',$block,PHP_INT_MAX);
try{
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(['sdr_organizer','sdr_member','administrator'] as $role){
        $login='sr-venue-'.wp_generate_password(12,false,false);
        $uid=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(40),'role'=>$role]);
        if(is_wp_error($uid))throw new RuntimeException($uid->get_error_message());$users[]=$uid;
    }
    [$organizer,$member,$admin]=$users;
    wp_set_current_user($organizer);
    $assert(!current_user_can('edit_sdr_items')&&!current_user_can('publish_sdr_items'),'L’organisation ne gagne pas de droits généraux sur les fiches');
    $name="Salle d'essai ".wp_generate_password(10,false,false);
    $fields=['name'=>$name,'city'=>'Néant-sur-Yvel','address'=>"12, rue de l'Église"];
    $created=$rest('POST','venues',$fields);
    $assert($created->get_status()===200&&$created->get_data()['created']===true,'Création autorisée pour l’organisation');
    $venue=$created->get_data()['venue'];$posts[]=$venue['id'];
    $assert($venue['name']===$name&&$venue['city']===$fields['city']&&$venue['address']===$fields['address'],'Accents et apostrophes préservés');
    $assert(get_post_status($venue['id'])==='publish'&&get_post_type($venue['id'])==='sdr_venue','Lieu publié dans la bonne collection');
    $options=$rest('GET','event-options')->get_data();
    $assert(in_array($venue,$options['venues'],true),'Lieu immédiatement proposé au formulaire de spectacle');
    $same=$rest('POST','venues',['name'=>'  '.mb_strtoupper(str_replace(' ','   ',$name)).'  ','city'=>' NÉANT-SUR-YVEL ','address'=>'Autre adresse']);
    $assert($same->get_status()===200&&$same->get_data()['created']===false&&$same->get_data()['venue']['id']===$venue['id'],'Doublon reconnu malgré la casse et les espaces');
    $assert(get_post_meta($venue['id'],'_sdr_address',true)===$fields['address'],'Un doublon ne modifie pas le lieu existant');
    $other=$rest('POST','venues',array_replace($fields,['city'=>'Ploërmel']));$posts[]=$other->get_data()['venue']['id'];
    $assert($other->get_status()===200&&$other->get_data()['created']&&end($posts)!==$venue['id'],'Deux lieux de même nom dans des communes différentes sont distincts');
    foreach([[],['name'=>'   '],['name'=>['invalide']],['name'=>'Lieu','city'=>true],['name'=>str_repeat('é',201)],['name'=>'Lieu','city'=>str_repeat('a',121)],['name'=>'Lieu','address'=>str_repeat('a',301)]] as $bad){
        $assert($rest('POST','venues',$bad)->get_status()===400,'Données invalides refusées');
    }
    $date=(new DateTimeImmutable('today',wp_timezone()))->modify('+20 days')->format('Y-m-d');
    $event_fields=['title'=>'Spectacle test lieu','date'=>$date,'time'=>'20:30','callTime'=>'19:00','venue'=>$venue['id'],'team'=>0,'format'=>0,'capacity'=>['joueur'=>4,'mc'=>1,'dj'=>1],'bookingUrl'=>'','description'=>'Présentation conservée','closed'=>false,'intermission'=>true];
    $event=$rest('POST','events',$event_fields);$posts[]=$event->get_data()['id'];$event_id=end($posts);
    $assert($event->get_status()===200&&get_post_meta($event_id,'_sdr_venue',true)==$venue['id'],'Spectacle créé avec le nouveau lieu');
    $edit=$rest('GET','events/'.$event_id.'/edit')->get_data();
    $updated=$rest('POST','events/'.$event_id.'/edit',$event_fields+['revision'=>$edit['revision']]);
    $assert($updated->get_status()===200&&get_post_field('post_content',$event_id)==='Présentation conservée','Lieu accepté en édition sans perte de présentation');
    foreach([$member,0] as $uid){wp_set_current_user($uid);$assert($rest('POST','venues',$fields)->get_status()===($uid?403:401),'Création interdite aux membres et visiteurs');}
    wp_set_current_user($admin);
    $minimal=$rest('POST','venues',['name'=>$name.' minimal']);$posts[]=$minimal->get_data()['venue']['id'];
    $assert($minimal->get_status()===200&&$minimal->get_data()['venue']['city']===''&&$minimal->get_data()['venue']['address']==='','Administration autorisée, adresse et commune facultatives');
    $fail_insert=static fn($empty,$post)=>$post['post_type']==='sdr_venue'?true:$empty;
    add_filter('wp_insert_post_empty_content',$fail_insert,10,2);
    $assert($rest('POST','venues',['name'=>$name.' échec'])->get_status()===500,'Erreur de stockage renvoyée sans faux succès');
    $assert($mails===0,'Aucun email envoyé pendant la création de lieux et le spectacle');
    WP_CLI::success($checks.' vérifications réussies.');
}finally{
    if($fail_insert)remove_filter('wp_insert_post_empty_content',$fail_insert,10);
    foreach(array_reverse($posts) as $id)if($id)wp_delete_post($id,true);
    foreach($users as $uid)wp_delete_user($uid);
    wp_set_current_user($original_user);remove_filter('pre_wp_mail',$block,PHP_INT_MAX);
}
