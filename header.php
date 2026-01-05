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
            $theme_uri = get_template_directory_uri();
            $headerLogoId = (int) get_theme_mod('match_me_header_logo', 0);
            $headerLogoUrl = $headerLogoId ? wp_get_attachment_image_url($headerLogoId, 'full') : '';
            ?>
            <a href="<?php echo esc_url($home_url); ?>" class="site-logo" rel="home">
                <?php if (is_string($headerLogoUrl) && $headerLogoUrl !== '') : ?>
                    <img
                        class="site-logo-img"
                        src="<?php echo esc_url($headerLogoUrl); ?>"
                        alt="<?php echo esc_attr($site_name); ?>"
                        decoding="async"
                        loading="eager"
                    >
                <?php else : ?>
                    <picture>
                        <source srcset="<?php echo esc_url($theme_uri . '/assets/img/M2me.me-white.svg'); ?>" media="(prefers-color-scheme: dark)">
                        <img
                            class="site-logo-img"
                            src="<?php echo esc_url($theme_uri . '/assets/img/M2me.me.svg'); ?>"
                            alt="<?php echo esc_attr($site_name); ?>"
                            decoding="async"
                            loading="eager"
                        >
                    </picture>
                <?php endif; ?>
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

            <?php if (is_user_logged_in()) : ?>
                <div class="mm-notifications">
                    <button type="button" class="mm-notifications-btn" data-mm-notifications-btn aria-expanded="false" aria-label="<?php echo esc_attr__('Notifications', 'match-me'); ?>">
                        <span class="mm-notifications-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 22a2.4 2.4 0 0 0 2.4-2.4H9.6A2.4 2.4 0 0 0 12 22Z" fill="currentColor"/>
                                <path d="M18 16.8v-5.4A6 6 0 0 0 13.2 5.5V4.8a1.2 1.2 0 1 0-2.4 0v.7A6 6 0 0 0 6 11.4v5.4l-1.2 1.2v.6h14.4v-.6L18 16.8Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="mm-notifications-badge" data-mm-notifications-badge style="display:none;">0</span>
                    </button>

                    <div class="mm-notifications-panel" data-mm-notifications-panel style="display:none;" aria-hidden="true">
                        <div class="mm-notifications-head">
                            <div class="mm-notifications-title"><?php echo esc_html__('Notifications', 'match-me'); ?></div>
                            <a class="mm-notifications-link" href="<?php echo esc_url(home_url('/comparisons/')); ?>"><?php echo esc_html__('History', 'match-me'); ?></a>
                        </div>
                        <div class="mm-notifications-list" data-mm-notifications-list>
                            <div class="mm-notifications-empty"><?php echo esc_html__('No new notifications.', 'match-me'); ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="header-auth-actions">
                <?php if (!is_user_logged_in()) : ?>
                    <a class="header-auth-btn mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                        <?php echo esc_html__('Login', 'match-me'); ?>
                    </a>
                    <a class="header-auth-btn header-auth-btn-primary mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                        <?php echo esc_html__('Register', 'match-me'); ?>
                    </a>
                <?php else : ?>
                    <a class="header-auth-btn" href="<?php echo esc_url(home_url('/profile/')); ?>"><?php echo esc_html__('My Profile', 'match-me'); ?></a>
                    <a class="header-auth-btn" href="<?php echo esc_url(wp_logout_url($redirectTo)); ?>"><?php echo esc_html__('Logout', 'match-me'); ?></a>
                <?php endif; ?>
            </div>

            <?php
            // Add Polylang language switcher
            if (function_exists('pll_the_languages')) {
                $languages = pll_the_languages(['raw' => 1]);
                if (is_array($languages) && count($languages) > 1) {
                    echo '<div class="header-language-switcher">';
                    foreach ($languages as $lang) {
                        $class = $lang['current_lang'] ? 'current-lang' : '';
                        echo '<a href="' . esc_url($lang['url']) . '" class="' . esc_attr($class) . '" hreflang="' . esc_attr($lang['slug']) . '">' . esc_html($lang['name']) . '</a>';
                    }
                    echo '</div>';
                }
            }
            ?>

            <button class="menu-toggle" type="button" aria-controls="primary-menu-mobile" aria-expanded="false" aria-label="<?php echo esc_attr__('Menu', 'match-me'); ?>">
                <span class="menu-toggle-bars" aria-hidden="true"></span>
            </button>
        </div>

        <div class="mm-mobile-menu-overlay" data-mm-menu-close style="display:none;"></div>
        <div class="mm-mobile-menu" aria-hidden="true" style="display:none;">
            <div class="container">
                <nav class="mm-mobile-nav" aria-label="Mobile Primary">
                    <?php
                    if (has_nav_menu('primary')) {
                        wp_nav_menu(array(
                            'theme_location' => 'primary',
                            'menu_id'        => 'primary-menu-mobile',
                            'container'      => false,
                            'menu_class'     => '',
                        ));
                    }
                    ?>
                </nav>
            </div>
        </div>
    </header>

    <div id="content" class="site-content">
