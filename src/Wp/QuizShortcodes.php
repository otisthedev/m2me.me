<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;

final class QuizShortcodes
{
    public function __construct(
        private ThemeConfig $config,
        private QuizResultRepository $results,
        private QuizJsonRepository $quizzes,
    ) {
    }

    public function register(): void
    {
        add_shortcode('X_quiz', [$this, 'renderQuiz']);
        add_shortcode('match_me_quiz', [$this, 'renderQuizV2']);
        add_shortcode('previous_results', [$this, 'renderPreviousResults']);
        add_shortcode('post_titles_archive', [$this, 'renderPostTitlesArchive']);

        add_filter('the_excerpt', [$this, 'appendStartQuizLink']);
        add_action('init', [$this, 'logoutOnUrlParameter']);
        add_action('wp_head', [$this, 'manipulateDom']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * @param array<string,mixed> $atts
     */
    public function renderQuiz(array $atts): string
    {
        $quizId = isset($atts['id']) ? (string) $atts['id'] : '';
        $quizId = sanitize_file_name($quizId);
        if ($quizId === '') {
            return '<p>Quiz not found</p>';
        }

        try {
            $quizData = $this->quizzes->load($quizId);
        } catch (\Throwable) {
            return '<p>Quiz not found</p>';
        }

        $resultId = get_query_var('rsID') ? absint($_GET['rsID'] ?? 0) : 0;

        $previous = null;
        $userInfo = null;
        if ($resultId > 0) {
            $previous = $this->results->findByAttemptId($resultId, $quizId);
        } else {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                $previous = $this->results->findLatestByUserAndQuiz($userId, $quizId);
            }
        }

        if ($previous && isset($previous['user_id'])) {
            $userInfo = get_userdata((int) $previous['user_id']);
        }

        $hasPrevious = is_array($previous) && !empty($previous['content']);

        // Get site logo URL and site name
        $customLogoId = get_theme_mod('custom_logo');
        $logoUrl = '';
        if ($customLogoId) {
            $logoUrl = wp_get_attachment_image_url($customLogoId, 'full');
        }
        $siteName = get_bloginfo('name');

        $this->enqueueQuizRuntime($quizData, [
            'nonce' => wp_create_nonce('cq_quiz_nonce'),
            'hasPrevious' => $hasPrevious,
            'isLoggedIn' => is_user_logged_in(),
            'requireLogin' => $this->config->requireLoginForResults(),
            'logoUrl' => $logoUrl,
            'siteName' => $siteName,
        ]);

        ob_start();
        ?>
        <div class="cq-quiz-container" data-quiz-id="<?= esc_attr($quizId) ?>">
            <div class="cq-previous-results" style="<?= !$hasPrevious ? 'display: none;' : ''; ?>">
                <h3>
                    <?php
                    if ($userInfo) {
                        $firstName = (string) $userInfo->first_name;
                        echo $firstName !== '' ? 'Quiz results for ' . esc_html($firstName) : 'Quiz results';
                    } else {
                        echo 'Quiz results';
                    }
                    ?>
                </h3>
                <div class="cq-scores">
                    <?= $hasPrevious ? (string) $previous['content'] : '' ?>
                </div>
                <button class="cq-retake-btn">
                    <?php
                    $ownerId = $hasPrevious ? (int) ($previous['user_id'] ?? 0) : 0;
                    echo (is_user_logged_in() && (int) get_current_user_id() === $ownerId) ? 'Retake Quiz' : 'Take Quiz';
                    ?>
                </button>
                <div class="share-btn">
                    <?php
                    $ownerId = $hasPrevious ? (int) ($previous['user_id'] ?? 0) : 0;
                    echo (is_user_logged_in() && (int) get_current_user_id() === $ownerId) ? 'Share Results' : 'Share Quiz';
                    ?>
                </div>
            </div>

            <div class="cq-start-screen" style="<?= $hasPrevious ? 'display: none;' : ''; ?>">
                <h2><?= esc_html((string) $quizData['meta']['title']) ?></h2>
                <div class="cq-description"><?= wp_kses_post((string) $quizData['meta']['description']) ?></div>
                <button class="cq-start-btn">Start Quiz</button>
            </div>

            <div class="cq-questions" style="display:none;">
                <?php foreach ($quizData['questions'] as $index => $question) : ?>
                    <div class="cq-question" data-question-index="<?= (int) $index ?>">
                        <h3><?= esc_html((string) $question['text']) ?></h3>
                        <div class="cq-answers">
                            <?php foreach ($question['answers'] as $answer) : ?>
                                <label class="cq-answer">
                                    <input type="radio" name="question_<?= (int) $index ?>"
                                           data-scores="<?= esc_attr(wp_json_encode($answer['scores'])) ?>">
                                    <?= esc_html((string) $answer['text']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button class="cq-next-btn">Next Question</button>
            </div>

            <div class="cq-results" style="display:none;"></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * New modular quiz runner that submits answers to backend (no client-side scoring).
     *
     * Usage: [match_me_quiz id="communication-style-v1"]
     *
     * @param array<string,mixed> $atts
     */
    public function renderQuizV2(array $atts): string
    {
        $quizId = isset($atts['id']) ? (string) $atts['id'] : '';
        $quizId = sanitize_file_name($quizId);
        if ($quizId === '') {
            return '<p>Quiz not found</p>';
        }

        try {
            $quizData = $this->quizzes->load($quizId);
        } catch (\Throwable) {
            return '<p>Quiz not found</p>';
        }

        $this->enqueueQuizRuntimeV2($quizData, [
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'requireLogin' => $this->config->requireLoginForResults(),
        ]);

        ob_start();
        ?>
        <div data-match-me-quiz class="mmq" data-quiz-id="<?= esc_attr($quizId) ?>">
            <div class="mmq-error" style="display:none;"></div>

            <div class="mmq-screen">
                <div class="mmq-header">
                    <div class="mmq-title"><?= esc_html((string) ($quizData['meta']['title'] ?? 'Quiz')) ?></div>
                    <div class="mmq-progress"></div>
                </div>

                <div class="mmq-question">
                    <div class="mmq-question-text"></div>
                    <div class="mmq-options"></div>
                </div>

                <div class="mmq-actions">
                    <button type="button" class="mmq-back">Back</button>
                    <button type="button" class="mmq-next">Next</button>
                </div>
            </div>

            <div class="mmq-results" style="display:none;"></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function appendStartQuizLink(string $excerpt): string
    {
        return $excerpt . ' <a class="start-quiz" href="' . esc_url(get_permalink()) . '">Start Quiz</a>';
    }

    public function logoutOnUrlParameter(): void
    {
        if (!isset($_GET['logout'])) {
            return;
        }
        wp_logout();
        wp_redirect(home_url('/'));
        exit;
    }

    public function manipulateDom(): void
    {
        if (is_user_logged_in()) {
            echo '<style type="text/css">li#menu-item-468{display:none!important;}</style>';
            echo '<script>document.addEventListener("DOMContentLoaded",function(){var u=sessionStorage.getItem("quizRedirectURL");if(u){sessionStorage.removeItem("quizRedirectURL");window.location.href=u;}});</script>';
        } else {
            echo '<style type="text/css">li#menu-item-497{display:none!important;}</style>';
        }
    }

    public function enqueueAssets(): void
    {
        wp_enqueue_style('match-me-font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css', [], null);

        // Use file modification time for cache-busting in development (avoids stale mobile share code on LAN).
        $baseDir = (string) get_template_directory();
        $fallback = $this->config->themeVersion();

        $publicCss = $baseDir . '/assets/css/quiz-public.css';
        $popupCss = $baseDir . '/assets/css/quiz-popup.css';
        $publicJs = $baseDir . '/assets/js/quiz-public.js';
        $popupJs = $baseDir . '/assets/js/quiz-popup.js';

        $publicCssVer = is_file($publicCss) ? (string) filemtime($publicCss) : $fallback;
        $popupCssVer = is_file($popupCss) ? (string) filemtime($popupCss) : $fallback;
        $publicJsVer = is_file($publicJs) ? (string) filemtime($publicJs) : $fallback;
        $popupJsVer = is_file($popupJs) ? (string) filemtime($popupJs) : $fallback;

        wp_enqueue_style('match-me-quiz-public', get_template_directory_uri() . '/assets/css/quiz-public.css', [], $publicCssVer);
        wp_enqueue_style('match-me-quiz-popup', get_template_directory_uri() . '/assets/css/quiz-popup.css', [], $popupCssVer);
        wp_enqueue_script('match-me-quiz-public', get_template_directory_uri() . '/assets/js/quiz-public.js', [], $publicJsVer, true);
        wp_enqueue_script('match-me-quiz-popup', get_template_directory_uri() . '/assets/js/quiz-popup.js', [], $popupJsVer, true);
    }

    /**
     * @param array<string,mixed> $quizData
     * @param array<string,mixed> $vars
     */
    private function enqueueQuizRuntime(array $quizData, array $vars): void
    {
        $inline = 'window.cqData=' . wp_json_encode($quizData) . ';'
            . 'window.cqVars=' . wp_json_encode($vars) . ';'
            . 'window.ajaxurl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';';

        wp_add_inline_script('match-me-quiz-public', $inline, 'before');
    }

    /**
     * @param array<string,mixed> $quizData
     * @param array<string,mixed> $vars
     */
    private function enqueueQuizRuntimeV2(array $quizData, array $vars): void
    {
        $baseDir = (string) get_template_directory();
        $fallback = $this->config->themeVersion();

        $ajaxClient = $baseDir . '/assets/js/quiz-ajax-client.js';
        $resultsUi = $baseDir . '/assets/js/quiz-results-ui.js';
        $runner = $baseDir . '/assets/js/quiz-public-v2.js';
        $resultsCss = $baseDir . '/assets/css/quiz-results.css';
        $v2Css = $baseDir . '/assets/css/quiz-v2.css';

        $ajaxClientVer = is_file($ajaxClient) ? (string) filemtime($ajaxClient) : $fallback;
        $resultsUiVer = is_file($resultsUi) ? (string) filemtime($resultsUi) : $fallback;
        $runnerVer = is_file($runner) ? (string) filemtime($runner) : $fallback;
        $resultsCssVer = is_file($resultsCss) ? (string) filemtime($resultsCss) : $fallback;
        $v2CssVer = is_file($v2Css) ? (string) filemtime($v2Css) : $fallback;

        wp_enqueue_style('match-me-quiz-results', get_template_directory_uri() . '/assets/css/quiz-results.css', [], $resultsCssVer);
        wp_enqueue_style('match-me-quiz-v2', get_template_directory_uri() . '/assets/css/quiz-v2.css', [], $v2CssVer);
        wp_enqueue_script('match-me-quiz-ajax-client', get_template_directory_uri() . '/assets/js/quiz-ajax-client.js', [], $ajaxClientVer, true);
        wp_enqueue_script('match-me-quiz-results-ui', get_template_directory_uri() . '/assets/js/quiz-results-ui.js', [], $resultsUiVer, true);
        wp_enqueue_script('match-me-quiz-public-v2', get_template_directory_uri() . '/assets/js/quiz-public-v2.js', [], $runnerVer, true);

        $inline = 'window.matchMeQuizData=' . wp_json_encode($quizData) . ';'
            . 'window.matchMeQuizVars=' . wp_json_encode($vars) . ';';

        wp_add_inline_script('match-me-quiz-public-v2', $inline, 'before');
    }

    public function renderPreviousResults(): string
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return '<p>Please log in to view your attempts.</p>';
        }

        $attempts = $this->results->latestAttemptsByUserGroupedByQuiz($userId);
        if ($attempts === []) {
            return '<p>No attempts found.</p>';
        }

        $quizAttempts = [];
        foreach ($attempts as $row) {
            $quizAttempts[$row['quiz_id']] = $row['latest_attempt'];
        }

        $query = new \WP_Query([
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_name__in' => array_keys($quizAttempts),
            'post_type' => 'any',
        ]);

        $out = '';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $postId = get_the_ID();
                $slug = (string) get_post_field('post_name', $postId);
                $attemptId = $quizAttempts[$slug] ?? null;
                $url = $attemptId ? esc_url(get_permalink($postId) . $attemptId) : esc_url(get_permalink($postId));

                $out .= '<article class="post-' . esc_attr((string) $postId) . ' post type-post status-publish format-standard has-post-thumbnail hentry category-quizzes ast-grid-common-col ast-full-width ast-article-post remove-featured-img-padding" itemtype="https://schema.org/CreativeWork" itemscope="itemscope">';
                $out .= '<div class="ast-post-format- blog-layout-4 ast-article-inner"><div class="post-content ast-grid-common-col">';

                if (has_post_thumbnail()) {
                    $out .= '<div class="ast-blog-featured-section post-thumb ast-blog-single-element"><div class="post-thumb-img-content post-thumb">';
                    $out .= '<a href="' . esc_url($url) . '">' . get_the_post_thumbnail($postId, 'full', ['class' => 'attachment-full size-full wp-post-image', 'itemprop' => 'image']) . '</a>';
                    $out .= '</div></div>';
                }

                $out .= '<h2 class="entry-title ast-blog-single-element" itemprop="headline"><a href="' . esc_url($url) . '" rel="bookmark">' . esc_html(get_the_title()) . '</a></h2>';
                $out .= '<header class="entry-header ast-blog-single-element ast-blog-meta-container"><div class="entry-meta"><span class="posted-on"><span class="published" itemprop="datePublished">' . esc_html(get_the_date('F j, Y')) . '</span></span></div></header>';
                $out .= '<div class="ast-excerpt-container ast-blog-single-element"><a class="start-quiz" href="' . esc_url($url) . '">View Last Attempt</a></div>';
                $out .= '</div></div></article>';
            }
            wp_reset_postdata();
        } else {
            $out = '<p>No quizzes found.</p>';
        }

        return $out;
    }

