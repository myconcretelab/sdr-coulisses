<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Relances utilisateurs — <?php echo esc_html(get_bloginfo('name')); ?></title>
    <?php wp_print_styles(); wp_print_head_scripts(); ?>
</head>
<body class="sdr-relances-embedded wp-core-ui">
    <main><?php require __DIR__ . '/page.php'; ?></main>
    <?php wp_footer(); ?>
</body>
</html>
