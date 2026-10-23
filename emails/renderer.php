<?php
defined('ABSPATH') || exit;

/** A small email-only compiler: no shortcodes, PHP, scripts or arbitrary block callbacks. */
final class SDR_Email_Renderer {
    const PREFIX = 'sdr-email/';
    const MAX_BYTES = 90000;
    static function schemas() {
        $text=['content'=>['type'=>'string','default'=>''],'color'=>['type'=>'string','default'=>''],'fontSize'=>['type'=>'number','default'=>16],'align'=>['type'=>'string','default'=>'left']];
        return [
            'text'=>$text,
            'heading'=>array_merge($text,['level'=>['type'=>'number','default'=>1],'fontSize'=>['type'=>'number','default'=>36]]),
            'section'=>['background'=>['type'=>'string','default'=>'#fffdf6'],'padding'=>['type'=>'number','default'=>20],'borderColor'=>['type'=>'string','default'=>'transparent'],'borderWidth'=>['type'=>'number','default'=>0],'radius'=>['type'=>'number','default'=>0]],
            'columns'=>['gap'=>['type'=>'number','default'=>8]],
            'column'=>['background'=>['type'=>'string','default'=>'transparent'],'padding'=>['type'=>'number','default'=>8]],
            'condition'=>['rules'=>['type'=>'array','items'=>['type'=>'string'],'default'=>['has_needs']],'mode'=>['type'=>'string','default'=>'all'],'invert'=>['type'=>'boolean','default'=>false]],
            'image'=>['url'=>['type'=>'string','default'=>''],'id'=>['type'=>'number','default'=>0],'alt'=>['type'=>'string','default'=>''],'width'=>['type'=>'number','default'=>540],'align'=>['type'=>'string','default'=>'center']],
            'button'=>['label'=>['type'=>'string','default'=>'Ouvrir les Coulisses'],'target'=>['type'=>'string','default'=>'coulisses'],'url'=>['type'=>'string','default'=>''],'background'=>['type'=>'string','default'=>'#dce99f'],'color'=>['type'=>'string','default'=>'#30391c'],'fontSize'=>['type'=>'number','default'=>17],'radius'=>['type'=>'number','default'=>8],'align'=>['type'=>'string','default'=>'center']],
            'event'=>['fields'=>['type'=>'array','items'=>['type'=>'string'],'default'=>['spectacle','date','lieu','heure','rdv']],'background'=>['type'=>'string','default'=>'#eee7f4']],
            'needs'=>['scope'=>['type'=>'string','default'=>'recipient'],'background'=>['type'=>'string','default'=>'#edf0da']],
            'message'=>[], 'reply-note'=>[],
            'divider'=>['color'=>['type'=>'string','default'=>'#cfc3b1']],
            'spacer'=>['height'=>['type'=>'number','default'=>16]],
        ];
    }
    static function error($message) { return new WP_Error('sdr_email_template',$message,['status'=>400]); }
    static function attrs($name,$attrs) {
        $defaults=[];foreach(self::schemas()[$name]??[] as $key=>$schema)$defaults[$key]=$schema['default'];
        return array_merge($defaults,array_intersect_key($attrs,$defaults));
    }
    static function valid_color($value) { return is_string($value)&&preg_match('/^(?:#[0-9a-f]{3}|#[0-9a-f]{6}|transparent)$/iD',$value); }
    static function validate($snapshot) {
        if(!is_array($snapshot)||!is_string($snapshot['kind']??null)||!isset(SDR_Email_Templates::kinds()[$snapshot['kind']]))return self::error('Le type d’email est invalide.');
        foreach(['content','subject','preheader'] as $key)if(!is_string($snapshot[$key]??null))return self::error('Le modèle est incomplet.');
        if(strlen($snapshot['content'])>100000||mb_strlen($snapshot['subject'])>400||mb_strlen($snapshot['preheader'])>400)return self::error('Le modèle ou son objet est trop long.');
        if(trim($snapshot['subject'])==='')return self::error('Renseigne l’objet de l’email.');
        $style=$snapshot['style']??[];
        if(!is_array($style))return self::error('La présentation du modèle est invalide.');
        foreach(['background','surface','color'] as $key)if(isset($style[$key])&&!self::valid_color($style[$key]))return self::error('Une couleur du modèle est invalide.');
        foreach(['width'=>[320,800],'padding'=>[0,48]] as $key=>$range)if(isset($style[$key])&&(!is_numeric($style[$key])||!is_finite((float)$style[$key])||$style[$key]<$range[0]||$style[$key]>$range[1]))return self::error('Les dimensions du modèle sont invalides.');
        if(isset($style['font'])&&!in_array($style['font'],['Arial','Georgia','Verdana','Tahoma'],true))return self::error('Choisis une police compatible avec les emails.');
        $count=0;$actions=[];$messages=0;
        $walk=static function($blocks,$depth=0,$parent='')use(&$walk,&$count,&$actions,&$messages){
            if($depth>10)return self::error('Limite de dix niveaux de blocs imbriqués dépassée.');
            foreach($blocks as $block){
                if(++$count>300)return self::error('Limite de 300 blocs dépassée.');
                if(!$block['blockName']){if(trim($block['innerHTML']??'')!=='')return self::error('Utilise les blocs Email pour le contenu ; le HTML libre n’est pas pris en charge.');continue;}
                $name=str_starts_with($block['blockName'],self::PREFIX)?substr($block['blockName'],strlen(self::PREFIX)):'';
                if(!isset(self::schemas()[$name]))return self::error('Bloc non compatible email : '.$block['blockName'].'.');
                if($name==='column'&&$parent!=='columns')return self::error('Un bloc Colonne doit appartenir à un bloc Colonnes.');
                $a=self::attrs($name,$block['attrs']??[]);
                foreach(self::schemas()[$name] as $key=>$schema){
                    $value=$a[$key];$type=$schema['type'];
                    if(($type==='string'&&!is_string($value))||($type==='array'&&(!is_array($value)||count($value)>50||array_filter($value,static fn($v)=>!is_string($v))))||($type==='number'&&(!is_int($value)&&!is_float($value)))||($type==='boolean'&&!is_bool($value)))return self::error('Valeur invalide dans le bloc '.$name.' ('.$key.').');
                    if(in_array($key,['color','background','borderColor'],true)&&!($key==='color'&&in_array($name,['text','heading'],true)&&$value==='')&&!self::valid_color($value))return self::error('Couleur invalide dans le bloc '.$name.'.');
                    $range=['fontSize'=>[10,48],'padding'=>[0,48],'height'=>[0,100],'width'=>[50,800],'id'=>[0,PHP_INT_MAX],'radius'=>[0,30],'borderWidth'=>[0,6],'gap'=>[0,30],'level'=>[1,3]][$key]??null;
                    if($range&&(!is_finite((float)$value)||$value<$range[0]||$value>$range[1]))return self::error('Dimension invalide dans le bloc '.$name.' ('.$key.').');
                    if($key==='align'&&!in_array($value,['left','center','right'],true))return self::error('Alignement invalide.');
                }
                if($name==='condition'&&(!$a['rules']||array_diff($a['rules'],array_keys(SDR_Email_Context::conditions()))||!in_array($a['mode'],['all','any'],true)))return self::error('Choisis des conditions reconnues pour le bloc conditionnel.');
                if($name==='needs'&&!in_array($a['scope'],['recipient','all'],true))return self::error('Portée des besoins invalide.');
                if($name==='event'&&array_diff($a['fields'],['spectacle','date','lieu','adresse','ville','heure','rdv','format','troupe']))return self::error('Champ de spectacle invalide.');
                if($name==='button'){
                    if(!in_array($a['target'],['reply_yes','reply_no','coulisses','profile','event','booking','custom'],true))return self::error('Destination de bouton invalide.');
                    if(trim(wp_strip_all_tags($a['label']))==='')return self::error('Un bouton doit avoir un libellé.');
                    if(in_array($a['target'],['reply_yes','reply_no'],true))$actions[]=$a['target'];
                }
                if($name==='message')$messages++;
                foreach(['content','label','alt','url'] as $field)if(isset($a[$field])){$error=self::validate_variables($a[$field]);if($error)return $error;}
                if($name==='image'&&$a['url']!==''&&!str_contains($a['url'],'{{')&&!self::url($a['url']))return self::error('L’image doit utiliser une adresse http ou https complète.');
                if($name==='button'&&$a['target']==='custom'&&$a['url']!==''&&!str_contains($a['url'],'{{')&&!self::url($a['url']))return self::error('Le bouton doit utiliser une adresse http ou https complète.');
                $children=$block['innerBlocks']??[];
                if($name==='columns'){
                    $columns=array_values(array_filter($children,static fn($b)=>!empty($b['blockName'])));
                    if(count($columns)<1||count($columns)>3||array_filter($columns,static fn($b)=>$b['blockName']!==self::PREFIX.'column'))return self::error('Utilise une à trois colonnes dans le bloc Colonnes.');
                }
                if($children&&!in_array($name,['section','condition','columns','column'],true))return self::error('Ce bloc ne peut pas contenir d’autres blocs.');
                $error=$walk($children,$depth+1,$name);if($error)return $error;
            }
            return null;
        };
        $blocks=parse_blocks($snapshot['content']);$error=$walk($blocks);if($error)return $error;
        if(!array_filter($blocks,static fn($b)=>!empty($b['blockName'])))return self::error('Ajoute du contenu au modèle.');
        foreach(['subject','preheader'] as $key){$error=self::validate_variables($snapshot[$key]);if($error)return $error;}
        if(in_array($snapshot['kind'],['announcement','reminder'],true)&&array_diff(['reply_yes','reply_no'],$actions))return self::error('Ce modèle doit contenir les boutons de réponse « Je me lance » et « Pas disponible ».');
        if(!in_array($snapshot['kind'],['announcement','reminder'],true)&&$actions)return self::error('Les boutons de réponse sont réservés aux annonces et relances de spectacle.');
        if($snapshot['kind']==='message'&&!$messages)return self::error('Ajoute le bloc « Message libre » pour conserver le texte rédigé dans les relances utilisateurs.');
        return true;
    }
    static function validate_variables($text) {
        preg_match_all('/\{\{([^{}]*)\}\}/u',$text,$matches);
        $rest=preg_replace('/\{\{[^{}]*\}\}/u','',$text);
        if(str_contains($rest,'{{')||str_contains($rest,'}}'))return self::error('Une variable est mal fermée : utilise {{variable}}.');
        foreach($matches[1] as $token){$key=trim(explode('|',$token,2)[0]);if(!array_key_exists($key,SDR_Email_Context::variables()))return self::error('Variable inconnue : {{'.$key.'}}.');}
        return null;
    }
    static function replace($text,$ctx,$html=true) {
        return preg_replace_callback('/\{\{([^{}]*)\}\}/u',static function($m)use($ctx,$html){[$key,$fallback]=array_pad(explode('|',$m[1],2),2,'');$value=(string)($ctx['vars'][trim($key)]??'');if($value==='')$value=trim($fallback);return $html?esc_html($value):wp_strip_all_tags($value);},$text);
    }
    static function rich($text,$ctx) {
        return wp_kses(self::replace($text,$ctx),['strong'=>[],'b'=>[],'em'=>[],'i'=>[],'u'=>[],'br'=>[],'code'=>[],'a'=>['href'=>true,'title'=>true]],['http','https','mailto']);
    }
    static function url($value) {
        if(!is_string($value)||strlen($value)>2048||preg_match('/[\x00-\x20]/',$value))return '';
        $scheme=strtolower((string)wp_parse_url($value,PHP_URL_SCHEME));
        return in_array($scheme,['http','https'],true)&&wp_parse_url($value,PHP_URL_HOST)?esc_url_raw($value,['http','https']):'';
    }
    static function condition($attrs,$ctx) {
        $a=self::attrs('condition',$attrs);$matches=array_map(static fn($key)=>!empty($ctx['conditions'][$key]),$a['rules']);
        $value=$a['mode']==='any'?in_array(true,$matches,true):!in_array(false,$matches,true);
        return $a['invert']?!$value:$value;
    }
    static function render($snapshot,$ctx) {
        $valid=self::validate($snapshot);if(is_wp_error($valid))return $valid;
        if(is_wp_error($ctx))return $ctx;
        $state=['actions'=>[],'notes'=>0,'messages'=>0,'warnings'=>$ctx['warnings']??[]];
        $html=self::blocks(parse_blocks($snapshot['content']),$ctx,$state);
        if(!empty($ctx['conditions']['can_reply'])&&array_diff(['reply_yes','reply_no'],$state['actions']))return self::error('Les conditions masquent un bouton de réponse nécessaire dans cette situation.');
        if($snapshot['kind']==='message'&&!empty($ctx['conditions']['has_message'])&&!$state['messages'])return self::error('Les conditions masquent le message libre de ce destinataire.');
        if(trim(wp_strip_all_tags($html))==='')return self::error('Les conditions masquent tout le contenu du message dans cette situation.');
        if(!empty($ctx['conditions']['can_reply'])&&!$state['notes'])$html.=self::note($ctx);
        $style=array_merge(['background'=>'#eee9de','surface'=>'#fffdf6','color'=>'#302e28','font'=>'Arial','width'=>600,'padding'=>24],$snapshot['style']??[]);
        $preheader=self::replace($snapshot['preheader'],$ctx);
        $preview=!empty($ctx['preview'])?'<div style="padding:10px 16px;background:#f9e9c5;color:#5c4216;font:13px/1.5 Arial,sans-serif;text-align:center">APERÇU — Les boutons de réponse ne permettent pas de répondre pour cette personne.</div>':'';
        $document='<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><style>@media(max-width:480px){.sdr-email-column{display:block!important;width:100%!important;box-sizing:border-box!important}.sdr-email-inner{padding:16px!important}img{max-width:100%!important;height:auto!important}}</style></head><body style="margin:0;padding:0;background:'.$style['background'].';color:'.$style['color'].';font-family:'.$style['font'].',sans-serif;line-height:1.6">'.$preview.'<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.$preheader.'</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:'.$style['background'].'"><tr><td align="center" style="padding:24px 10px"><table role="presentation" width="'.(int)$style['width'].'" cellpadding="0" cellspacing="0" style="width:100%;max-width:'.(int)$style['width'].'px;background:'.$style['surface'].';border:1px solid #cfc3b1"><tr><td class="sdr-email-inner" style="padding:'.(int)$style['padding'].'px">'.$html.'</td></tr></table></td></tr></table></body></html>';
        if(strlen($document)>self::MAX_BYTES)return self::error('Le message rendu dépasse 90 Ko. Réduis le nombre de blocs ou la longueur du texte.');
        $subject=preg_replace('/[\x00-\x1f\x7f]+/u',' ',self::replace($snapshot['subject'],$ctx,false));$subject=trim(mb_substr($subject,0,300));
        if($subject==='')return self::error('L’objet est vide dans cette situation. Ajoute un texte de secours.');
        return ['subject'=>$subject,'html'=>$document,'plain'=>self::plain($html),'warnings'=>array_values(array_unique($state['warnings'])),'template'=>$snapshot['id']??0,'templateHash'=>hash('sha256',wp_json_encode($snapshot))];
    }
    static function blocks($blocks,$ctx,&$state) {
        $html='';
        foreach($blocks as $block){
            if(empty($block['blockName']))continue;
            $name=substr($block['blockName'],strlen(self::PREFIX));$a=self::attrs($name,$block['attrs']??[]);$children=$block['innerBlocks']??[];
            switch($name){
                case 'condition': if(self::condition($a,$ctx))$html.=self::blocks($children,$ctx,$state);break;
                case 'text': $html.='<p style="margin:0 0 14px;font-size:'.(int)$a['fontSize'].'px;line-height:1.6;color:'.($a['color']?:'inherit').';text-align:'.$a['align'].'">'.self::rich($a['content'],$ctx).'</p>';break;
                case 'heading': $level=(int)$a['level'];$html.='<h'.$level.' style="margin:10px 0 20px;font-family:Georgia,serif;font-weight:normal;font-size:'.(int)$a['fontSize'].'px;line-height:1.2;color:'.($a['color']?:'inherit').';text-align:'.$a['align'].'">'.self::rich($a['content'],$ctx).'</h'.$level.'>';break;
                case 'section':case 'column':
                    $inner=self::blocks($children,$ctx,$state);$border=$name==='section'?(int)$a['borderWidth'].'px solid '.$a['borderColor']:'0';$radius=$name==='section'?(int)$a['radius']:0;
                    $html.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;background:'.$a['background'].';border:'.$border.';border-radius:'.$radius.'px"><tr><td style="padding:'.(int)$a['padding'].'px">'.$inner.'</td></tr></table>';break;
                case 'columns':
                    $cols=array_values(array_filter($children,static fn($b)=>!empty($b['blockName'])));$width=(int)floor(100/count($cols));$html.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px"><tr>';
                    foreach($cols as $column)$html.='<td class="sdr-email-column" width="'.$width.'%" valign="top" style="padding:'.(int)$a['gap'].'px;width:'.$width.'%">'.self::blocks([$column],$ctx,$state).'</td>';
                    $html.='</tr></table>';break;
                case 'button':
                    if(in_array($a['target'],['reply_yes','reply_no'],true)){
                        if(empty($ctx['conditions']['can_reply']))break;
                        $url=$ctx['preview']?'#apercu-'.$a['target']:self::url($ctx[$a['target']==='reply_yes'?'yes':'no']??'');
                        if(!$url){$state['warnings'][]='Un bouton de réponse n’a pas de lien personnel.';break;}
                        $state['actions'][]=$a['target'];
                    }else{
                        $key=['coulisses'=>'lien_coulisses','profile'=>'lien_profil','event'=>'lien_spectacle','booking'=>'lien_billetterie'][$a['target']]??'';
                        $url=self::url($key?($ctx['vars'][$key]??''):self::replace($a['url'],$ctx,false));
                        if(!$url){$state['warnings'][]='Un bouton sans adresse utilisable a été masqué.';break;}
                    }
                    $html.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 12px"><tr><td align="'.$a['align'].'" bgcolor="'.$a['background'].'" style="background:'.$a['background'].';border:1px solid #b5b997;border-radius:'.(int)$a['radius'].'px"><a href="'.esc_url($url).'"'.($ctx['preview']&&str_starts_with($a['target'],'reply_')?' aria-disabled="true"':'').' style="display:block;padding:16px 18px;font-family:Arial,sans-serif;font-size:'.(int)$a['fontSize'].'px;font-weight:bold;line-height:1.4;text-align:'.$a['align'].';text-decoration:none;color:'.$a['color'].'">'.esc_html(self::replace($a['label'],$ctx,false)).'</a></td></tr></table>';break;
                case 'image':
                    $url=$a['id']?wp_get_attachment_image_url((int)$a['id'],'large'):$a['url'];$url=self::url(self::replace((string)$url,$ctx,false));
                    if(!$url){$state['warnings'][]='Une image absente ou sans adresse utilisable a été masquée.';break;}
                    $html.='<p style="text-align:'.$a['align'].';margin:0 0 16px"><img src="'.esc_url($url).'" width="'.(int)$a['width'].'" alt="'.esc_attr(self::replace($a['alt'],$ctx,false)).'" style="display:inline-block;width:100%;max-width:'.(int)$a['width'].'px;height:auto;border:0"></p>';break;
                case 'event':
                    if(empty($ctx['conditions']['has_event']))break;
                    $html.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:'.$a['background'].';border:1px solid #d7c9e2;margin:14px 0 20px"><tr><td style="padding:18px 20px">';
                    foreach($a['fields'] as $field){$value=$ctx['vars'][$field]??'';if($value==='')$value=['heure'=>'Horaire à préciser','rdv'=>'Rendez-vous à préciser','lieu'=>'Lieu à préciser'][$field]??'';if($value==='')continue;$label=['heure'=>'Spectacle : ','rdv'=>'Rendez-vous troupe : '][$field]??'';$html.='<p style="margin:0 0 8px;font-size:'.($field==='spectacle'?'23':'15').'px;line-height:1.5">'.esc_html($label.$value).'</p>';}
                    $html.='</td></tr></table>';break;
                case 'needs':
                    $needs=$a['scope']==='all'?$ctx['needs']:array_intersect_key($ctx['needs'],array_flip($ctx['roles']));if(!$needs)break;
                    $html.='<p style="padding:14px 16px;margin:12px 0 18px;background:'.$a['background'].';font-size:16px"><strong>Encore à trouver : '.esc_html(SDR_Event_Reminders::need_summary($needs)).'</strong></p>';break;
                case 'message': $html.=$ctx['message'];$state['messages']++;break;
                case 'reply-note': if(!empty($ctx['conditions']['can_reply'])){$html.=self::note($ctx);$state['notes']++;}break;
                case 'divider': $html.='<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="height:16px;border-bottom:1px dashed '.$a['color'].'"></td></tr><tr><td style="height:16px"></td></tr></table>';break;
                case 'spacer': $html.='<div style="height:'.(int)$a['height'].'px;line-height:'.(int)$a['height'].'px;font-size:1px">&#160;</div>';break;
            }
        }
        return $html;
    }
    static function note($ctx) {
        return '<p style="font:12px/1.6 Arial,sans-serif;color:#736b60;margin:16px 0">Un clic ouvre la fiche ; tu confirmes ensuite ta réponse. Une disponibilité n’est pas une sélection : l’organisation compose l’équipe.<br>Ce lien est personnel : ne le transfère pas.'.(!empty($ctx['vars']['expiration'])?' Il est valable jusqu’au '.esc_html($ctx['vars']['expiration']).', tant que les réponses sont ouvertes.':'').'<br>Tu reçois cette invitation car tu as coché « OK pour la scène » dans ton profil.</p>';
    }
    static function plain($html) {
        $html=preg_replace_callback('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',static fn($m)=>wp_strip_all_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES,'UTF-8').')',$html);
        $html=preg_replace('/<(?:br\s*\/?|\/p|\/div|\/h[1-6]|\/tr)>/i',"\n",$html);
        return trim(preg_replace('/\n{3,}/',"\n\n",html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES,'UTF-8')));
    }
}
