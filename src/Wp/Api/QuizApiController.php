<?php
declare(strict_types=1);

namespace MatchMe\Wp\Api;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Quiz\MatchingService;
use MatchMe\Quiz\QuizCalculator;
use MatchMe\Quiz\ShareTokenGenerator;

/**
 * WordPress REST API controller for quiz submission and matching.
 */
final class QuizApiController
{
    private const NAMESPACE = 'match-me/v1';
    private const RATE_LIMIT_KEY_PREFIX = 'match_me_compare_';
    private const RATE_LIMIT_MAX = 10;
    private const RATE_LIMIT_WINDOW = 3600; // 1 hour

    public function __construct(
        private QuizJsonRepository $quizRepository,
        private QuizCalculator $calculator,
        private MatchingService $matchingService,
        private ResultRepository $resultRepository,
        private ComparisonRepository $comparisonRepository,
        private ShareTokenGenerator $tokenGenerator,
        private ThemeConfig $config
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/quiz/(?P<quiz_id>[a-zA-Z0-9_-]+)/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'submitQuiz'],
            'permission_callback' => [$this, 'checkSubmitPermission'],
            'args' => [
                'quiz_id' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => fn($param) => is_string($param) && $param !== '',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/result/(?P<share_token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getResult'],
            'permission_callback' => '__return_true',
            'args' => [
                'share_token' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/result/(?P<share_token>[a-zA-Z0-9]+)/compare', [
            'methods' => 'POST',
            'callback' => [$this, 'compareResults'],
            'permission_callback' => '__return_true',
            'args' => [
                'share_token' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/result/(?P<result_id>\d+)/revoke', [
            'methods' => 'POST',
            'callback' => [$this, 'revokeToken'],
            'permission_callback' => [$this, 'checkRevokePermission'],
            'args' => [
                'result_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);
    }

    /**
     * Check if user can submit quiz.
     */
    public function checkSubmitPermission(): bool
    {
        if ($this->config->requireLoginForResults()) {
            return is_user_logged_in();
        }
        return true;
    }

    /**
     * Check if user can revoke token (must own the result).
     */
    public function checkRevokePermission(\WP_REST_Request $request): bool
    {
        $resultId = (int) $request->get_param('result_id');
        $result = $this->resultRepository->findById($resultId);

        if ($result === null) {
            return false;
        }

        $userId = (int) get_current_user_id();
        if ($userId === 0) {
            return false;
        }

        return (int) ($result['user_id'] ?? 0) === $userId;
    }

    /**
     * POST /wp-json/match-me/v1/quiz/{quiz_id}/submit
     */
    public function submitQuiz(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $quizId = $request->get_param('quiz_id');
        $body = $request->get_json_params();

        try {
            // Load quiz configuration
            $quizConfig = $this->quizRepository->load($quizId);
            $quizVersion = $quizConfig['meta']['version'] ?? '1.0';

            // Validate answers
            $answers = $body['answers'] ?? [];
            if (!is_array($answers) || empty($answers)) {
                return new \WP_Error('invalid_answers', 'Answers array is required', ['status' => 400]);
            }

            // Calculate trait vector
            $traitVector = $this->calculator->calculateTraitVector($answers, $quizConfig);

            // Generate share token
            $shareToken = $this->tokenGenerator->generate();

            // Determine share mode (default: share_match for new system)
            $shareMode = $body['share_mode'] ?? 'share_match';
            if (!in_array($shareMode, ['private', 'share', 'share_match'], true)) {
                $shareMode = 'share_match';
            }

            // Get user ID (nullable for anonymous)
            $userId = is_user_logged_in() ? (int) get_current_user_id() : null;

            // Get quiz_id from database (we need to look it up or create it)
            // For now, we'll use a slug-based lookup - in production, you'd have a quizzes table
            $dbQuizId = $this->getOrCreateQuizId($quizId, $quizConfig);

            // Store result
            $resultId = $this->resultRepository->insert(
                $dbQuizId,
                $quizId, // quiz_slug
                $userId,
                $traitVector,
                $shareToken,
                $shareMode,
                $quizVersion
            );

            // Generate share URLs
            $shareUrls = [
                // API URLs are guaranteed to exist (front-end pages are optional).
                'view' => rest_url(self::NAMESPACE . '/result/' . $shareToken),
                'compare' => rest_url(self::NAMESPACE . '/result/' . $shareToken . '/compare'),
            ];

            return new \WP_REST_Response([
                'result_id' => $resultId,
                'trait_vector' => $traitVector,
                'share_token' => $shareToken,
                'share_urls' => $shareUrls,
                'quiz_version' => $quizVersion,
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_request', $e->getMessage(), ['status' => 400]);
        } catch (\RuntimeException $e) {
            return new \WP_Error('server_error', $e->getMessage(), ['status' => 500]);
        } catch (\Throwable $e) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * GET /wp-json/match-me/v1/result/{share_token}
     */
    public function getResult(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $shareToken = $request->get_param('share_token');

        try {
            $result = $this->resultRepository->findByShareToken($shareToken);

            if ($result === null) {
                return new \WP_Error('not_found', 'Result not found or token invalid', ['status' => 404]);
            }

            // Check if revoked
            if (!empty($result['revoked_at'])) {
                return new \WP_Error('forbidden', 'Token has been revoked', ['status' => 403]);
            }

            // Check share mode
            $shareMode = $result['share_mode'] ?? 'private';
            if ($shareMode === 'private') {
                $userId = (int) get_current_user_id();
                $resultUserId = (int) ($result['user_id'] ?? 0);
                if ($userId === 0 || $userId !== $resultUserId) {
                    return new \WP_Error('forbidden', 'This result is private', ['status' => 403]);
                }
            }

            // Decode trait vector
            $traitVector = json_decode($result['trait_vector'] ?? '{}', true);
            if (!is_array($traitVector)) {
                $traitVector = [];
            }

            $quizTitle = 'Quiz Result';
            $quizSlug = (string) ($result['quiz_slug'] ?? '');
            if ($quizSlug !== '') {
                try {
                    $quizConfig = $this->quizRepository->load($quizSlug);
                    $quizTitle = (string) (($quizConfig['meta']['title'] ?? '') ?: $quizTitle);
                } catch (\Throwable) {
                    // Ignore and fallback.
                }
            }

            // Generate textual summary (simplified - in production, use a more sophisticated generator)
            $textualSummary = $this->generateTextualSummary($traitVector);

            return new \WP_REST_Response([
                'result_id' => (int) $result['result_id'],
                'quiz_title' => $quizTitle,
                'trait_summary' => $traitVector,
                'textual_summary' => $textualSummary,
                'share_mode' => $shareMode,
                'can_compare' => $shareMode === 'share_match',
                'created_at' => $result['created_at'] ?? '',
            ], 200);

        } catch (\Throwable $e) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * POST /wp-json/match-me/v1/result/{share_token}/compare
     */
    public function compareResults(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $shareToken = $request->get_param('share_token');
        $body = $request->get_json_params();

        // Rate limiting
        if (!$this->checkRateLimit()) {
            return new \WP_Error('rate_limit', 'Too many requests. Please try again later.', [
                'status' => 429,
                'retry_after' => self::RATE_LIMIT_WINDOW,
            ]);
        }

        try {
            // Get first result
            $resultA = $this->resultRepository->findByShareToken($shareToken);
            if ($resultA === null) {
                return new \WP_Error('not_found', 'Result not found', ['status' => 404]);
            }

            // Check share mode allows comparison
            $shareMode = $resultA['share_mode'] ?? 'private';
            if ($shareMode !== 'share_match') {
                return new \WP_Error('forbidden', 'This result does not allow comparison', ['status' => 403]);
            }

            $resultIdA = (int) $resultA['result_id'];

            // Get or create second result
            if (isset($body['result_id'])) {
                // Use existing result
                $resultIdB = (int) $body['result_id'];
                $resultB = $this->resultRepository->findById($resultIdB);
                if ($resultB === null) {
                    return new \WP_Error('not_found', 'Second result not found', ['status' => 404]);
                }
            } elseif (isset($body['answers']) && isset($body['quiz_id'])) {
                // Calculate new result from answers
                $quizId = $body['quiz_id'];
                $quizConfig = $this->quizRepository->load($quizId);
                $answers = $body['answers'];

                $traitVector = $this->calculator->calculateTraitVector($answers, $quizConfig);
                $shareTokenB = $this->tokenGenerator->generate();
                $quizVersion = $quizConfig['meta']['version'] ?? '1.0';
                $userId = is_user_logged_in() ? (int) get_current_user_id() : null;
                $dbQuizId = $this->getOrCreateQuizId($quizId, $quizConfig);

                $resultIdB = $this->resultRepository->insert(
                    $dbQuizId,
                    $quizId, // quiz_slug
                    $userId,
                    $traitVector,
                    $shareTokenB,
                    'private',
                    $quizVersion
                );
            } else {
                return new \WP_Error('invalid_request', 'Either result_id or answers+quiz_id required', ['status' => 400]);
            }

            // Get algorithm
            $algorithm = $body['algorithm'] ?? 'cosine';
            if (!in_array($algorithm, ['cosine', 'euclidean', 'absolute'], true)) {
                $algorithm = 'cosine';
            }

            // Compute match
            $matchResult = $this->matchingService->matchResults($resultIdA, $resultIdB, $algorithm);

            // Store comparison
            $comparisonId = $this->comparisonRepository->insert(
                $resultIdA,
                $resultIdB,
                $matchResult['match_score'],
                $matchResult['breakdown'],
                $matchResult['algorithm_used']
            );

            return new \WP_REST_Response([
                'comparison_id' => $comparisonId,
                'match_score' => $matchResult['match_score'],
                'breakdown' => $matchResult['breakdown'],
                'algorithm_used' => $matchResult['algorithm_used'],
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_request', $e->getMessage(), ['status' => 400]);
        } catch (\Throwable $e) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * POST /wp-json/match-me/v1/result/{result_id}/revoke
     */
    public function revokeToken(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $resultId = (int) $request->get_param('result_id');

        try {
            $success = $this->resultRepository->revokeToken($resultId);

            if (!$success) {
                return new \WP_Error('not_found', 'Result not found', ['status' => 404]);
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Token revoked successfully',
            ], 200);

        } catch (\Throwable $e) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * Check rate limit for compare endpoint.
     */
    private function checkRateLimit(): bool
    {
        $ip = $this->getClientIp();
        $hour = (int) (time() / self::RATE_LIMIT_WINDOW);
        $key = self::RATE_LIMIT_KEY_PREFIX . $ip . '_' . $hour;

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }

        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field((string) $_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Generate textual summary from trait vector.
     */
    private function generateTextualSummary(array $traitVector): string
    {
        if (empty($traitVector)) {
            return 'No trait data available.';
        }

        $traits = array_keys($traitVector);
        $dominantTraits = [];
        foreach ($traitVector as $trait => $value) {
            if ($value >= 0.7) {
                $dominantTraits[] = ucfirst(str_replace('_', ' ', $trait));
            }
        }

        if (empty($dominantTraits)) {
            return 'Your profile shows a balanced mix of traits.';
        }

        if (count($dominantTraits) === 1) {
            return "Your profile highlights {$dominantTraits[0]} as a key trait.";
        }

        $lastTrait = array_pop($dominantTraits);
        $traitList = implode(', ', $dominantTraits) . ', and ' . $lastTrait;
        return "Your profile highlights key traits such as {$traitList}.";
    }

    /**
     * Get or create quiz ID in database.
     * For now, returns a placeholder - in production, this would look up/create in quizzes table.
     */
    private function getOrCreateQuizId(string $quizSlug, array $quizConfig): int
    {
        // TODO: Implement proper quiz lookup/creation
        // For now, return a hash-based ID
        return abs(crc32($quizSlug));
    }
}

