<?php
/** Local WordPress only. All HTTP calls and email are intercepted. */
if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type()!=='local' && !str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'.local'))) throw new RuntimeException('Installation locale uniquement.');
if (SDR_Updates::managed()) throw new RuntimeException('Exécuter avec le ZIP installé sur une copie locale sans Git.');
require_once ABSPATH.'wp-admin/includes/plugin-install.php';
require_once ABSPATH.'wp-admin/includes/user.php';
$original=get_current_user_id(); $setting=get_option(SDR_Updates::OPTION,null); $cache=get_transient(SDR_Updates::CACHE);
$updates=get_site_transient('update_plugins'); $plugin_cache=wp_cache_get('plugins','plugins');
$checks=0; $calls=[]; $users=[]; $files=[]; $scenario='valid'; $mails=0;
$assert=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$version='99.0.0'; $package=SDR_Updates::REPOSITORY.'/releases/download/v'.$version.'/coulisse-'.$version.'.zip';
$bytes='verified test archive bytes';
$manifest=['version'=>$version,'filename'=>'coulisse-'.$version.'.zip','sha256'=>hash('sha256',$bytes),'requires_wordpress'=>'6.5','requires_php'=>'8.1'];
$release=['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'body'=>'<script>bad()</script> Version stable.', 'assets'=>[
    ['name'=>$manifest['filename'],'state'=>'uploaded','browser_download_url'=>$package,'digest'=>'sha256:'.$manifest['sha256']],
    ['name'=>'release.json','state'=>'uploaded','browser_download_url'=>dirname($package).'/release.json']]];