    public function renderPostTitlesArchive(): string
    {
        $userId = (int) get_current_user_id();
        $quizAttempts = [];
        if ($userId > 0) {
            foreach ($this->results->latestAttemptsByUserGroupedByQuiz($userId) as $row) {
                $quizAttempts[$row['quiz_id']] = $row['latest_attempt'];
            }
        }

        $query = new \WP_Query([
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if (!$query->have_posts()) {
            return '<p>No posts found.</p>';
        }

        $out = '';
        while ($query->have_posts()) {
            $query->the_post();
            $postId = get_the_ID();
            $title = get_the_title();
            $link = get_permalink();
            $date = get_the_date('F j, Y');
            $thumb = get_the_post_thumbnail_url($postId, 'full');
            $slug = (string) get_post_field('post_name', $postId);
            $attemptId = $quizAttempts[$slug] ?? null;
            if ($attemptId) {
                $link = esc_url(get_permalink($postId) . $attemptId);
            } else {
                $link = esc_url($link);
            }

            $out .= '<article class="post-' . esc_attr((string) $postId) . ' post type-post status-publish format-standard has-post-thumbnail hentry category-quizzes ast-grid-common-col ast-full-width ast-article-post remove-featured-img-padding" id="post-' . esc_attr((string) $postId) . '" itemtype="https://schema.org/CreativeWork" itemscope="itemscope">';
            $out .= '<div class="ast-post-format- blog-layout-4 ast-article-inner"><div class="post-content ast-grid-common-col">';

            if ($thumb) {
                $out .= '<div class="ast-blog-featured-section post-thumb ast-blog-single-element"><div class="post-thumb-img-content post-thumb">';
                $out .= '<a href="' . esc_url($link) . '"><img width="2048" height="2048" src="' . esc_url($thumb) . '" class="attachment-full size-full wp-post-image" alt="" itemprop="image" decoding="async"></a>';
                $out .= '</div></div>';
            }

            $out .= '<h2 class="entry-title ast-blog-single-element" itemprop="headline"><a href="' . esc_url($link) . '" rel="bookmark">' . esc_html((string) $title) . '</a></h2>';
            $out .= '<header class="entry-header ast-blog-single-element ast-blog-meta-container"><div class="entry-meta"><span class="posted-on"><span class="published" itemprop="datePublished"> ' . esc_html((string) $date) . ' </span></span></div></header>';
            $out .= '<div class="ast-excerpt-container ast-blog-single-element">';
            $out .= $attemptId ? '<a class="start-quiz" href="' . esc_url($link) . '">View Result</a>' : '<a class="start-quiz" href="' . esc_url($link) . '">Start Quiz</a>';
            $out .= '</div>';
            $out .= '<p class="ast-blog-single-element ast-read-more-container read-more"><a class="" href="' . esc_url($link) . '"> <span class="screen-reader-text">' . esc_html((string) $title) . '</span> Read More »</a></p>';
            $out .= '<div class="entry-content clear" itemprop="text"></div>';
            $out .= '</div></div></article>';
        }
        wp_reset_postdata();

        return $out;
    }
}


