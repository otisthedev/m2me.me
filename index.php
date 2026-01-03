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

        <?php if (!is_user_logged_in()) : ?>
            <section class="mm-hero">
                <div class="container">
                    <div class="mm-hero-inner">
                        <div class="mm-hero-copy">
                            <h1 class="mm-hero-title">Know yourself.<br>Match better.</h1>
                            <p class="mm-hero-subtitle">
                                Take quick personality quizzes, get a clear trait breakdown, and compare with others in seconds.
                            </p>

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
        <?php else : ?>
            <?php
            $comparisons = [];
            try {
                $wpdb = \MatchMe\Wp\Container::wpdb();
                $cfg = \MatchMe\Wp\Container::config();
                $compRepo = new \MatchMe\Infrastructure\Db\ComparisonRepository($wpdb);
                $quizRepo = new \MatchMe\Infrastructure\Quiz\QuizJsonRepository($cfg);
                $quizTitleCache = [];
                $comparisons = $compRepo->latestByUser((int) get_current_user_id(), 6);
            } catch (\Throwable) {
                $comparisons = [];
            }
            ?>

            <section class="mm-recent-comparisons">
                <div class="container">
                    <div class="mm-recent-header">
                        <h2 class="mm-recent-title">Recent comparisons</h2>
                    </div>

                    <?php if ($comparisons === []) : ?>
                        <div class="mm-empty">
                            <p>No comparisons yet. Take a quiz and share the compare link to get started.</p>
                        </div>
                    <?php else : ?>
                        <div class="mm-compare-grid">
                            <?php foreach ($comparisons as $row) : ?>
                                <?php
                                $shareToken = (string) ($row['share_token'] ?? '');
                                if ($shareToken === '') {
                                    continue;
                                }
                                $matchScore = (int) round((float) ($row['match_score'] ?? 0.0));
                                $createdAt = (string) ($row['created_at'] ?? '');
                                $createdTs = $createdAt !== '' ? strtotime($createdAt) : 0;
                                $ago = $createdTs > 0 ? human_time_diff($createdTs, (int) current_time('timestamp')) . ' ago' : '';

                                $userId = (int) get_current_user_id();
                                $userA = isset($row['user_a']) ? (int) $row['user_a'] : 0;
                                $userB = isset($row['user_b']) ? (int) $row['user_b'] : 0;
                                $otherId = ($userA === $userId) ? $userB : $userA;

                                $otherName = 'Someone';
                                $otherAvatar = '';
                                if ($otherId > 0) {
                                    $u = get_user_by('id', $otherId);
                                    if ($u instanceof \WP_User) {
                                        $first = (string) get_user_meta($otherId, 'first_name', true);
                                        $otherName = $first !== '' ? $first : (string) ($u->display_name ?: $otherName);
                                        $otherAvatar = (string) get_avatar_url($otherId, ['size' => 128]);
                                    }
                                }

                                $quizSlug = (string) (($row['quiz_slug_a'] ?? '') ?: ($row['quiz_slug_b'] ?? ''));
                                $quizTitle = $quizSlug !== '' ? ucfirst(str_replace('-', ' ', $quizSlug)) : 'Quiz';
                                if ($quizSlug !== '') {
                                    if (isset($quizTitleCache[$quizSlug])) {
                                        $quizTitle = (string) $quizTitleCache[$quizSlug];
                                    } else {
                                        try {
                                            $qc = $quizRepo->load($quizSlug);
                                            $quizTitle = (string) (($qc['meta']['title'] ?? '') ?: $quizTitle);
                                        } catch (\Throwable) {
                                            // ignore
                                        }
                                        $quizTitleCache[$quizSlug] = $quizTitle;
                                    }
                                }

                                $url = home_url('/match/' . rawurlencode($shareToken) . '/');
                                ?>

                                <article class="mm-compare-card">
                                    <div class="mm-compare-card-top">
                                        <div class="mm-compare-avatar">
                                            <?php if ($otherAvatar !== '') : ?>
                                                <img src="<?php echo esc_url($otherAvatar); ?>" alt="<?php echo esc_attr($otherName); ?>" loading="lazy" decoding="async">
                                            <?php else : ?>
                                                <span><?php echo esc_html(mb_substr($otherName, 0, 1)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mm-compare-meta">
                                            <div class="mm-compare-line">
                                                <strong><?php echo esc_html($otherName); ?></strong>
                                                <?php if ($ago !== '') : ?>
                                                    did a comparison <?php echo esc_html($ago); ?>
                                                <?php else : ?>
                                                    did a comparison recently
                                                <?php endif; ?>
                                            </div>
                                            <div class="mm-compare-sub"><?php echo esc_html($quizTitle); ?> • <?php echo esc_html($matchScore); ?>% match</div>
                                        </div>
                                    </div>
                                    <div class="mm-compare-actions">
                                        <a class="mm-compare-link" href="<?php echo esc_url($url); ?>">Check results</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <div class="container">
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
    </div>
</main>

<?php
get_footer();

