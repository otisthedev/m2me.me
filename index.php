<?php
/**
 * The main template file
 *
 * @package MatchMe
 */

get_header();
?>

<main id="main" class="site-main">
    <?php if (is_home() || is_front_page()) : ?>
        <?php
        $currentPath = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $redirectTo = wp_validate_redirect(home_url($currentPath), home_url('/'));
        $siteName = (string) get_bloginfo('name');
        $themeUri = (string) get_template_directory_uri();
        $headerLogoId = (int) get_theme_mod('match_me_header_logo', 0);
        $heroImg = $headerLogoId ? wp_get_attachment_image_url($headerLogoId, 'full') : '';
        ?>

        <section class="mm-hero">
            <div class="container">
                <div class="mm-hero-inner">
                    <div class="mm-hero-copy">
                        <div class="mm-hero-eyebrow">Personality quizzes • Results • Comparison</div>
                        <h1 class="mm-hero-title">Know yourself.<br>Match better.</h1>
                        <p class="mm-hero-subtitle">
                            Take quick personality quizzes, get a clear trait breakdown, and compare with others in seconds.
                        </p>

                        <?php if (!is_user_logged_in()) : ?>
                            <div class="mm-hero-cta">
                                <a class="header-auth-btn header-auth-btn-primary mm-auth-link" data-auth="register"
                                   href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                                    Register
                                </a>
                                <a class="header-auth-btn mm-auth-link" data-auth="login"
                                   href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                                    Login
                                </a>
                            </div>
                        <?php else : ?>
                            <div class="mm-hero-cta">
                                <a class="header-auth-btn header-auth-btn-primary" href="<?php echo esc_url(home_url('/profile/')); ?>">
                                    My Profile
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mm-hero-media" aria-hidden="true">
                        <?php if (is_string($heroImg) && $heroImg !== '') : ?>
                            <img class="mm-hero-image" src="<?php echo esc_url($heroImg); ?>" alt="<?php echo esc_attr($siteName); ?>" decoding="async" loading="eager">
                        <?php else : ?>
                            <picture>
                                <source srcset="<?php echo esc_url($themeUri . '/assets/img/M2me.me-white.svg'); ?>" media="(prefers-color-scheme: dark)">
                                <img class="mm-hero-image" src="<?php echo esc_url($themeUri . '/assets/img/M2me.me.svg'); ?>" alt="<?php echo esc_attr($siteName); ?>" decoding="async" loading="eager">
                            </picture>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php
    if (have_posts()) {
        echo '<div class="mm-post-grid">';
        while (have_posts()) {
            the_post();
            get_template_part('template-parts/content', get_post_type());
        }
        echo '</div>';
        
        // Pagination
        the_posts_pagination(array(
            'mid_size'  => 2,
            'prev_text' => __('&laquo; Previous', 'match-me'),
            'next_text' => __('Next &raquo;', 'match-me'),
        ));
    } else {
        get_template_part('template-parts/content', 'none');
    }
    ?>
</main>

<?php
get_footer();

