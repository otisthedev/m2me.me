    </div><!-- #content -->

    <footer id="colophon" class="site-footer">
        <div class="container">
            <?php
            $site_name = get_bloginfo('name');
            $home_url = home_url('/');
            $theme_uri = get_template_directory_uri();
            $headerLogoId = (int) get_theme_mod('match_me_header_logo', 0);
            $headerLogoUrl = $headerLogoId ? wp_get_attachment_image_url($headerLogoId, 'full') : '';
            ?>

            <div class="mm-footer">
                <div class="mm-footer-brand">
                    <a href="<?php echo esc_url($home_url); ?>" class="mm-footer-logo" rel="home" aria-label="<?php echo esc_attr($site_name); ?>">
                        <?php if (is_string($headerLogoUrl) && $headerLogoUrl !== '') : ?>
                            <img class="mm-footer-logo-img" src="<?php echo esc_url($headerLogoUrl); ?>" alt="<?php echo esc_attr($site_name); ?>" decoding="async" loading="lazy">
                        <?php else : ?>
                            <picture>
                                <source srcset="<?php echo esc_url($theme_uri . '/assets/img/M2me.me-white.svg'); ?>" media="(prefers-color-scheme: dark)">
                                <img class="mm-footer-logo-img" src="<?php echo esc_url($theme_uri . '/assets/img/M2me.me.svg'); ?>" alt="<?php echo esc_attr($site_name); ?>" decoding="async" loading="lazy">
                            </picture>
                        <?php endif; ?>
                    </a>
                </div>

                <nav class="mm-footer-nav" aria-label="<?php echo esc_attr__('Footer Menu', 'match-me'); ?>">
                    <?php
                    if (has_nav_menu('footer')) {
                        wp_nav_menu([
                            'theme_location' => 'footer',
                            'container' => false,
                            'menu_class' => 'mm-footer-menu',
                            'fallback_cb' => '__return_empty_string',
                        ]);
                    }
                    ?>
                </nav>

                <div class="site-info">
                    <p>&copy; <?php echo date('Y'); ?> <a href="<?php echo esc_url($home_url); ?>"><?php bloginfo('name'); ?></a>. <?php esc_html_e('All rights reserved.', 'match-me'); ?></p>
                </div>
            </div>
        </div>
    </footer>
</div><!-- #page -->

<?php wp_footer(); ?>
</body>
</html>

