<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>On joue ensemble ?</title></head>
<body style="margin:0;padding:0;background:#eee9de;color:#302e28;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">Une date, une scène, et peut-être toi. Ta réponse sans mot de passe.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eee9de"><tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#fffdf6;border:1px solid #cfc3b1">
<tr><td style="padding:18px 28px;border-bottom:1px dashed #b9ad99;font-size:12px;letter-spacing:2px;font-weight:bold">LES SORTIES DE ROUTE <span style="color:#79608f">✳︎</span> LE PETIT RAPPEL</td></tr>
<tr><td style="padding:32px 28px 12px">
<span style="display:inline-block;padding:5px 11px;background:#e4dbee;color:#5f437d;font-size:11px;letter-spacing:1px;font-weight:bold">L’IMPRÉVU, ÇA SE PRÉPARE.</span>
<h1 style="font-family:Georgia,serif;font-size:38px;line-height:1.12;font-weight:normal;margin:24px 0;color:#302e28">On te garde une place<br>dans l’imprévu <span style="color:#8d6ca7">?</span></h1>
<p style="font-size:16px;margin:0 0 16px">Bonjour <?php echo esc_html($name); ?>,</p>
<p style="font-size:16px;margin:0 0 22px">Une soirée se prépare et ta réponse manque encore au casting. Ça te dit de rejoindre l’aventure ?</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eee7f4;border:1px solid #d7c9e2">
<tr><td style="padding:20px 22px"><p style="font-size:11px;letter-spacing:1px;color:#6b537e;margin:0 0 6px;font-weight:bold">LE RENDEZ-VOUS</p><h2 style="font-family:Georgia,serif;font-size:25px;line-height:1.3;margin:0 0 16px;font-weight:normal"><?php echo esc_html($details['title']); ?></h2>
<p style="margin:0 0 5px;font-size:16px;font-weight:bold"><?php echo esc_html($details['date']); ?></p><p style="margin:0 0 12px;font-size:15px"><?php echo esc_html($details['venue']); ?></p>
<p style="margin:0;font-size:13px">Sur scène à <strong><?php echo esc_html($details['time']); ?></strong><br>Rendez-vous de la troupe à <strong><?php echo esc_html($details['callTime']); ?></strong></p></td></tr></table>
<p style="font-size:15px;margin:24px 0 6px">Tes terrains de jeu : <strong><?php echo esc_html(implode(' · ', $roles)); ?></strong>.</p><p style="font-size:14px;margin:0 0 22px;color:#6c655a">Tu choisis les rôles qui te tentent sur la petite fiche. Aucun mot de passe à retrouver, promis.</p>
</td></tr>
<tr><td align="center" style="padding:0 28px 12px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" bgcolor="#dce99f" style="border:1px solid #a3b35f;border-radius:8px"><a href="<?php echo esc_url($yes); ?>" style="display:block;padding:17px 18px;font-family:Arial,sans-serif;font-size:17px;font-weight:bold;color:#30391c;text-decoration:none">OK, je me lance ! ↗︎</a></td></tr></table></td></tr>
<tr><td align="center" style="padding:0 28px 28px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" bgcolor="#f5eee3" style="border:1px solid #c9bcaa;border-radius:8px"><a href="<?php echo esc_url($no); ?>" style="display:block;padding:15px 18px;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;color:#5f5547;text-decoration:none">Je ne suis pas dispo</a></td></tr></table><p style="font-size:12px;color:#736b60;margin:14px 0 0">Un clic ouvre la fiche ; tu confirmes ensuite ta réponse.<br>Une disponibilité n’est pas une sélection : l’organisation compose l’équipe.</p></td></tr>
<tr><td style="padding:22px 28px;background:#f1eddf;border-top:1px dashed #b9ad99"><p style="font-family:Georgia,serif;font-size:20px;margin:0 0 10px">Le meilleur plan ? Ne pas en avoir. <span style="color:#8d6ca7">✳︎</span></p><p style="font-size:12px;color:#736b60;margin:0">Ce lien est personnel : ne le transfère pas. Il est valable jusqu’au <?php echo esc_html(wp_date('d/m/Y', $expires)); ?>, tant que les réponses sont ouvertes. Une nouvelle invitation remplace le lien précédent.<br>Tu reçois ce message car tu as coché « OK pour la scène » dans ton profil. Tu peux y changer ce choix à tout moment.</p></td></tr>
</table></td></tr></table>
</body></html>