$mail=static function()use(&$mails){$mails++;return true;};add_filter('pre_wp_mail',$mail,PHP_INT_MAX);
$http=static function($pre,$args,$url)use(&$calls,&$scenario,$manifest,$release,$package,$bytes){
    $calls[]=[$url,$args];
    if(str_starts_with($url,'https://api.wordpress.org/plugins/update-check/')) return ['response'=>['code'=>200],'body'=>wp_json_encode(['plugins'=>[],'no_update'=>[],'translations'=>[]])];
    if($url===SDR_Updates::API){
        if($scenario==='timeout')return new WP_Error('http_request_failed','PRIVATE HOST PATH ERROR');
        if($scenario==='rate')return ['response'=>['code'=>403],'body'=>'private details'];
        if($scenario==='empty')return ['response'=>['code'=>404],'body'=>''];
        $data=$release;
        if($scenario==='draft')$data['draft']=true;
        if($scenario==='prerelease')$data['prerelease']=true;
        if($scenario==='tag')$data['tag_name']='v99.0.0-beta';
        if($scenario==='asset')$data['assets'][0]['browser_download_url']='https://other.example.com/plugin.zip';
        if($scenario==='missing')array_pop($data['assets']);
        if($scenario==='digest')$data['assets'][0]['digest']='sha256:'.str_repeat('0',64);
        return ['response'=>['code'=>200],'body'=>$scenario==='json'?'broken':wp_json_encode($data)];
    }
    if($url===dirname($package).'/release.json'){
        $data=$manifest;
        if($scenario==='manifest_version')$data['version']='98.0.0';
        if($scenario==='manifest_hash')$data['sha256']='nope';
        if($scenario==='manifest_requires')$data['requires_php']=[];
        return ['response'=>['code'=>200],'body'=>wp_json_encode($data)];
    }
    if($url===$package && !empty($args['stream'])){
        file_put_contents($args['filename'],$scenario==='corrupt'?'corrupted':$bytes);
        return ['response'=>['code'=>200],'body'=>'','filename'=>$args['filename']];
    }
    return new WP_Error('unexpected_http','Unexpected HTTP blocked in test: '.$url);
};add_filter('pre_http_request',$http,PHP_INT_MAX,3);
try {
    foreach(['administrator','sdr_organizer','sdr_member'] as $role){$login='updates-'.wp_generate_password(10,false,false);$id=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(40),'user_email'=>$login.'@example.invalid','role'=>$role]);if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$users[]=$id;}
    wp_set_current_user($users[0]); delete_option(SDR_Updates::OPTION);delete_transient(SDR_Updates::CACHE);
    $assert(!SDR_Updates::enabled(),'Désactivé au départ');
    $assert(SDR_Updates::release()['state']==='disabled' && !$calls,'Aucun appel externe sans activation');
    ob_start();SDR_Updates::render();$html=ob_get_clean();
    $assert(str_contains($html,'Vérifier les nouvelles versions sur GitHub')&&!str_contains($html,'Vérifier maintenant'),'Réglage visible sans lancer une recherche');
    $assert(!str_contains($html,' checked=') && !$calls,'Consentement non précoché, affichage sans HTTP');
    $assert(SDR_Updates::allowed(),'Administrateur autorisé');
    foreach(array_merge(array_slice($users,1),[0]) as $uid){wp_set_current_user($uid);$assert(!SDR_Updates::allowed(),'Autres rôles refusés');ob_start();SDR_Updates::render();$html=ob_get_clean();$assert($html==='','Pas de réglage pour les autres rôles');}
    wp_set_current_user($users[0]); update_option(SDR_Updates::OPTION,true);
    $r=SDR_Updates::release();$assert($r['state']==='ready' && $r['version']===$version,'Version stable reconnue');
    $assert(count($calls)===2,'Une lecture du dépôt et une du manifeste');
    $assert($r['requires']==='6.5'&&$r['requires_php']==='8.1','Prérequis lus depuis le manifeste');
    $count=count($calls);SDR_Updates::release();$assert(count($calls)===$count,'Cache réutilisé');
    foreach($calls as [$url,$args]){$assert(!str_contains(wp_json_encode($args),home_url())&&!isset($args['body']),'Contrôle sans URL du site ni données de membres');$assert(str_starts_with($args['user-agent'],'Coulisse-Updater/'),'Agent sans adresse du site');}
    $plugin=['UpdateURI'=>SDR_Updates::REPOSITORY,'Version'=>'1.14.0'];
    $assert(SDR_Updates::update('keep',$plugin,'other/plugin.php')==='keep','Autres extensions préservées');
    $assert(SDR_Updates::update('keep',['UpdateURI'=>'https://example.com'],SDR_Updates::file())==='keep','Autre origine préservée');
    $r=SDR_Updates::update(false,$plugin,SDR_Updates::file());
    $assert($r['version']===$version&&$r['package']===$package&&!isset($r['autoupdate']),'Réponse native sans activer l’installation automatique');
    $info=plugins_api('plugin_information',(object)['slug'=>'sdr-coulisses']);
    $assert($info->version===$version&&$info->download_link===$package,'Détails dans le dialogue WordPress');
    $assert(!str_contains($info->sections['changelog'],'<script>')&&str_contains($info->sections['changelog'],'&lt;script&gt;'),'Notes de version échappées');
    $assert(SDR_Updates::information('keep','query_plugins',(object)['slug'=>'sdr-coulisses'])==='keep','Autres recherches WordPress préservées');
    $assert(SDR_Updates::information('keep','plugin_information',(object)['slug'=>'another'])==='keep','Autres détails préservés');
    delete_site_transient('update_plugins'); wp_update_plugins();$native=get_site_transient('update_plugins');
    $assert(isset($native->response[SDR_Updates::file()])&&$native->response[SDR_Updates::file()]->new_version===$version,'WordPress propose la version dans son vrai contrôle de mises à jour');
    $assert($native->response[SDR_Updates::file()]->requires_php==='8.1','Compatibilité PHP portée dans le contrôle natif');
    $assert(SDR_Updates::download('keep',$package,null,['plugin'=>SDR_Updates::file()])==='keep','Résultat antérieur conservé');
    $assert(SDR_Updates::download(false,$package,null,['plugin'=>'other/plugin.php'])===false,'Téléchargements des autres extensions préservés');
    $assert(SDR_Updates::download(false,'/local/upload.zip',null,['plugin'=>SDR_Updates::file()])===false,'Installation manuelle du ZIP conservée');
    $path=SDR_Updates::download(false,$package,null,['plugin'=>SDR_Updates::file()]);
    $assert(is_string($path)&&file_get_contents($path)===$bytes,'Téléchargement vérifié avant installation');$files[]=$path;
    $scenario='corrupt';$r=SDR_Updates::download(false,$package,null,['plugin'=>SDR_Updates::file()]);
    $assert(is_wp_error($r)&&$r->get_error_code()==='sdr_update_checksum','Archive corrompue refusée');
    $assert(is_wp_error(SDR_Updates::download(false,str_replace('99.0.0','98.0.0',$package),null,['plugin'=>SDR_Updates::file()])),'Autre version non vérifiée refusée');
    foreach(['timeout','rate','draft','prerelease','tag','asset','missing','digest','json','manifest_version','manifest_hash','manifest_requires'] as $scenario){
        delete_transient(SDR_Updates::CACHE);$r=SDR_Updates::release();$assert($r['state']==='error','Publication incorrecte refusée : '.$scenario);
        $assert(!str_contains(wp_json_encode($r),'PRIVATE'),'Erreur sans information interne');
        $assert(SDR_Updates::update(false,$plugin,SDR_Updates::file())===false,'Pas de proposition en erreur : '.$scenario);
        $count=count($calls);SDR_Updates::release();$assert(count($calls)===$count,'Échec mis en cache : '.$scenario);
    }
    $scenario='empty';delete_transient(SDR_Updates::CACHE);$assert(SDR_Updates::release()['state']==='empty','Absence de publication expliquée');
    $scenario='valid';SDR_Updates::release(true);ob_start();SDR_Updates::render();$html=ob_get_clean();
    $assert(str_contains($html,'Version 99.0.0 disponible.')&&str_contains($html,'Vérifier maintenant')&&str_contains($html,'_wpnonce'),'État, contrôle manuel et protection CSRF visibles');
    $auto_before=get_site_option('auto_update_plugins',[]);update_option(SDR_Updates::OPTION,false);
    $assert(get_site_option('auto_update_plugins',[])===$auto_before,'Préférence native d’installation automatique préservée');
    $assert(get_transient(SDR_Updates::CACHE)===false&&get_site_transient('update_plugins')===false,'Désactivation efface les propositions en cache');
    $count=count($calls);$assert(SDR_Updates::release()['state']==='disabled'&&count($calls)===$count,'Désactivation arrête les contrôles');
    $assert($mails===0,'Aucun email envoyé');
    WP_CLI::success($checks.' vérifications des mises à jour réussies. HTTP et emails interceptés.');
} finally {
    remove_filter('pre_http_request',$http,PHP_INT_MAX);remove_filter('pre_wp_mail',$mail,PHP_INT_MAX);
    foreach($files as $path)if(is_file($path))wp_delete_file($path);
    foreach($users as $id)wp_delete_user($id);
    if($setting===null)delete_option(SDR_Updates::OPTION);else update_option(SDR_Updates::OPTION,$setting,false);
    if($cache===false)delete_transient(SDR_Updates::CACHE);else set_transient(SDR_Updates::CACHE,$cache,6*HOUR_IN_SECONDS);
    if($updates===false)delete_site_transient('update_plugins');else set_site_transient('update_plugins',$updates);
    if($plugin_cache!==false)wp_cache_set('plugins',$plugin_cache,'plugins');
    wp_set_current_user($original);
}
