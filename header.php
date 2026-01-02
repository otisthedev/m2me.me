<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="site">
    <header id="masthead" class="site-header">
        <div class="container">
            <?php
            $site_name = get_bloginfo('name');
            $home_url = home_url('/');
            ?>
            <a href="<?php echo esc_url($home_url); ?>" class="site-logo" rel="home">
                <?php echo esc_html($site_name); ?>
            </a>
            
            <button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
                <span class="screen-reader-text"><?php esc_html_e('Primary Menu', 'match-me'); ?></span>
                <span aria-hidden="true">☰</span>
            </button>
            
            <?php
            if (has_nav_menu('primary')) {
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'menu_id'        => 'primary-menu',
                    'container'      => false,
                    'menu_class'     => '',
                ));
            }
            ?>
        </div>
    </header>

    <div id="content" class="site-content">
        <div class="container">

