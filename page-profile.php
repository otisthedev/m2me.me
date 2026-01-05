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
    <h1><?php echo esc_html__('My Profile', 'match-me'); ?></h1>

    <?php if (!is_user_logged_in()) : ?>
        <p><?php echo esc_html__('Please log in to see your saved results.', 'match-me'); ?></p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => home_url('/profile/')], home_url('/'))); ?>"><?php echo esc_html__('Login', 'match-me'); ?></a>
            &nbsp;<?php echo esc_html__('or', 'match-me'); ?>&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => home_url('/profile/')], home_url('/'))); ?>"><?php echo esc_html__('Register', 'match-me'); ?></a>
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
                <?php echo esc_html__('Profile updated.', 'match-me'); ?>
            </div>
        <?php endif; ?>

        <section class="mm-profile-hero mm-mt-md">
            <div class="mm-profile-hero-top">
                <button type="button" class="mm-profile-avatar mm-profile-avatar-edit" data-mm-avatar-edit aria-expanded="false" aria-controls="mm-profile-photo-panel">
                    <?php echo get_avatar($userId, 72); ?>
                    <span class="mm-profile-avatar-overlay" aria-hidden="true">
                        <span class="mm-profile-avatar-pencil" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 17.25V21h3.75L19.81 7.94l-3.75-3.75L3 17.25Z" fill="currentColor"/>
                                <path d="M20.71 6.04a1 1 0 0 0 0-1.41l-1.34-1.34a1 1 0 0 0-1.41 0l-1.06 1.06 3.75 3.75 1.06-1.06Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="mm-profile-avatar-edit-text"><?php echo esc_html__('Edit', 'match-me'); ?></span>
                    </span>
                </button>
                <div class="mm-profile-identity">
                    <div class="mm-profile-name"><?php echo esc_html($user->display_name ?: $user->user_login); ?></div>
                    <div class="mm-profile-email"><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>
            <div class="mm-profile-quick">
                <a class="mm-pill-link" href="<?php echo esc_url(home_url('/matches/')); ?>"><?php echo esc_html__('Matches', 'match-me'); ?></a>
                <a class="mm-pill-link" href="<?php echo esc_url(home_url('/comparisons/')); ?>"><?php echo esc_html__('Comparisons', 'match-me'); ?></a>
                <a class="mm-pill-link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"><?php echo esc_html__('Logout', 'match-me'); ?></a>
            </div>
        </section>

        <section class="mm-section mm-mb-lg">
            <h2><?php echo esc_html__('Profile details', 'match-me'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="mm-grid">
                <input type="hidden" name="action" value="match_me_profile_update">
                <?php wp_nonce_field('match_me_profile_update', 'match_me_profile_nonce'); ?>

                <div class="mm-grid-2">
                    <label class="mm-field">
                        <span class="mm-field-label"><?php echo esc_html__('First name', 'match-me'); ?></span>
                        <input class="mm-input" type="text" name="first_name" value="<?php echo esc_attr($currentFirst); ?>">
                    </label>
                    <label class="mm-field">
                        <span class="mm-field-label"><?php echo esc_html__('Last name', 'match-me'); ?></span>
                        <input class="mm-input" type="text" name="last_name" value="<?php echo esc_attr($currentLast); ?>">
                    </label>
                </div>

                <div id="mm-profile-photo-panel" class="mm-profile-photo-panel" hidden>
                <label class="mm-field">
                    <span class="mm-field-label"><?php echo esc_html__('Profile image (upload)', 'match-me'); ?></span>
                    <div class="mm-file">
                        <input id="mm_profile_picture_file" class="mm-file-input" type="file" name="profile_picture_file" accept="image/*">
                        <label for="mm_profile_picture_file" class="mm-profile-btn mm-profile-btn-outline"><?php echo esc_html__('Choose file', 'match-me'); ?></label>
                        <span class="mm-file-name" aria-live="polite"><?php echo esc_html__('No file chosen', 'match-me'); ?></span>
                    </div>
                </label>

                <label class="mm-field">
                    <span class="mm-field-label"><?php echo esc_html__('Or profile image URL', 'match-me'); ?></span>
                    <input class="mm-input" type="url" name="profile_picture_url" value="<?php echo esc_attr($currentPic); ?>" placeholder="https://...">
                </label>
                </div>

                <label class="mm-toggle">
                    <input type="checkbox" name="email_compare_notify" value="1" <?php checked($emailNotifyOn); ?>>
                    <span>
                        <div class="mm-toggle-title"><?php echo esc_html__('Email notifications', 'match-me'); ?></div>
                        <div class="mm-toggle-sub"><?php echo esc_html__('Email me when someone compares with my results.', 'match-me'); ?></div>
                    </span>
                </label>

                <button type="submit" class="mm-profile-btn mm-profile-btn-primary"><?php echo esc_html__('Save changes', 'match-me'); ?></button>
            </form>

            <script>
                (function () {
                    const avatarBtn = document.querySelector('[data-mm-avatar-edit]');
                    const panel = document.getElementById('mm-profile-photo-panel');
                    const input = document.getElementById('mm_profile_picture_file');
                    const nameEl = document.querySelector('.mm-file-name');

                    if (input && nameEl) {
                        const noFileText = <?php echo wp_json_encode(__('No file chosen', 'match-me')); ?>;
                        input.addEventListener('change', function () {
                            const f = input.files && input.files[0] ? input.files[0].name : '';
                            nameEl.textContent = f || noFileText;
                        });
                    }

                    if (!avatarBtn || !panel) return;

                    function openPanel() {
                        panel.hidden = false;
                        avatarBtn.setAttribute('aria-expanded', 'true');
                        // Focus the first control for better mobile UX
                        const focusable = panel.querySelector('input, button, a, select, textarea');
                        if (focusable && typeof focusable.focus === 'function') focusable.focus();
                    }

                    function closePanel() {
                        panel.hidden = true;
                        avatarBtn.setAttribute('aria-expanded', 'false');
                    }

                    avatarBtn.addEventListener('click', function () {
                        if (panel.hidden) openPanel();
                        else closePanel();
                    });
                })();
            </script>
        </section>

        <section class="mm-section">
            <h2><?php echo esc_html__('Latest Results (new quizzes)', 'match-me'); ?></h2>
            <?php if ($latestV2 === []) : ?>
                <p><?php echo esc_html__('No results yet.', 'match-me'); ?></p>
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
                                        <div class="mm-muted mm-text-sm"><?php echo esc_html__('Latest:', 'match-me'); ?> <?php echo esc_html($takenAt); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mm-row-actions">
                                    <?php if ($viewUrl !== '') : ?>
                                        <a class="mm-pill-link" href="<?php echo esc_url($viewUrl); ?>"><?php echo esc_html__('View', 'match-me'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($compareUrl !== '') : ?>
                                        <a class="mm-pill-link" href="<?php echo esc_url($compareUrl); ?>"><?php echo esc_html__('Compare', 'match-me'); ?></a>
                                    <?php endif; ?>
                                    <a class="mm-pill-link" href="<?php echo esc_url(home_url('/' . $quizSlug . '/')); ?>"><?php echo esc_html__('Take again', 'match-me'); ?></a>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php /* Legacy results removed */ ?>

        <section class="mm-section mm-mt-xl mm-divider-top">
            <h2><?php echo esc_html__('Privacy & Data (GDPR)', 'match-me'); ?></h2>
            
            <div class="mm-gdpr-actions">
                <div class="mm-gdpr-action">
                    <h3><?php echo esc_html__('Export your data', 'match-me'); ?></h3>
                    <p class="mm-muted mm-max-70ch">
                        <?php echo esc_html__('Download all your data in JSON format, including your profile, quiz results, and comparisons. This is your right under GDPR (Article 20 - Right to Data Portability).', 'match-me'); ?>
                    </p>
                    <button type="button" class="mm-profile-btn mm-profile-btn-outline" id="mm-export-data-btn">
                        <?php echo esc_html__('Export my data', 'match-me'); ?>
                    </button>
                    <div id="mm-export-status" class="mm-gdpr-status" role="status" aria-live="polite"></div>
                </div>

                <div class="mm-gdpr-action mm-mt-md">
                    <h3><?php echo esc_html__('Delete your data', 'match-me'); ?></h3>
                    <p class="mm-muted mm-max-70ch">
                        <?php echo esc_html__('Request deletion of all your data. This will permanently delete your quiz results, comparisons, and profile information. This action cannot be undone. This is your right under GDPR (Article 17 - Right to Erasure).', 'match-me'); ?>
                    </p>
                    <button type="button" class="mm-profile-btn mm-profile-btn-outline mm-profile-btn-danger" id="mm-delete-data-btn">
                        <?php echo esc_html__('Delete my data', 'match-me'); ?>
                    </button>
                    <div id="mm-delete-status" class="mm-gdpr-status" role="status" aria-live="polite"></div>
                </div>
            </div>

            <script>
                (function() {
                    const API_BASE = '<?php echo esc_js(rest_url('match-me/v1')); ?>';
                    const nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
                    const i18n = {
                        exporting: <?php echo wp_json_encode(__('Exporting your data...', 'match-me')); ?>,
                        exportSuccess: <?php echo wp_json_encode(__('Data exported successfully!', 'match-me')); ?>,
                        exportError: <?php echo wp_json_encode(__('Error:', 'match-me')); ?>,
                        exportFailed: <?php echo wp_json_encode(__('Failed to export data', 'match-me')); ?>,
                        deleteConfirm: <?php echo wp_json_encode(__('Delete your data?\n\nThis will permanently delete your quiz results, comparisons, and profile information. This cannot be undone.', 'match-me')); ?>,
                        deletePrompt: <?php echo wp_json_encode(__('Type DELETE to confirm data deletion:', 'match-me')); ?>,
                        deleteCancelled: <?php echo wp_json_encode(__('Data deletion cancelled.', 'match-me')); ?>,
                        deleteFinalConfirm: <?php echo wp_json_encode(__('Final confirmation: permanently delete all your data now?', 'match-me')); ?>,
                        deleting: <?php echo wp_json_encode(__('Deleting your data...', 'match-me')); ?>,
                        deleteSuccess: <?php echo wp_json_encode(__('Your data has been deleted successfully. Redirecting...', 'match-me')); ?>,
                        deleteFailed: <?php echo wp_json_encode(__('Failed to delete data', 'match-me')); ?>
                    };

                    // Export data
                    const exportBtn = document.getElementById('mm-export-data-btn');
                    const exportStatus = document.getElementById('mm-export-status');
                    if (exportBtn && exportStatus) {
                        exportBtn.addEventListener('click', async function() {
                            exportBtn.disabled = true;
                            exportStatus.textContent = i18n.exporting;
                            exportStatus.className = 'mm-gdpr-status mm-gdpr-status-info';

                            try {
                                const response = await fetch(API_BASE + '/gdpr/export', {
                                    method: 'GET',
                                    headers: {
                                        'X-WP-Nonce': nonce
                                    }
                                });

                                if (!response.ok) {
                                    const error = await response.json();
                                    throw new Error(error.message || i18n.exportFailed);
                                }

                                const blob = await response.blob();
                                const url = window.URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                a.href = url;
                                a.download = 'match-me-data-export-' + new Date().toISOString().split('T')[0] + '.json';
                                document.body.appendChild(a);
                                a.click();
                                document.body.removeChild(a);
                                window.URL.revokeObjectURL(url);

                                exportStatus.textContent = i18n.exportSuccess;
                                exportStatus.className = 'mm-gdpr-status mm-gdpr-status-success';
                            } catch (error) {
                                exportStatus.textContent = i18n.exportError + ' ' + (error.message || i18n.exportFailed);
                                exportStatus.className = 'mm-gdpr-status mm-gdpr-status-error';
                            } finally {
                                exportBtn.disabled = false;
                            }
                        });
                    }

                    // Delete data
                    const deleteBtn = document.getElementById('mm-delete-data-btn');
                    const deleteStatus = document.getElementById('mm-delete-status');
                    if (deleteBtn && deleteStatus) {
                        deleteBtn.addEventListener('click', async function() {
                            const first = window.confirm(i18n.deleteConfirm);
                            if (!first) return;

                            const typed = window.prompt(i18n.deletePrompt);
                            if (typed !== 'DELETE') {
                                window.alert(i18n.deleteCancelled);
                                return;
                            }

                            const second = window.confirm(i18n.deleteFinalConfirm);
                            if (!second) return;

                            deleteBtn.disabled = true;
                            deleteStatus.textContent = i18n.deleting;
                            deleteStatus.className = 'mm-gdpr-status mm-gdpr-status-info';

                            try {
                                const response = await fetch(API_BASE + '/gdpr/delete', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-WP-Nonce': nonce
                                    },
                                    body: JSON.stringify({ confirm: true })
                                });

                                if (!response.ok) {
                                    const error = await response.json();
                                    throw new Error(error.message || i18n.deleteFailed);
                                }

                                const result = await response.json();
                                deleteStatus.textContent = i18n.deleteSuccess;
                                deleteStatus.className = 'mm-gdpr-status mm-gdpr-status-success';
                                
                                setTimeout(function() {
                                    window.location.href = '<?php echo esc_js(wp_logout_url(home_url('/'))); ?>';
                                }, 2000);
                            } catch (error) {
                                deleteStatus.textContent = i18n.exportError + ' ' + (error.message || i18n.deleteFailed);
                                deleteStatus.className = 'mm-gdpr-status mm-gdpr-status-error';
                                deleteBtn.disabled = false;
                            }
                        });
                    }
                })();
            </script>
        </section>

        <section class="mm-section mm-mt-xl mm-divider-top">
            <h2><?php echo esc_html__('Delete account', 'match-me'); ?></h2>
            <p class="mm-muted mm-max-70ch">
                <?php echo esc_html__('This will permanently delete your account and', 'match-me'); ?> <strong><?php echo esc_html__('everything related to you', 'match-me'); ?></strong>, <?php echo esc_html__('including your profile information, quiz history, results, and comparisons. This action cannot be undone.', 'match-me'); ?>
            </p>

            <form id="mm-delete-account-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="match_me_delete_account">
                <?php wp_nonce_field('match_me_delete_account', 'match_me_delete_account_nonce'); ?>
                <input type="hidden" name="confirm_delete" value="">

                <button type="button" class="mm-profile-btn mm-profile-btn-danger" id="mm-delete-account-btn">
                    <?php echo esc_html__('Delete my account', 'match-me'); ?>
                </button>
            </form>

            <script>
                (function () {
                    const btn = document.getElementById('mm-delete-account-btn');
                    const form = document.getElementById('mm-delete-account-form');
                    if (!btn || !form) return;
                    const i18n = {
                        deleteConfirm: <?php echo wp_json_encode(__('Delete your account?\n\nThis will permanently delete your profile, quiz history, results, and comparisons. This cannot be undone.', 'match-me')); ?>,
                        deletePrompt: <?php echo wp_json_encode(__('Type DELETE to confirm account deletion:', 'match-me')); ?>,
                        deleteCancelled: <?php echo wp_json_encode(__('Account deletion cancelled.', 'match-me')); ?>,
                        deleteFinalConfirm: <?php echo wp_json_encode(__('Final confirmation: permanently delete your account now?', 'match-me')); ?>
                    };

                    btn.addEventListener('click', function () {
                        const first = window.confirm(i18n.deleteConfirm);
                        if (!first) return;

                        const typed = window.prompt(i18n.deletePrompt);
                        if (typed !== 'DELETE') {
                            window.alert(i18n.deleteCancelled);
                            return;
                        }

                        const second = window.confirm(i18n.deleteFinalConfirm);
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


