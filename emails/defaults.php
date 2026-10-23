<?php
defined('ABSPATH') || exit;

final class SDR_Email_Defaults {
    static function block($name,$attrs=[],$children=[]) {
        return ['blockName'=>SDR_Email_Renderer::PREFIX.$name,'attrs'=>$attrs,'innerBlocks'=>$children,'innerHTML'=>'','innerContent'=>array_fill(0,count($children),null)];
    }
    static function snapshot($kind) {
        $b=[__CLASS__,'block'];
        $badge=['announcement'=>'LA PROCHAINE SORTIE','reminder'=>'LE PETIT RAPPEL','team'=>'L’ÉQUIPE EST PRÊTE','message'=>'UN PETIT MOT DE LA TROUPE'][$kind];
        $blocks=[$b('text',['content'=>'LES SORTIES DE ROUTE ✳ '.$badge,'fontSize'=>12]),$b('divider')];
        if($kind==='announcement'){
            $subject='Nouvelle date : {{spectacle}} — {{date}}';$preheader='Une date, une scène, et peut-être toi. Ta réponse sans mot de passe.';
            $blocks[]=$b('heading',['content'=>'Une nouvelle date.<br>Et si tu en étais ?']);
            $blocks[]=$b('text',['content'=>'Bonjour {{prenom}},']);
            $blocks[]=$b('text',['content'=>'Une nouvelle soirée vient d’arriver au planning. On a la date, on a la scène… il ne manque plus que tes envies !']);
        }elseif($kind==='reminder'){
            $subject='On joue ensemble ? Recherche : {{besoins_cibles|des volontaires}} — {{spectacle}} — {{date}}';$preheader='Il manque encore {{besoins_cibles|du monde}}. Ta réponse sans mot de passe.';
            $blocks[]=$b('heading',['content'=>'Il manque encore<br>{{besoins_cibles|du monde}}','color'=>'#79608f']);
            $blocks[]=$b('text',['content'=>'Bonjour {{prenom}},']);
            $blocks[]=$b('text',['content'=>'Il reste {{besoins_cibles|des places}} à trouver pour cette soirée. Ton profil correspond : ça te dit de rejoindre l’aventure ?']);
        }elseif($kind==='team'){
            $subject='Équipe finalisée : {{date}} — {{nom_site}}';$preheader='Tu fais partie de cette soirée, en tant que {{role_retenu}}.';
            $blocks[]=$b('heading',['content'=>'L’équipe est prête.<br>À toi de jouer !']);
            $blocks[]=$b('text',['content'=>'Bonjour {{prenom}},']);
            $blocks[]=$b('section',['background'=>'#edf0da','padding'=>18],[$b('text',['content'=>'Tu es retenu·e en tant que <strong>{{role_retenu|membre de l’équipe}}</strong>. Voici les informations pour préparer la soirée.'])]);
        }else{
            $subject='{{objet_message}}';$preheader='Un message personnel des Sorties de Route.';
            $blocks[]=$b('message');
        }
        if($kind!=='message')$blocks[]=$b('event');
        if(in_array($kind,['announcement','reminder'],true)){
            $blocks[]=$b('text',['content'=>($kind==='reminder'?'Pour cette relance, on te propose :':'Tes terrains de jeu :').' <strong>{{roles_proposes|à préciser}}</strong>.']);
            $blocks[]=$b('condition',['rules'=>['can_reply']],[$b('button',['label'=>'OK, je me lance ! ↗','target'=>'reply_yes']),$b('button',['label'=>'Je ne suis pas dispo','target'=>'reply_no','background'=>'#f5eee3','color'=>'#5f5547','fontSize'=>16]),$b('reply-note')]);
            $blocks[]=$b('condition',['rules'=>['can_reply'],'invert'=>true],[$b('text',['content'=>'Les réponses par email ne sont pas disponibles dans cette situation. Retrouve les informations du spectacle dans les Coulisses.']),$b('button',['target'=>'coulisses','label'=>'Retrouver les Coulisses ↗'])]);
        }elseif($kind==='team')$blocks[]=$b('button',['target'=>'coulisses','label'=>'Retrouver ma soirée dans les Coulisses ↗']);
        $blocks[]=$b('divider');
        $blocks[]=$b('section',['background'=>'#f1eddf','padding'=>18],[$b('heading',['content'=>'Le meilleur plan ? Ne pas en avoir. ✳','level'=>2,'fontSize'=>22]),$b('text',['content'=>'Sur scène, en tout cas.<br>Les Sorties de Route','fontSize'=>12,'color'=>'#736b60'])]);
        return ['id'=>0,'kind'=>$kind,'content'=>serialize_blocks($blocks),'subject'=>$subject,'preheader'=>$preheader,'style'=>['background'=>'#eee9de','surface'=>'#fffdf6','color'=>'#302e28','font'=>'Arial','width'=>600,'padding'=>24]];
    }
}
