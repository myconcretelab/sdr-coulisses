<?php
/** wp eval-file tests/email-templates.php — local only, every email intercepted. */
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'.local')) throw new RuntimeException('Installation .local uniquement.');
$users=[];$posts=[];$campaigns=[];$mails=[];$checks=0;$original=get_current_user_id();$active=get_option(SDR_Email_Templates::ACTIVE);$fail=false;$restrict=null;$free_campaigns=[];
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$rest=static function($method,$path,$data=[]){$r=new WP_REST_Request($method,$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return rest_do_request($r);};
$intercept=static function($pre,$mail)use(&$mails,&$fail){$mails[]=$mail;return !$fail;};
add_filter('pre_wp_mail',$intercept,PHP_INT_MAX,2);remove_action('transition_post_status',['SDR_Coulisses','queue_new_event_email'],10);
try{
 require_once ABSPATH.'wp-admin/includes/user.php';global $wpdb;
 $prefix='sdr-email-test-'.wp_generate_password(8,false,false);
 foreach(['administrator','sdr_organizer','sdr_member','sdr_member','sdr_member','sdr_member','sdr_member','sdr_member','sdr_member'] as $i=>$role){$uid=wp_insert_user(['user_login'=>$prefix.$i,'user_pass'=>wp_generate_password(40),'user_email'=>$prefix.$i.'@example.org','display_name'=>'Essai modèle '.$i,'first_name'=>$i===2?'Éloïse & Alice':'Camille','role'=>$role,'meta_input'=>['sdr_roles'=>['joueur','mc'],'sdr_stage_opt_in'=>$i>1]]);if(is_wp_error($uid))throw new RuntimeException($uid->get_error_message());$users[]=$uid;}
 wp_set_current_user($users[0]);
 $date=(new DateTimeImmutable('today',wp_timezone()))->modify('+21 days')->format('Y-m-d');
 $event=wp_insert_post(['post_type'=>'sdr_event','post_status'=>'publish','post_title'=>'Essai <équipe> & amis','meta_input'=>['_sdr_date'=>$date,'_sdr_capacity_mc'=>2]]);$posts[]=$event;
 $make=static function($kind,$mark='')use(&$posts){$s=SDR_Email_Defaults::snapshot($kind);$id=wp_insert_post(wp_slash(['post_type'=>SDR_Email_Templates::TYPE,'post_status'=>'publish','post_title'=>'Essai modèle '.$kind,'post_content'=>$s['content'],'meta_input'=>['_sdr_email_kind'=>$kind,'_sdr_email_subject'=>$mark.$s['subject'],'_sdr_email_preheader'=>$s['preheader'],'_sdr_email_style'=>$s['style']]]));$posts[]=$id;return $id;};
 $id=$make('reminder','VERSION A ');$message_id=$make('message','LIBRE A ');$team_id=$make('team','ÉQUIPE A ');
 foreach(SDR_Email_Templates::kinds() as $kind=>$label){
  $s=SDR_Email_Defaults::snapshot($kind);$assert(!is_wp_error(SDR_Email_Renderer::validate($s)),'Modèle valide : '.$kind);
  foreach(SDR_Email_Templates::scenarios() as $scenario=>$text){$ctx=SDR_Email_Context::demo($kind,$scenario);$r=SDR_Email_Renderer::render($s,$ctx);$assert(!is_wp_error($r),'Rendu '.$kind.' / '.$scenario);$assert(str_contains($r['html'],'#apercu-reply_yes')===$ctx['conditions']['can_reply'],'Boutons adaptés : '.$kind.' / '.$scenario);$assert(!str_contains($r['html'],'sdr-reponse'),'Aperçu sans secret : '.$kind.' / '.$scenario);}
 }
 $assert(count($mails)===0,'Créer ou publier un modèle ne transmet rien');
 $assert(!get_post_type_object(SDR_Email_Templates::TYPE)->publicly_queryable,'Fiches non publiques');
 foreach([0,$users[1],$users[2]] as $uid){wp_set_current_user($uid);foreach(['/wp/v2/'.SDR_Email_Templates::BASE,'/wp/v2/'.SDR_Email_Templates::BASE.'/'.$id,'/wp/v2/'.SDR_Email_Templates::BASE.'/'.$id.'/revisions','/wp/v2/'.SDR_Email_Templates::BASE.'/'.$id.'/autosaves'] as $path)$assert(in_array($rest('GET',$path)->get_status(),[401,403],true),'Confidentialité REST '.$uid.' '.$path);foreach(['preview','test','activate'] as $action)$assert(in_array($rest('POST','/sdr/v1/email-templates/'.$action,['template'=>$id])->get_status(),[401,403],true),'Outil réservé '.$action);}
 wp_set_current_user($users[0]);
 $assert($rest('GET','/wp/v2/'.SDR_Email_Templates::BASE.'/'.$id)->get_status()===200,'Lecture administrateur');
 $base=SDR_Email_Defaults::snapshot('reminder');$b=[SDR_Email_Defaults::class,'block'];
 $request=['template'=>$id,'event'=>$event,'user'=>$users[2]];
 $before=get_user_meta($users[2],SDR_Event_Reminders::token_key($event),true);
 $r=$rest('POST','/sdr/v1/email-templates/preview',$request);$assert($r->get_status()===200,'Aperçu réel');$d=$r->get_data();$assert(str_contains($d['html'],'Éloïse &amp; Alice'),'Variables échappées');$assert(str_contains($d['html'],'#apercu-reply_yes')&&!str_contains($d['html'],'sdr-reponse'),'Aperçu réel inerte');$assert(get_user_meta($users[2],SDR_Event_Reminders::token_key($event),true)===$before&&!SDR_Event_Reminders::response($event,$users[2]),'Prévisualiser ne touche ni liens ni réponse');
 $context=SDR_Email_Context::build('reminder',$event,get_userdata($users[2]),['preview'=>true]);$assert($context['vars']['lieu']===''&&$context['vars']['jours_avant_spectacle']==='21'&&!$context['conditions']['has_venue'],'Informations absentes et jours restants exacts');
 update_user_meta($users[2],'sdr_roles',[]);$assert(!SDR_Email_Context::build('reminder',$event,get_userdata($users[2]))['conditions']['can_reply'],'Profil sans rôle sans boutons');update_user_meta($users[2],'sdr_roles',['joueur','mc']);
 update_user_meta($users[2],'sdr_stage_opt_in',false);$assert(!SDR_Email_Context::build('reminder',$event,get_userdata($users[2]))['conditions']['can_reply'],'Profil désinscrit sans boutons');update_user_meta($users[2],'sdr_stage_opt_in',true);
 update_post_meta($event,'_sdr_closed',true);$assert(!SDR_Email_Context::build('announcement',$event,get_userdata($users[2]))['conditions']['can_reply'],'Spectacle fermé sans boutons');update_post_meta($event,'_sdr_closed',false);
 update_post_meta($event,'_sdr_date',(new DateTimeImmutable('today',wp_timezone()))->modify('-1 day')->format('Y-m-d'));$context=SDR_Email_Context::build('announcement',$event,get_userdata($users[2]));$assert(!$context['conditions']['can_reply']&&$context['vars']['jours_avant_spectacle']==='-1','Spectacle passé, nombre de jours signé');update_post_meta($event,'_sdr_date',$date);
 SDR_Coulisses::save_response($event,$users[2],['availability'=>'maybe','roles'=>['mc']]);$context=SDR_Email_Context::build('reminder',$event,get_userdata($users[2]));$assert(!$context['conditions']['can_reply']&&$context['conditions']['response_maybe'],'Réponse si besoin ne redemande pas une inscription');$wpdb->delete($wpdb->prefix.'sdr_responses',['event_id'=>$event,'user_id'=>$users[2]]);
 foreach([['template'=>[$id]],['template'=>'-1'],['event'=>'1.4'],['user'=>[$users[2]]],['scenario'=>['missing_mc'],'event'=>0],['kind'=>['reminder']]] as $invalid)$assert($rest('POST','/sdr/v1/email-templates/preview',array_merge($request,$invalid))->get_status()>=400,'Requête mal formée rejetée');
 $ctx=SDR_Email_Context::demo('reminder');$ctx['vars']['lieu']='';
 $assert(SDR_Email_Renderer::replace('{{lieu|Lieu surprise}} — {{places_dj}}',$ctx,false)==='Lieu surprise — 0','Valeur de secours et zéro conservé');
 $ctx['vars']['prenom']='<img src=x onerror=alert(1)> &';$assert(SDR_Email_Renderer::replace('{{prenom}}',$ctx)==='&lt;img src=x onerror=alert(1)&gt; &amp;','Donnée malveillante échappée');
 $ctx['vars']['prenom']='{{spectacle}}';$assert(SDR_Email_Renderer::replace('{{prenom}}',$ctx,false)==='{{spectacle}}','Substitution unique sans récursion');
 $assert(SDR_Email_Renderer::condition(['rules'=>['needs_mc','needs_dj'],'mode'=>'any'],$ctx)&&!SDR_Email_Renderer::condition(['rules'=>['needs_mc','needs_dj'],'mode'=>'all'],$ctx)&&SDR_Email_Renderer::condition(['rules'=>['needs_dj'],'invert'=>true],$ctx),'Conditions ET OU inversion');
 foreach(['{{inconnu}}','{{prenom','}}','{{php|system}}'] as $bad)$assert(is_wp_error(SDR_Email_Renderer::validate_variables($bad)),'Variable invalide : '.$bad);
 $rich=SDR_Email_Renderer::rich('<script>alert(1)</script><strong>{{nom}}</strong><img src=x onerror=alert(1)><a href="javascript:alert(1)">X</a>',$ctx);$assert(!str_contains($rich,'<script')&&!str_contains($rich,'onerror')&&!str_contains($rich,'javascript:')&&str_contains($rich,'<strong>'),'HTML riche assaini');
 foreach(['javascript:alert(1)','data:text/html,x','//example.org','https://example.org/ a',"https://example.org/\n"] as $url)$assert(SDR_Email_Renderer::url($url)==='','Adresse dangereuse rejetée');
 $assert(SDR_Email_Renderer::url('https://example.org/a?b=1')!=='','Adresse https acceptée');
 foreach([
  [$b('text',['content'=>'{{inconnu}}'])],[$b('button',['target'=>'custom','url'=>'javascript:alert(1)'])],[$b('text',['fontSize'=>999])],[$b('text',['color'=>'red;position:absolute'])],[$b('condition',['rules'=>['code_arbitraire']])],[$b('condition',['rules'=>[]])],[$b('columns',[],array_fill(0,4,$b('column')))],[$b('column')],['blockName'=>'core/html','attrs'=>[],'innerBlocks'=>[],'innerHTML'=>'<p>X</p>','innerContent'=>['<p>X</p>']]
 ] as $bad){$s=$base;$s['content']=serialize_blocks(isset($bad['blockName'])?[$bad]:$bad);$assert(is_wp_error(SDR_Email_Renderer::validate($s)),'Bloc non compatible refusé');}
 $s=$base;$s['content']=serialize_blocks(array_fill(0,301,$b('divider')));$assert(is_wp_error(SDR_Email_Renderer::validate($s)),'Limite nombre de blocs');
 $nested=$b('text',['content'=>'Profond']);for($i=0;$i<12;$i++)$nested=$b('section',[],[$nested]);$s=$base;$s['content']=serialize_blocks([$nested]);$assert(is_wp_error(SDR_Email_Renderer::validate($s)),'Limite imbrication');
 $s=$base;$s['content']=str_repeat(' ',100001);$assert(is_wp_error(SDR_Email_Renderer::validate($s)),'Limite taille source');
 $s=$base;$s['style']['width']=INF;$assert(is_wp_error(SDR_Email_Renderer::validate($s)),'Dimension infinie refusée');
 $s=$base;$s['style']['color']='#123456';$r=SDR_Email_Renderer::render($s,$ctx);$assert(!is_wp_error($r)&&str_contains($r['html'],'color:#123456')&&str_contains($r['html'],'color:inherit'),'Couleur générale héritée par les textes sans surcharge');
 $s=$base;$s['subject']="Test\r\nBcc: autre@example.org";$r=SDR_Email_Renderer::render($s,$ctx);$assert(!str_contains($r['subject'],"\n")&&!str_contains($r['subject'],"\r"),'Objet sans injection entêtes');
 $s=$base;$s['content']=serialize_blocks([$b('image',['id'=>99999999]),...parse_blocks($base['content'])]);$r=SDR_Email_Renderer::render($s,$ctx);$assert(!is_wp_error($r)&&count($r['warnings'])>1,'Image supprimée masquée sans casser le mail');
 $s=$base;$s['content']=serialize_blocks([$b('text',['content'=>'Encore du texte']),$b('condition',['rules'=>['needs_dj']],[$b('button',['target'=>'reply_yes']),$b('button',['target'=>'reply_no'])])]);$assert(!is_wp_error(SDR_Email_Renderer::validate($s))&&is_wp_error(SDR_Email_Renderer::render($s,$ctx)),'Actions présentes mais cachées refusées dans cette situation');
 $s['kind']='message';$s['content']=serialize_blocks([$b('text',['content'=>'Texte']),$b('condition',['rules'=>['needs_dj']],[$b('message')])]);$assert(is_wp_error(SDR_Email_Renderer::render($s,SDR_Email_Context::demo('message'))),'Texte libre masqué refusé');
 $s=$base;$s['content']=serialize_blocks([$b('columns',[],[$b('column',[],[$b('text',['content'=>'COLONNE 1'])]),$b('column',[],[$b('text',['content'=>'COLONNE 2'])])]),...parse_blocks($base['content'])]);$r=SDR_Email_Renderer::render($s,$ctx);$assert(!is_wp_error($r)&&str_contains($r['html'],'COLONNE 2')&&str_contains($r['html'],'@media'),'Colonnes et adaptation mobile');
 $endpoint='/wp/v2/'.SDR_Email_Templates::BASE.'/'.$id;
 $bad_content=serialize_blocks([$b('text',['content'=>'{{inconnu}}'])]);
 $assert($rest('POST',$endpoint,['content'=>$bad_content])->get_status()===400,'Publication invalide bloquée');
 $assert($rest('POST',$endpoint,['status'=>'draft','content'=>$bad_content])->get_status()===200,'Brouillon incomplet autorisé');
 $assert($rest('POST','/sdr/v1/email-templates/activate',['template'=>$id])->get_status()===400,'Brouillon non activable');
 $assert($rest('POST',$endpoint,['status'=>'publish','content'=>$base['content'],'meta'=>['_sdr_email_subject'=>'VERSION A '.$base['subject']]])->get_status()===200,'Enregistrement Gutenberg valide');
 $assert($rest('POST','/sdr/v1/email-templates/activate',['template'=>$id])->get_status()===200,'Activation explicite');
 $assert((int)get_option(SDR_Email_Templates::ACTIVE)['reminder']===$id,'Modèle choisi sauvegardé');
 $assert(!array_diff(['_sdr_email_kind','_sdr_email_subject','_sdr_email_preheader','_sdr_email_style'],wp_post_revision_meta_keys(SDR_Email_Templates::TYPE)),'Objet, type et présentation révisables');
 $revision=wp_save_post_revision($id);if(!$revision)$revision=array_key_first(wp_get_post_revisions($id));$assert((bool)$revision,'Révision créée');update_post_meta($id,'_sdr_email_subject','Objet temporaire');update_post_meta($id,'_sdr_email_style',['background'=>'#000000']);wp_restore_post_revision($revision);$assert(get_post_meta($id,'_sdr_email_subject',true)==='VERSION A '.$base['subject']&&get_post_meta($id,'_sdr_email_style',true)['background']==='#eee9de','Restauration révision de l’objet et de la présentation');
 $test=$request+['requestId'=>'email-test-unique-123456789','to'=>'personne-exterieure@example.org'];$count=count($mails);
 $assert($rest('POST','/sdr/v1/email-templates/test',$test)->get_status()===200&&count($mails)===$count+1,'Un aperçu transmis');
 $assert($mails[$count]['to']===get_userdata($users[0])->user_email&&!str_contains($mails[$count]['message'],'sdr-reponse'),'Test uniquement à soi, sans pouvoir répondre');
 $rest('POST','/sdr/v1/email-templates/test',$test);$assert(count($mails)===$count+1,'Double clic test dédoublonné');
 set_transient('sdr_email_test_rate_'.$users[0],10,HOUR_IN_SECONDS);$test['requestId']='email-test-rate-123456789';$assert($rest('POST','/sdr/v1/email-templates/test',$test)->get_status()===429,'Limite essais');delete_transient('sdr_email_test_rate_'.$users[0]);
 $fail=true;$test['requestId']='email-test-failure-123456789';$assert($rest('POST','/sdr/v1/email-templates/test',$test)->get_status()===502,'Échec de transport signalé');$fail=false;$assert($rest('POST','/sdr/v1/email-templates/test',$test)->get_status()===200,'Essai refusé peut être repris');
 // A complete batch followed by editing the active template: every recipient retains A.
 $recipients=array_slice($users,2);$root='/sdr/v1/events/'.$event.'/reminders';$c=$rest('POST',$root,['requestId'=>'email-freeze-campaign-12345','recipients'=>$recipients])->get_data();$campaigns[]=$c['id'];
 $r=$rest('POST',$root.'/'.$c['id'].'/send')->get_data();$assert($r['accepted']===5&&$r['pending']===2,'Première tranche cinq destinataires');
 update_post_meta($id,'_sdr_email_subject','VERSION B '.$base['subject']);$count=count($mails);$r=$rest('POST',$root.'/'.$c['id'].'/send')->get_data();$assert($r['accepted']===7&&$r['pending']===0,'Campagne terminée');$assert(str_starts_with($mails[$count]['subject'],'VERSION A '),'Modèle figé pendant la campagne');
 $assert(str_starts_with(SDR_Email_Templates::snapshot('reminder')['subject'],'VERSION B '),'Nouvelle campagne utilise modification');
 wp_trash_post($id);$assert(SDR_Email_Templates::snapshot('reminder')['id']===0,'Modèle à la corbeille : secours');wp_untrash_post($id);wp_update_post(['ID'=>$id,'post_status'=>'publish']);
 update_post_meta($id,'_sdr_email_subject','{{inconnu}}');$assert(SDR_Email_Templates::snapshot('reminder')['id']===0,'Modèle corrompu : secours');update_post_meta($id,'_sdr_email_subject','VERSION B '.$base['subject']);
 $s=$base;$s['content']=serialize_blocks([$b('text',['content'=>'Texte']),$b('condition',['rules'=>['needs_dj']],[$b('button',['target'=>'reply_yes']),$b('button',['target'=>'reply_no'])])]);
 $r=SDR_Email_Templates::render_kind('reminder',$event,get_userdata($users[2]),['yes'=>'https://example.org/yes','no'=>'https://example.org/no'],$s);$assert(!is_wp_error($r)&&$r['template']===0&&count($r['warnings'])>0,'Secours si conditions cachent une action à l’envoi');
 // Shared wrapper preserves free messages and their own variable system.
 SDR_Email_Templates::activate(['template'=>$message_id]);$free=['event'=>$event,'subject'=>'Bonjour {{prenom}}','body'=>'<p>Contenu libre {{prenom}} — J-{{jours_avant_spectacle}}</p>','template'=>SDR_Email_Templates::snapshot('message')];
 update_post_meta($message_id,'_sdr_email_subject','LIBRE B {{objet_message}}');$r=SDR_Relances::render($free,get_userdata($users[2]));$assert(str_starts_with($r['subject'],'LIBRE A Bonjour')&&str_contains($r['html'],'Contenu libre Éloïse &amp; Alice')&&str_contains($r['plain'],'J-21'),'Enveloppe libre figée, variables existantes conservées');
 // A free campaign also stores its full wrapper at preparation.
 $fc=SDR_Relances::create_campaign(['subject'=>'Essai {{prenom}}','body'=>'<p>MESSAGE DE CAMPAGNE</p>','event'=>$event,'search'=>$prefix,'recipients'=>[$users[7],$users[8]]]);$assert(!is_wp_error($fc),'Campagne libre préparée');$free_campaigns[]=$fc['id'];update_post_meta($message_id,'_sdr_email_subject','LIBRE C {{objet_message}}');$count=count($mails);$r=SDR_Relances::send_batch(['id'=>$fc['id']]);$assert($r['accepted']===2&&str_starts_with($mails[$count]['subject'],'LIBRE B '),'Campagne libre utilise son enveloppe initiale');
 // Automatic announcements persist the template even when transport refuses one recipient.
 $announcement_id=$make('announcement','ANNONCE A ');SDR_Email_Templates::activate(['template'=>$announcement_id]);
 $automatic=wp_insert_post(['post_type'=>'sdr_event','post_status'=>'publish','post_title'=>'Essai annonce automatique','meta_input'=>['_sdr_date'=>$date]]);$posts[]=$automatic;update_post_meta($automatic,'_sdr_date_announcement_pending',true);
 $restrict=static function($query)use($users){$query->set('include',[$users[2],$users[3]]);};add_action('pre_get_users',$restrict);
 $fail=true;$r=SDR_Event_Reminders::automatic_announcement($automatic);$assert($r['failed']===2&&get_post_meta($automatic,'_sdr_date_announcement_template',true)['id']===$announcement_id,'Modèle annonce sauvegardé avant transport');$fail=false;update_post_meta($announcement_id,'_sdr_email_subject','ANNONCE B {{spectacle}}');$count=count($mails);$r=SDR_Event_Reminders::automatic_announcement($automatic);$assert($r['sent']===2&&str_starts_with($mails[$count]['subject'],'ANNONCE A '),'Annonce automatique reprise avec même modèle');remove_action('pre_get_users',$restrict);$restrict=null;
 // Team confirmations, retry after refusal, duplicate protection, interrupted delivery.
 SDR_Email_Templates::activate(['template'=>$team_id]);
 foreach(array_slice($users,2,2) as $uid){SDR_Coulisses::save_response($event,$uid,['availability'=>'yes','roles'=>['mc']]);$wpdb->update($wpdb->prefix.'sdr_responses',['selected_role'=>'mc'],['event_id'=>$event,'user_id'=>$uid]);}
 $final='/sdr/v1/events/'.$event.'/finalize';$count=count($mails);$r=$rest('POST',$final,['notify'=>false])->get_data();$assert($r['silent']&&count($mails)===$count,'Finalisation silencieuse');
 $fail=true;$r=$rest('POST',$final,['notify'=>true])->get_data();$assert($r['failed']===2&&$r['notified']===0,'Refus notifications signalé');$fail=false;update_post_meta($team_id,'_sdr_email_subject','ÉQUIPE B {{date}}');$count=count($mails);$r=$rest('POST',$final,['notify'=>true])->get_data();$assert($r['notified']===2&&str_starts_with($mails[$count]['subject'],'ÉQUIPE A '),'Reprise équipe avec même modèle');$assert(str_contains($mails[$count]['message'],'MC')&&!str_contains($mails[$count]['message'],'sdr-reponse'),'Rôle retenu, aucune invitation superflue');
 $count=count($mails);$r=$rest('POST',$final,['notify'=>true])->get_data();$assert($r['alreadyNotified']&&count($mails)===$count,'Équipe déjà informée sans doublon');
 $state=get_post_meta($event,'_sdr_team_notification_state',true);$state['sent']=[$users[2]];$state['processing']=[$users[3]];update_post_meta($event,'_sdr_team_notification_state',wp_slash($state));$r=$rest('POST',$final,['notify'=>true])->get_data();$assert($r['uncertain']===1&&count($mails)===$count,'Transmission interrompue ne renvoie rien automatiquement');
 $assert(!is_wp_error(SDR_Email_Renderer::validate(SDR_Email_Templates::snapshot('team'))),'JSON de modèle intact après stockage imbriqué');
 WP_CLI::success($checks.' vérifications réussies. Tous les emails interceptés.');
}finally{
 if($restrict)remove_action('pre_get_users',$restrict);foreach($free_campaigns as $c)delete_option('sdr_relances_campaign_'.$c);
 update_option(SDR_Email_Templates::ACTIVE,$active,false);foreach($campaigns as $c)delete_option(SDR_Event_Reminders::PREFIX.$c);
 foreach($posts as $id){$wpdb->delete($wpdb->prefix.'sdr_responses',['event_id'=>$id]);wp_clear_scheduled_hook('sdr_notify_new_event',[$id]);wp_delete_post($id,true);}
 foreach($users as $uid){delete_transient('sdr_email_test_rate_'.$uid);foreach(['email-test-unique-123456789','email-test-rate-123456789','email-test-failure-123456789'] as $key)delete_transient('sdr_email_test_'.$uid.'_'.hash('sha256',$key));wp_delete_user($uid);}
 wp_set_current_user($original);remove_filter('pre_wp_mail',$intercept,PHP_INT_MAX);add_action('transition_post_status',['SDR_Coulisses','queue_new_event_email'],10,3);
}
