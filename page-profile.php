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


