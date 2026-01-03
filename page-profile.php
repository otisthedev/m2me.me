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

<main class="mm-profile container mm-page mm-page-900">
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
        $emailNotifyPref = (string) get_user_meta($userId, 'match_me_email_compare_notify', true);
        $emailNotifyOn = $emailNotifyPref !== 'off';

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
            <div class="mm-profile-notice">
                Profile updated.
            </div>
        <?php endif; ?>

        <section class="mm-section mm-mb-lg">
            <h2>Profile details</h2>

            <div class="mm-flex mm-mb-md">
                <div>
                    <?php echo get_avatar($userId, 64); ?>
                </div>
                <div class="mm-muted">
                    <div><strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong></div>
                    <div><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="mm-grid">
                <input type="hidden" name="action" value="match_me_profile_update">
                <?php wp_nonce_field('match_me_profile_update', 'match_me_profile_nonce'); ?>

                <div class="mm-grid-2">
                    <label class="mm-field">
                        <span class="mm-field-label">First name</span>
                        <input class="mm-input" type="text" name="first_name" value="<?php echo esc_attr($currentFirst); ?>">
                    </label>
                    <label class="mm-field">
                        <span class="mm-field-label">Last name</span>
                        <input class="mm-input" type="text" name="last_name" value="<?php echo esc_attr($currentLast); ?>">
                    </label>
                </div>

                <label class="mm-field">
                    <span class="mm-field-label">Profile image (upload)</span>
                    <div class="mm-file">
                        <input id="mm_profile_picture_file" class="mm-file-input" type="file" name="profile_picture_file" accept="image/*">
                        <label for="mm_profile_picture_file" class="mm-profile-btn mm-profile-btn-outline">Choose file</label>
                        <span class="mm-file-name" aria-live="polite">No file chosen</span>
                    </div>
                </label>

                <label class="mm-field">
                    <span class="mm-field-label">Or profile image URL</span>
                    <input class="mm-input" type="url" name="profile_picture_url" value="<?php echo esc_attr($currentPic); ?>" placeholder="https://...">
                </label>

                <label class="mm-toggle">
                    <input type="checkbox" name="email_compare_notify" value="1" <?php checked($emailNotifyOn); ?>>
                    <span>
                        <div class="mm-toggle-title">Email notifications</div>
                        <div class="mm-toggle-sub">Email me when someone compares with my results.</div>
                    </span>
                </label>

                <button type="submit" class="mm-profile-btn mm-profile-btn-primary">Save changes</button>
            </form>

            <script>
                (function () {
                    const input = document.getElementById('mm_profile_picture_file');
                    const nameEl = document.querySelector('.mm-file-name');
                    if (!input || !nameEl) return;
                    input.addEventListener('change', function () {
                        const f = input.files && input.files[0] ? input.files[0].name : '';
                        nameEl.textContent = f || 'No file chosen';
                    });
                })();
            </script>
        </section>

        <section class="mm-section">
            <h2>Latest Results (new quizzes)</h2>
            <?php if ($latestV2 === []) : ?>
                <p>No results yet.</p>
            <?php else : ?>
                <ul class="mm-list">
                    <?php foreach ($latestV2 as $row) : ?>
                        <?php
                        $quizSlug = (string) $row['quiz_slug'];
                        $title = mm_quiz_title($quizRepo, $quizSlug);
                        $viewUrl = $row['share_token'] !== '' ? home_url('/result/' . $row['share_token'] . '/') : '';
                        $compareUrl = $row['share_token'] !== '' ? home_url('/compare/' . $row['share_token'] . '/') : '';
                        $takenAt = $row['created_at'] !== '' ? date_i18n(get_option('date_format'), strtotime($row['created_at'])) : '';
                        ?>
                        <li class="mm-list-item">
                            <div class="mm-row">
                                <div>
                                    <div class="mm-row-title"><?php echo esc_html($title); ?></div>
                                    <?php if ($takenAt !== '') : ?>
                                        <div class="mm-muted mm-text-sm">Latest: <?php echo esc_html($takenAt); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mm-row-actions">
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

        <?php /* Legacy results removed */ ?>

        <section class="mm-section mm-mt-xl mm-divider-top">
            <h2>Delete account</h2>
            <p class="mm-muted mm-max-70ch">
                This will permanently delete your account and <strong>everything related to you</strong>, including your profile information,
                quiz history, results, and comparisons. This action cannot be undone.
            </p>

            <form id="mm-delete-account-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="match_me_delete_account">
                <?php wp_nonce_field('match_me_delete_account', 'match_me_delete_account_nonce'); ?>
                <input type="hidden" name="confirm_delete" value="">

                <button type="button" class="mm-profile-btn mm-profile-btn-danger" id="mm-delete-account-btn">
                    Delete my account
                </button>
            </form>

            <script>
                (function () {
                    const btn = document.getElementById('mm-delete-account-btn');
                    const form = document.getElementById('mm-delete-account-form');
                    if (!btn || !form) return;

                    btn.addEventListener('click', function () {
                        const first = window.confirm(
                            'Delete your account?\n\nThis will permanently delete your profile, quiz history, results, and comparisons. This cannot be undone.'
                        );
                        if (!first) return;

                        const typed = window.prompt('Type DELETE to confirm account deletion:');
                        if (typed !== 'DELETE') {
                            window.alert('Account deletion cancelled.');
                            return;
                        }

                        const second = window.confirm('Final confirmation: permanently delete your account now?');
                        if (!second) return;

                        const input = form.querySelector('input[name="confirm_delete"]');
                        if (input) input.value = typed;
                        form.submit();
                    });
                })();
            </script>
        </section>
    <?php endif; ?>
</main>

<?php
get_footer();


