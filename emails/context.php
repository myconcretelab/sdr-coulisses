<?php
defined('ABSPATH') || exit;

/** Recipient/event data is resolved once, never by executing template code. */
final class SDR_Email_Context {
    static function variables() {
        return [
            'prenom'=>'Prénom', 'nom'=>'Nom de famille', 'nom_affiche'=>'Nom affiché', 'nom_complet'=>'Nom complet', 'email'=>'Adresse email', 'nom_site'=>'Nom du site',
            'spectacle'=>'Titre du spectacle', 'date'=>'Date du spectacle', 'heure'=>'Heure du spectacle', 'rdv'=>'Rendez-vous troupe',
            'date_du_jour'=>'Date du jour', 'date_spectacle'=>'Date du spectacle (alias)', 'jours_avant_spectacle'=>'Jours avant la date (négatif si passée)', 'heure_spectacle'=>'Heure du spectacle (alias)', 'heure_rendez_vous'=>'Rendez-vous troupe (alias)', 'lieu_spectacle'=>'Lieu (alias)',
            'lieu'=>'Lieu', 'adresse'=>'Adresse', 'ville'=>'Ville', 'format'=>'Format', 'troupe'=>'Troupe invitée',
            'roles'=>'Rôles du profil', 'roles_proposes'=>'Rôles proposés dans cet email', 'role_retenu'=>'Rôle retenu',
            'besoins_manquants'=>'Tous les rôles encore recherchés', 'besoins_cibles'=>'Besoins correspondant au destinataire',
            'places_joueur'=>'Places libres de joueur·se', 'places_mc'=>'Places libres de MC', 'places_dj'=>'Places libres de DJ',
            'places_libres'=>'Total de places libres', 'personnes_retenues'=>'Nombre de personnes retenues', 'reponse'=>'Réponse actuelle',
            'expiration'=>'Fin de validité du lien', 'lien_coulisses'=>'Lien vers les Coulisses', 'lien_spectacle'=>'Fiche publique du spectacle',
            'lien_profil'=>'Lien vers le profil', 'lien_billetterie'=>'Billetterie', 'objet_message'=>'Objet du message libre',
        ];
    }
    static function conditions() {
        return [
            'announcement'=>'Annonce d’une nouvelle date', 'reminder'=>'Relance des non-inscrits', 'team'=>'Confirmation d’équipe', 'message'=>'Message libre',
            'has_event'=>'Un spectacle est associé', 'has_needs'=>'Il reste des places', 'team_complete'=>'L’équipe est complète', 'no_capacity'=>'Les besoins ne sont pas définis',
            'needs_joueur'=>'Il manque des joueurs', 'needs_mc'=>'Il manque des MC', 'needs_dj'=>'Il manque des DJ',
            'profile_joueur'=>'Le profil est joueur', 'profile_mc'=>'Le profil est MC', 'profile_dj'=>'Le profil est DJ',
            'invited_joueur'=>'Cet email propose joueur', 'invited_mc'=>'Cet email propose MC', 'invited_dj'=>'Cet email propose DJ',
            'selected_joueur'=>'La personne est retenue comme joueur', 'selected_mc'=>'La personne est retenue comme MC', 'selected_dj'=>'La personne est retenue comme DJ',
            'selected'=>'La personne est sélectionnée', 'has_response'=>'La personne a répondu', 'response_yes'=>'La personne est partante',
            'response_maybe'=>'La personne est disponible si besoin', 'response_no'=>'La personne est indisponible',
            'has_venue'=>'Le lieu est renseigné', 'has_time'=>'L’horaire est renseigné', 'has_call_time'=>'Le rendez-vous est renseigné',
            'has_guest'=>'Une troupe est invitée', 'has_booking'=>'La billetterie est renseignée', 'intermission'=>'Avec entracte',
            'stage_opt_in'=>'Le profil est OK pour la scène', 'responses_open'=>'Les réponses sont ouvertes', 'can_reply'=>'Les boutons de réponse sont disponibles',
            'has_message'=>'Un message libre est fourni',
        ];
    }
    static function build($kind, $event = 0, $user = null, $extra = []) {
        $post = $event instanceof WP_Post ? $event : ($event ? get_post($event) : null);
        if ($event && (!$post || $post->post_type !== 'sdr_event')) return new WP_Error('sdr_email_event', 'Spectacle introuvable.', ['status'=>404]);
        $id = $post ? $post->ID : 0;
        $profile_roles = $user instanceof WP_User ? SDR_Event_Reminders::roles($user->ID) : ['joueur','mc','dj'];
        $profile_roles = $extra['demo_roles'] ?? $profile_roles;
        $needs = $id ? SDR_Event_Reminders::needs($id) : [];
        $capacity = $id ? array_sum(array_map(static fn($r)=>(int)SDR_Coulisses::meta($id,'capacity_'.$r,0),array_keys(SDR_Coulisses::ROLES))) : 0;
        $response = $id && $user instanceof WP_User ? SDR_Event_Reminders::response($id,$user->ID) : null;
        $selected_role = $extra['selected_role'] ?? ($response->selected_role ?? '');
        $roles = $extra['roles'] ?? ($kind === 'reminder' ? array_values(array_intersect($profile_roles,array_keys($needs))) : $profile_roles);
        $details = $post ? SDR_Coulisses::event_email_details($post) : ['title'=>'','date'=>'','venue'=>'','time'=>'','callTime'=>'','team'=>'','url'=>''];
        $data = $post ? SDR_Coulisses::event_data($post) : [];
        $open = $post && !SDR_Event_Reminders::event_error($id);
        $name = $user instanceof WP_User ? SDR_Coulisses::email_name($user) : 'Camille';
        $vars = array_fill_keys(array_keys(self::variables()),'');
        $vars = array_merge($vars,[
            'prenom'=>$name, 'nom'=>$user instanceof WP_User?$user->last_name:'Exemple', 'nom_affiche'=>$user instanceof WP_User?$user->display_name:'Camille Exemple', 'nom_complet'=>$user instanceof WP_User?(trim($user->first_name.' '.$user->last_name)?:$user->display_name):'Camille Exemple', 'email'=>$user instanceof WP_User?$user->user_email:'camille@example.org',
            'nom_site'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES), 'spectacle'=>$details['title'], 'date'=>$details['date'],
            'heure'=>!empty($data['time'])?$details['time']:'', 'rdv'=>!empty($data['callTime'])?$details['callTime']:'', 'lieu'=>$id&&SDR_Coulisses::meta($id,'venue')?($data['venue']??''):'', 'adresse'=>$data['address']??'', 'ville'=>$data['city']??'',
            'format'=>$id&&SDR_Coulisses::meta($id,'format')?($data['format']??''):'', 'troupe'=>$details['team']??'',
            'roles'=>self::role_names($profile_roles), 'roles_proposes'=>self::role_names($roles), 'role_retenu'=>SDR_Coulisses::ROLES[$selected_role]??'',
            'besoins_manquants'=>SDR_Event_Reminders::need_summary($needs), 'besoins_cibles'=>SDR_Event_Reminders::need_summary(array_intersect_key($needs,array_flip($roles))),
            'places_joueur'=>(string)($needs['joueur']??0), 'places_mc'=>(string)($needs['mc']??0), 'places_dj'=>(string)($needs['dj']??0), 'places_libres'=>(string)array_sum($needs),
            'personnes_retenues'=>(string)max(0,$capacity-array_sum($needs)), 'reponse'=>['yes'=>'Partant·e','maybe'=>'Si besoin','no'=>'Pas disponible'][$response->availability??'']??'',
            'expiration'=>!empty($extra['expires'])?wp_date('d/m/Y',(int)$extra['expires']):'',
            'lien_coulisses'=>home_url('/coulisses/'.($id?'?spectacle='.$id:'')), 'lien_spectacle'=>$post?get_permalink($post):'', 'lien_profil'=>home_url('/coulisses/?vue=profil'),
            'lien_billetterie'=>$id?SDR_Coulisses::meta($id,'booking_url'):'', 'objet_message'=>$extra['subject']??'',
        ]);
        $date=$id?SDR_Coulisses::meta($id,'date'):'';
        $vars=array_merge($vars,['date_du_jour'=>wp_date(get_option('date_format')),'date_spectacle'=>$vars['date'],'heure_spectacle'=>$vars['heure'],'heure_rendez_vous'=>$vars['rdv'],'lieu_spectacle'=>$vars['lieu'],'jours_avant_spectacle'=>$date&&SDR_Coulisses::valid_date($date)?(new DateTimeImmutable('today',wp_timezone()))->diff(new DateTimeImmutable($date,wp_timezone()))->format('%r%a'):'']);
        $conditions = array_fill_keys(array_keys(self::conditions()),false);
        $conditions[$kind] = true;
        $conditions = array_merge($conditions,[
            'has_event'=>(bool)$id, 'has_needs'=>(bool)$needs, 'team_complete'=>$capacity>0&&!$needs, 'no_capacity'=>$capacity===0,
            'selected'=>$selected_role!=='', 'has_response'=>(bool)$response, 'response_yes'=>($response->availability??'')==='yes',
            'response_maybe'=>($response->availability??'')==='maybe', 'response_no'=>($response->availability??'')==='no',
            'has_venue'=>$id&&(bool)SDR_Coulisses::meta($id,'venue'), 'has_time'=>!empty($data['time']), 'has_call_time'=>!empty($data['callTime']),
            'has_guest'=>$id&&(bool)SDR_Coulisses::meta($id,'team'), 'has_booking'=>!empty($vars['lien_billetterie']), 'intermission'=>!empty($data['intermission']),
            'stage_opt_in'=>$user instanceof WP_User?(bool)get_user_meta($user->ID,'sdr_stage_opt_in',true):true,
            'responses_open'=>$open, 'can_reply'=>in_array($kind,['reminder','announcement'],true)&&$open&&!$response&&(bool)$roles&&(!$user||($user instanceof WP_User&&user_can($user,'sdr_member')&&get_user_meta($user->ID,'sdr_stage_opt_in',true)&&!get_user_meta($user->ID,'sdr_demo',true)&&SDR_Event_Reminders::personal_email($user))),
            'has_message'=>trim(wp_strip_all_tags($extra['message']??''))!=='',
        ]);
        foreach (SDR_Coulisses::ROLES as $role=>$label) {
            $conditions['selected_'.$role]=$selected_role===$role;
            $conditions['needs_'.$role]=!empty($needs[$role]);
            $conditions['profile_'.$role]=in_array($role,$profile_roles,true);
            $conditions['invited_'.$role]=in_array($role,$roles,true);
        }
        return ['kind'=>$kind,'vars'=>$vars,'conditions'=>$conditions,'needs'=>$needs,'roles'=>$roles,'preview'=>!empty($extra['preview']),
            'yes'=>$extra['yes']??'', 'no'=>$extra['no']??'', 'message'=>wp_kses_post($extra['message']??''), 'warnings'=>[]];
    }
    static function role_names($roles) { return implode(' · ',array_map(static fn($role)=>SDR_Coulisses::ROLES[$role]??'', $roles)); }
    static function demo($kind, $scenario = 'missing_mc') {
        $context=self::build($kind,0,null,['preview'=>true,'message'=>'<p>Voici un exemple de message libre, personnalisé pour cette personne.</p>','subject'=>'Un petit mot de la troupe']);
        $context['vars']=array_merge($context['vars'],['spectacle'=>'SDR × La troupe invitée','date'=>'Samedi 14 novembre 2026','heure'=>'20h30','rdv'=>'19h30','lieu'=>'Le Champ commun','adresse'=>'1 rue du Clos Bily','ville'=>'Augan','format'=>'Rencontre improvisée','troupe'=>'La troupe invitée','expiration'=>'28/10/2026','lien_spectacle'=>home_url('/'),'lien_billetterie'=>home_url('/'),'personnes_retenues'=>'5']);
        $context['conditions']=array_merge($context['conditions'],['has_event'=>true,'has_venue'=>true,'has_time'=>true,'has_call_time'=>true,'has_guest'=>true,'has_booking'=>true,'no_capacity'=>false,'responses_open'=>true,'stage_opt_in'=>true]);
        $needs=$scenario==='multiple'?['joueur'=>2,'mc'=>1,'dj'=>1]:['mc'=>1];
        if(in_array($scenario,['complete','no_capacity'],true))$needs=[];
        $roles=$kind==='reminder'?array_keys($needs):['joueur','mc','dj'];
        if($scenario==='no_roles')$roles=[];
        $context['roles']=$roles;$context['needs']=$needs;
        foreach(SDR_Coulisses::ROLES as $role=>$label){$context['vars']['places_'.$role]=(string)($needs[$role]??0);$context['conditions']['needs_'.$role]=!empty($needs[$role]);$context['conditions']['invited_'.$role]=in_array($role,$roles,true);$context['conditions']['profile_'.$role]=$scenario!=='no_roles';}
        $context['vars']['roles']=$scenario==='no_roles'?'':self::role_names(['joueur','mc','dj']);$context['vars']['roles_proposes']=self::role_names($roles);
        $context['vars']['besoins_manquants']=SDR_Event_Reminders::need_summary($needs);$context['vars']['besoins_cibles']=SDR_Event_Reminders::need_summary(array_intersect_key($needs,array_flip($roles)));$context['vars']['places_libres']=(string)array_sum($needs);
        $context['conditions']['has_needs']=(bool)$needs;$context['conditions']['team_complete']=$scenario==='complete';$context['conditions']['no_capacity']=$scenario==='no_capacity';
        if($kind==='team'){$context['vars']['role_retenu']='MC';$context['conditions']['selected']=true;$context['conditions']['selected_mc']=true;$context['conditions']['has_response']=true;}
        if($scenario==='missing_details'){foreach(['lieu','adresse','ville','heure','rdv','troupe','lien_billetterie'] as $key)$context['vars'][$key]='';foreach(['has_venue','has_time','has_call_time','has_guest','has_booking'] as $key)$context['conditions'][$key]=false;}
        $context['vars']=array_merge($context['vars'],['date_spectacle'=>$context['vars']['date'],'heure_spectacle'=>$context['vars']['heure'],'heure_rendez_vous'=>$context['vars']['rdv'],'lieu_spectacle'=>$context['vars']['lieu'],'jours_avant_spectacle'=>'21']);
        if($scenario==='closed')$context['conditions']['responses_open']=false;
        if($scenario==='optout')$context['conditions']['stage_opt_in']=false;
        $context['conditions']['can_reply']=in_array($kind,['reminder','announcement'],true)&&$context['conditions']['responses_open']&&$context['conditions']['stage_opt_in']&&(bool)$roles;
        $context['warnings'][]='Données fictives : cet aperçu ne crée aucun lien de réponse utilisable.';
        return $context;
    }
}
