<?php
declare(strict_types=1);

namespace MatchMe\Wp\Ajax;

use MatchMe\Infrastructure\Db\QuizResultRepository;

final class SaveQuizResultsController
{
    public function __construct(private QuizResultRepository $repo)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_save_quiz_results', [$this, 'handle']);
        add_action('wp_ajax_nopriv_save_quiz_results', [$this, 'handle']);
    }

    public function handle(): void
    {
        check_ajax_referer('cq_quiz_nonce', 'security');

        if (!session_id()) {
            session_start();
        }

        $userId = is_user_logged_in() ? (int) get_current_user_id() : 9999;

        $quizId = isset($_POST['quiz_id']) ? (string) $_POST['quiz_id'] : '';
        $scores = isset($_POST['scores']) ? (string) $_POST['scores'] : '';
        $content = isset($_POST['content']) ? (string) $_POST['content'] : '';

        if ($quizId === '' || $scores === '') {
            wp_send_json_error('Missing required data');
            return;
        }

        try {
            $attemptId = $this->repo->insert($userId, $quizId, $scores, $content);
            $_SESSION['temp_results'][] = $attemptId;

            // Construct the result URL server-side
            $resultUrl = $this->buildResultUrl($quizId, $attemptId);

            wp_send_json_success([
                'message' => 'Results saved successfully',
                'inserted_id' => $attemptId,
                'result_url' => $resultUrl,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function buildResultUrl(string $quizId, int $attemptId): string
    {
        // First, try to get the current post if we're on a single page
        global $post;
        if ($post && isset($post->post_name) && $post->post_name === $quizId) {
            $permalink = get_permalink($post->ID);
            if ($permalink) {
                return rtrim($permalink, '/') . '/' . $attemptId . '/';
            }
        }

        // Try to find the post by slug
        $postBySlug = get_page_by_path($quizId, OBJECT, 'post');
        if ($postBySlug) {
            $permalink = get_permalink($postBySlug->ID);
            if ($permalink) {
                return rtrim($permalink, '/') . '/' . $attemptId . '/';
            }
        }

        // Fallback: construct URL manually using home_url (matches RsIdRewrite pattern)
        return home_url("/{$quizId}/{$attemptId}/");
    }
}


