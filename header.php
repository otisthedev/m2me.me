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
        <div class="container header-inner">
            <?php
            $site_name = get_bloginfo('name');
            $home_url = home_url('/');
            ?>
            <a href="<?php echo esc_url($home_url); ?>" class="site-logo" rel="home">
                <?php echo esc_html($site_name); ?>
            </a>

            <?php
            $currentPath = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $redirectTo = wp_validate_redirect(home_url($currentPath), home_url('/'));
            ?>

            <?php
            // Primary nav (desktop) lives between logo and auth actions.
            ?>

            <nav class="site-nav" aria-label="Primary">
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
            </nav>

            <div class="header-auth-actions">
                <?php if (!is_user_logged_in()) : ?>
                    <a class="header-auth-btn mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                        Login
                    </a>
                    <a class="header-auth-btn header-auth-btn-primary mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                        Register
                    </a>
                <?php else : ?>
                    <a class="header-auth-btn" href="<?php echo esc_url(home_url('/profile/')); ?>">My Profile</a>
                    <a class="header-auth-btn" href="<?php echo esc_url(wp_logout_url($redirectTo)); ?>">Logout</a>
                <?php endif; ?>
            </div>
            
            <button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">
                <span class="screen-reader-text"><?php esc_html_e('Primary Menu', 'match-me'); ?></span>
                <span aria-hidden="true">☰</span>
            </button>
        </div>
    </header>

    <div id="content" class="site-content">
        <div class="container">

