<?php
/**
 * Template Name: Results
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Wp\Container;

get_header();

?>

<main class="mm-profile container mm-page mm-page-900">
    <h1><?php echo esc_html__('My Results', 'match-me'); ?></h1>

    <?php if (!is_user_logged_in()) : ?>
        <p><?php echo esc_html__('Please log in to see your saved results.', 'match-me'); ?></p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => home_url('/results/')], home_url('/'))); ?>"><?php echo esc_html__('Login', 'match-me'); ?></a>
            &nbsp;<?php echo esc_html__('or', 'match-me'); ?>&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => home_url('/results/')], home_url('/'))); ?>"><?php echo esc_html__('Register', 'match-me'); ?></a>
        </p>
    <?php else : ?>
        <?php
        $userId = (int) get_current_user_id();
        $config = Container::config();
        $wpdb = Container::wpdb();

        $quizRepo = new QuizJsonRepository($config);
        $resultRepo = new ResultRepository($wpdb);
        $latestV2 = $resultRepo->latestByUserGroupedByQuizSlug($userId);

        function mm_quiz_title_results(QuizJsonRepository $repo, string $quizId): string {
            try {
                $q = $repo->load($quizId);
                return (string) (($q['meta']['title'] ?? '') ?: $quizId);
            } catch (\Throwable) {
                return $quizId;
            }
        }
        ?>

        <section class="mm-section mm-mb-lg">
            <p class="mm-muted mm-max-70ch">
                <?php echo esc_html__('Your latest saved result for each quiz. You can view your shareable result page, start a comparison, or take the quiz again.', 'match-me'); ?>
            </p>
        </section>

        <section class="mm-section">
            <h2><?php echo esc_html__('Latest Results', 'match-me'); ?></h2>
            <?php if ($latestV2 === []) : ?>
                <p><?php echo esc_html__('No results yet.', 'match-me'); ?></p>
                <p><a class="mm-pill-link" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html__('Explore quizzes', 'match-me'); ?></a></p>
            <?php else : ?>
                <ul class="mm-list">
                    <?php foreach ($latestV2 as $row) : ?>
                        <?php
                        $quizSlug = (string) $row['quiz_slug'];
                        $title = mm_quiz_title_results($quizRepo, $quizSlug);
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

        <section class="mm-section mm-mt-xl">
            <h2><?php echo esc_html__('Profile', 'match-me'); ?></h2>
            <p class="mm-muted"><?php echo esc_html__('Edit your name, photo, and notification preferences on your profile.', 'match-me'); ?></p>
            <p><a class="mm-pill-link" href="<?php echo esc_url(home_url('/profile/')); ?>"><?php echo esc_html__('Go to My Profile', 'match-me'); ?></a></p>
        </section>
    <?php endif; ?>
</main>

<?php
get_footer();


