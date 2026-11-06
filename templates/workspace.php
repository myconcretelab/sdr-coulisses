<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('sdr-workspace'); ?>><?php wp_body_open(); ?>
<main id="contenu"><?php echo SDR_Coulisses::app(); // The private application escapes its user data. ?></main>
<?php wp_footer(); ?></body></html>
