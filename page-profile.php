<?php
/**
 * Template Name: Profile
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Db\QuizResultsTable;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Wp\Container;

get_header();

?>

<main class="mm-profile container" style="max-width: 900px;">
    <h1>My Profile</h1>

    <?php if (!is_user_logged_in()) : ?>
        <p>Please log in to see your saved results.</p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => home_url('/profile/')], home_url('/'))); ?>">Login</a>
            &nbsp;or&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => home_url('/profile/')], home_url('/'))); ?>">Register</a>
        </p>
    <?php else : ?>
        <?php
        $userId = (int) get_current_user_id();
        $user = wp_get_current_user();
        $currentFirst = (string) get_user_meta($userId, 'first_name', true);
        $currentLast = (string) get_user_meta($userId, 'last_name', true);
        $currentPic = (string) get_user_meta($userId, 'profile_picture', true);

        $config = Container::config();
        $wpdb = Container::wpdb();

        $quizRepo = new QuizJsonRepository($config);

        // v2 results (match_me_results)
        $resultRepo = new ResultRepository($wpdb);
        $latestV2 = $resultRepo->latestByUserGroupedByQuizSlug($userId);

        // v1 results (legacy quiz_results table)
        $legacyTable = new QuizResultsTable($wpdb);
        $legacyRepo = new QuizResultRepository($wpdb, $legacyTable);
        $latestV1 = $legacyRepo->latestAttemptsByUserGroupedByQuiz($userId);

        function mm_quiz_title(QuizJsonRepository $repo, string $quizId): string {
            try {
                $q = $repo->load($quizId);
                return (string) (($q['meta']['title'] ?? '') ?: $quizId);
            } catch (\Throwable) {
                return $quizId;
            }
        }
        ?>

        <?php if (isset($_GET['updated']) && (string) $_GET['updated'] === '1') : ?>
            <div class="mm-profile-notice" style="margin: 12px 0; padding: 12px; border: 1px solid var(--color-border,#e5e5e5); border-radius: 10px;">
                Profile updated.
            </div>
        <?php endif; ?>

        <section style="margin-top: 1.25rem; margin-bottom: 2rem;">
            <h2>Profile details</h2>

            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom: 12px;">
                <div>
                    <?php echo get_avatar($userId, 64); ?>
                </div>
                <div style="color:var(--color-text-secondary,#666); font-size:0.95rem;">
                    <div><strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong></div>
                    <div><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:grid; gap:12px;">
                <input type="hidden" name="action" value="match_me_profile_update">
                <?php wp_nonce_field('match_me_profile_update', 'match_me_profile_nonce'); ?>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <label style="display:block;">
                        <span style="display:block; font-size:0.85rem; color:var(--color-text-secondary,#666); margin-bottom:6px;">First name</span>
                        <input type="text" name="first_name" value="<?php echo esc_attr($currentFirst); ?>" style="width:100%; min-height:48px; border-radius:10px; border:1px solid var(--color-border,#e5e5e5); padding:0 14px;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; font-size:0.85rem; color:var(--color-text-secondary,#666); margin-bottom:6px;">Last name</span>
                        <input type="text" name="last_name" value="<?php echo esc_attr($currentLast); ?>" style="width:100%; min-height:48px; border-radius:10px; border:1px solid var(--color-border,#e5e5e5); padding:0 14px;">
                    </label>
                </div>

                <label style="display:block;">
                    <span style="display:block; font-size:0.85rem; color:var(--color-text-secondary,#666); margin-bottom:6px;">Profile image (upload)</span>
                    <input type="file" name="profile_picture_file" accept="image/*">
                </label>

                <label style="display:block;">
                    <span style="display:block; font-size:0.85rem; color:var(--color-text-secondary,#666); margin-bottom:6px;">Or profile image URL</span>
                    <input type="url" name="profile_picture_url" value="<?php echo esc_attr($currentPic); ?>" placeholder="https://..." style="width:100%; min-height:48px; border-radius:10px; border:1px solid var(--color-border,#e5e5e5); padding:0 14px;">
                </label>

                <button type="submit" class="mm-auth-submit" style="max-width:220px;">Save changes</button>
            </form>
        </section>

        <section style="margin-top: 1.5rem;">
            <h2>Latest Results (new quizzes)</h2>
            <?php if ($latestV2 === []) : ?>
                <p>No results yet.</p>
            <?php else : ?>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:12px;">
                    <?php foreach ($latestV2 as $row) : ?>
                        <?php
                        $quizSlug = (string) $row['quiz_slug'];
                        $title = mm_quiz_title($quizRepo, $quizSlug);
                        $viewUrl = $row['share_token'] !== '' ? home_url('/result/' . $row['share_token'] . '/') : '';
                        $compareUrl = $row['share_token'] !== '' ? home_url('/compare/' . $row['share_token'] . '/') : '';
                        $takenAt = $row['created_at'] !== '' ? date_i18n(get_option('date_format'), strtotime($row['created_at'])) : '';
                        ?>
                        <li style="border:1px solid var(--color-border, #e5e5e5); border-radius:10px; padding:14px;">
                            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div>
                                    <div style="font-weight:600;"><?php echo esc_html($title); ?></div>
                                    <?php if ($takenAt !== '') : ?>
                                        <div style="color:var(--color-text-secondary,#666); font-size:0.9rem;">Latest: <?php echo esc_html($takenAt); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <?php if ($viewUrl !== '') : ?>
                                        <a href="<?php echo esc_url($viewUrl); ?>">View</a>
                                    <?php endif; ?>
                                    <?php if ($compareUrl !== '') : ?>
                                        <a href="<?php echo esc_url($compareUrl); ?>">Compare</a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url(home_url('/' . $quizSlug . '/')); ?>">Take again</a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section style="margin-top: 2rem;">
            <h2>Latest Results (legacy quizzes)</h2>
            <?php if ($latestV1 === []) : ?>
                <p>No legacy results yet.</p>
            <?php else : ?>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:12px;">
                    <?php foreach ($latestV1 as $row) : ?>
                        <?php
                        $quizId = (string) $row['quiz_id'];
                        $attemptId = (int) $row['latest_attempt'];
                        $title = mm_quiz_title($quizRepo, $quizId);
                        $viewUrl = home_url('/' . $quizId . '/' . $attemptId . '/');
                        ?>
                        <li style="border:1px solid var(--color-border, #e5e5e5); border-radius:10px; padding:14px;">
                            <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="font-weight:600;"><?php echo esc_html($title); ?></div>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <a href="<?php echo esc_url($viewUrl); ?>">View</a>
                                    <a href="<?php echo esc_url(home_url('/' . $quizId . '/')); ?>">Take again</a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<?php
get_footer();


