<?php defined('ABSPATH') || exit;
$valid = !is_wp_error($context);
$done = $saved || $existing;
$can_answer = $valid && !$done && (!$error || (($error->get_error_data()['status'] ?? 400) === 400));
?>
<!doctype html><html lang="fr"><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ta réponse — Les Sorties de Route</title><link rel="stylesheet" href="<?php echo esc_url(plugins_url('response.css', __FILE__) . '?ver=' . self::VERSION); ?>"></head>
<body><main class="reply-ticket"><div class="reply-brand">LES SORTIES DE ROUTE <span aria-hidden="true">✳</span></div>
<div class="reply-inner"><div class="reply-label"><?php echo $can_answer ? 'RÉPONSE À CONFIRMER' : 'L’IMPRÉVU, ÇA SE PRÉPARE.'; ?></div>
<?php if (!$valid): ?>
<h1>Petit contretemps<span aria-hidden="true">.</span></h1><p role="alert"><?php echo esc_html($error->get_error_message()); ?></p>
<?php else: ?>
<h1><?php echo $done ? 'C’est dans la boîte' : ($can_answer ? 'Confirme ta réponse' : 'Petit contretemps'); ?><span aria-hidden="true"> ✳</span></h1>
<?php if ($can_answer): ?>
<div class="reply-confirmation"><strong>Ta réponse n’est pas encore enregistrée.</strong><p><?php echo $choice === 'no' ? 'Pour prévenir l’organisation, clique sur « Confirmer mon indisponibilité » ci-dessous.' : 'Choisis tes rôles puis clique sur « Confirmer : je me lance ! » ci-dessous pour prévenir l’organisation.'; ?></p></div>
<?php endif; ?>
<p>Bonjour <?php echo esc_html(SDR_Coulisses::email_name($context['user'])); ?>,</p>
<div class="reply-event"><h2><?php echo esc_html($details['title']); ?></h2><p><strong><?php echo esc_html($details['date']); ?></strong><br><?php echo esc_html($details['venue']); ?></p><p>Sur scène : <?php echo esc_html($details['time']); ?><br>Rendez-vous troupe : <?php echo esc_html($details['callTime']); ?></p></div>
<?php if ($done): ?>
<div class="reply-success" role="status"><?php if ($saved): ?>Ta réponse est enregistrée. <?php echo $choice === 'no' ? 'Merci de nous avoir prévenus !' : 'Merci, l’organisation a reçu ta proposition !'; ?><?php else: ?>Tu as déjà répondu à ce spectacle : <strong><?php echo esc_html(['yes' => 'Partant·e', 'maybe' => 'Si besoin', 'no' => 'Pas disponible'][$existing->availability] ?? 'réponse enregistrée'); ?></strong>. Ta réponse est conservée.<?php endif; ?></div>
<p class="reply-note">Pour modifier ta réponse, retrouve ce spectacle dans les Coulisses. Une disponibilité n’est pas une sélection : l’organisation confirme ensuite l’équipe.</p>
<?php else: ?>
<?php if ($error): ?><p class="reply-error" role="alert"><?php echo esc_html($error->get_error_message()); ?></p><?php endif; ?>
<?php if ($can_answer): ?>
<form method="post" action="<?php echo esc_url(self::link($context['event']->ID, $context['user']->ID, $credentials['token'], $choice)); ?>">
<input type="hidden" name="availability" value="<?php echo esc_attr($choice); ?>">
<?php if ($choice === 'yes'): ?>
<fieldset><legend>Dans quel rôle as-tu envie de te lancer ?</legend><p class="reply-note">Tu peux en choisir plusieurs. L’organisation compose ensuite l’équipe.</p><div class="reply-roles"><?php foreach ($context['roles'] as $role): ?><label><input type="checkbox" name="roles[]" value="<?php echo esc_attr($role); ?>" checked><span><?php echo esc_html(SDR_Coulisses::ROLES[$role]); ?></span></label><?php endforeach; ?></div></fieldset>
<button class="reply-button" type="submit">Confirmer : je me lance ! <span aria-hidden="true">↗</span></button>
<?php else: ?><p>Un imprévu ailleurs ? Confirme simplement que tu ne pourras pas participer à cette soirée.</p><button class="reply-button reply-no" type="submit">Confirmer mon indisponibilité</button><?php endif; ?>
<p class="reply-note">Aucun compte à ouvrir, aucun mot de passe à saisir.</p>
</form>
<a class="reply-alternative" href="<?php echo esc_url(self::link($context['event']->ID, $context['user']->ID, $credentials['token'], $choice === 'no' ? 'yes' : 'no')); ?>"><?php echo $choice === 'no' ? 'Finalement, je me lance !' : 'Finalement, je ne suis pas dispo'; ?> <span aria-hidden="true">↗</span></a>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div><footer><a href="<?php echo esc_url(home_url('/coulisses/' . ($valid ? '?spectacle=' . $context['event']->ID : ''))); ?>">Retrouver les Coulisses ↗</a><p>Le meilleur plan ? Ne pas en avoir.<br>Sur scène, en tout cas.</p></footer></main></body></html>
