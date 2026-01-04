<?php
declare(strict_types=1);

namespace MatchMe\Wp\Api;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\QuizRepository;
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
    private const NOTIFICATIONS_LAST_SEEN_META = 'match_me_last_seen_comparison_ts';
    private const EMAIL_NOTIFY_META = 'match_me_email_compare_notify'; // optional future opt-out

    public function __construct(
        private QuizJsonRepository $quizRepository,
        private QuizCalculator $calculator,
        private MatchingService $matchingService,
        private ResultRepository $resultRepository,
        private ComparisonRepository $comparisonRepository,
        private QuizRepository $quizDbRepository,
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

        register_rest_route(self::NAMESPACE, '/comparison/(?P<share_token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getComparison'],
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

        register_rest_route(self::NAMESPACE, '/notifications/comparisons', [
            'methods' => 'GET',
            'callback' => [$this, 'getComparisonNotifications'],
            'permission_callback' => fn(\WP_REST_Request $r) => is_user_logged_in() && $this->hasRestNonce($r),
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/comparisons/seen', [
            'methods' => 'POST',
            'callback' => [$this, 'markComparisonNotificationsSeen'],
            'permission_callback' => fn(\WP_REST_Request $r) => is_user_logged_in() && $this->hasRestNonce($r),
        ]);
    }

    private function hasRestNonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce === '') {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, 'wp_rest');
    }

    /**
     * GET /wp-json/match-me/v1/notifications/comparisons
     */
    public function getComparisonNotifications(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $lastSeen = (int) get_user_meta($userId, self::NOTIFICATIONS_LAST_SEEN_META, true);
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = max(1, min(20, $limit));

        $rows = $this->comparisonRepository->latestForOwnerUser($userId, $limit, $lastSeen);

        $items = [];
        $nowTs = (int) current_time('timestamp');
        foreach ($rows as $row) {
            $viewerId = isset($row['viewer_user_id']) ? (int) $row['viewer_user_id'] : 0;
            $name = 'Someone';
            $avatar = '';
            if ($viewerId > 0) {
                $u = get_user_by('id', $viewerId);
                if ($u instanceof \WP_User) {
                    $first = (string) get_user_meta($viewerId, 'first_name', true);
                    $name = $first !== '' ? $first : (string) ($u->display_name ?: $name);
                    $avatar = (string) get_avatar_url($viewerId, ['size' => 128]);
                }
            }

            $createdAt = (string) ($row['created_at'] ?? '');
            $createdTs = $createdAt !== '' ? (int) strtotime($createdAt) : 0;
            $ago = $createdTs > 0 ? human_time_diff($createdTs, $nowTs) . ' ago' : '';

            $shareToken = (string) ($row['share_token'] ?? '');
            if ($shareToken === '') {
                continue;
            }

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'viewer' => [
                    'id' => $viewerId,
                    'name' => $name,
                    'avatar_url' => $avatar,
                ],
                'match_score' => (float) ($row['match_score'] ?? 0.0),
                'created_at' => $createdAt,
                'ago' => $ago,
                'share_url' => home_url('/match/' . $shareToken . '/'),
            ];
        }

        return new \WP_REST_Response([
            'unseen_count' => count($items),
            'items' => $items,
            'last_seen_ts' => $lastSeen,
        ], 200);
    }

    /**
     * POST /wp-json/match-me/v1/notifications/comparisons/seen
     */
    public function markComparisonNotificationsSeen(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        $now = (int) current_time('timestamp');
        update_user_meta($userId, self::NOTIFICATIONS_LAST_SEEN_META, $now);

        return new \WP_REST_Response([
            'success' => true,
            'last_seen_ts' => $now,
        ], 200);
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

            // Generate deterministic short + long narrative
            $traitLabels = $this->extractTraitLabels($quizConfig);
            $traitDescriptions = $this->extractTraitDescriptions($quizConfig);
            [$summaryShort, $summaryLong] = $this->generateTextualSummaries($traitVector, $traitLabels, $traitDescriptions, (string) ($quizConfig['meta']['aspect'] ?? ''), $quizConfig);

            // Store result
            $resultId = $this->resultRepository->insert(
                $dbQuizId,
                $quizId, // quiz_slug
                $userId,
                $traitVector,
                $shareToken,
                $shareMode,
                $quizVersion,
                $summaryShort,
                $summaryLong,
                (string) $quizVersion
            );

            // Store anonymous result ID in session for later assignment
            if ($userId === null) {
                if (!session_id()) {
                    session_start();
                }
                if (!isset($_SESSION['match_me_temp_results'])) {
                    $_SESSION['match_me_temp_results'] = [];
                }
                $_SESSION['match_me_temp_results'][] = $resultId;
            }

            // Generate share URLs
            $shareUrls = [
                // Frontend URLs (human-friendly).
                'view' => home_url('/result/' . $shareToken . '/'),
                'compare' => home_url('/compare/' . $shareToken . '/'),
                // API URLs (machine-friendly, still useful for debugging).
                'api_view' => rest_url(self::NAMESPACE . '/result/' . $shareToken),
                'api_compare' => rest_url(self::NAMESPACE . '/result/' . $shareToken . '/compare'),
            ];

            return new \WP_REST_Response([
                'result_id' => $resultId,
                'trait_vector' => $traitVector,
                'share_token' => $shareToken,
                'share_urls' => $shareUrls,
                'quiz_version' => $quizVersion,
                'textual_summary' => $summaryShort,
                'textual_summary_short' => $summaryShort,
                'textual_summary_long' => $summaryLong,
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(
                'invalid_request',
                $e->getMessage() ?: 'Invalid request. Please check your input and try again.',
                ['status' => 400, 'error_code' => 'VALIDATION_ERROR']
            );
        } catch (\RuntimeException $e) {
            return new \WP_Error(
                'server_error',
                'An error occurred while processing your request. Please try again later.',
                ['status' => 500, 'error_code' => 'SERVER_ERROR', 'details' => $e->getMessage()]
            );
        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An unexpected error occurred. Please try again later.',
                ['status' => 500, 'error_code' => 'UNEXPECTED_ERROR']
            );
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
                return new \WP_Error(
                    'not_found',
                    'The quiz result you are looking for could not be found. The link may be incorrect or the result may have been deleted.',
                    ['status' => 404, 'error_code' => 'RESULT_NOT_FOUND']
                );
            }

            // Check if revoked
            if (!empty($result['revoked_at'])) {
                return new \WP_Error(
                    'forbidden',
                    'This result is no longer available. The share link has been revoked by the owner.',
                    ['status' => 403, 'error_code' => 'TOKEN_REVOKED']
                );
            }

            // Check share mode
            $shareMode = $result['share_mode'] ?? 'private';
            if ($shareMode === 'private') {
                $userId = (int) get_current_user_id();
                $resultUserId = (int) ($result['user_id'] ?? 0);
                if ($userId === 0 || $userId !== $resultUserId) {
                    return new \WP_Error(
                        'forbidden',
                        'This result is private and can only be viewed by its owner. Please log in with the account that created this result.',
                        ['status' => 403, 'error_code' => 'RESULT_PRIVATE']
                    );
                }
            }

            // Decode trait vector
            $traitVector = json_decode($result['trait_vector'] ?? '{}', true);
            if (!is_array($traitVector)) {
                $traitVector = [];
            }

            $quizTitle = 'Quiz Result';
            $traitLabels = [];
            $traitDescriptions = [];
            $quizAspect = '';
            $currentQuizVersion = '';
            $quizSlug = (string) ($result['quiz_slug'] ?? '');
            if ($quizSlug !== '') {
                try {
                    $quizConfig = $this->quizRepository->load($quizSlug);
                    $quizTitle = (string) (($quizConfig['meta']['title'] ?? '') ?: $quizTitle);
                    $traitLabels = $this->extractTraitLabels($quizConfig);
                    $traitDescriptions = $this->extractTraitDescriptions($quizConfig);
                    $quizAspect = (string) ($quizConfig['meta']['aspect'] ?? '');
                    $currentQuizVersion = (string) (($quizConfig['meta']['version'] ?? '') ?: '');
                } catch (\Throwable) {
                    // Ignore and fallback.
                }
            }

            // Prefer stored narrative (stable). If missing (older rows), generate + backfill.
            $storedShort = isset($result['textual_summary_short']) ? (string) ($result['textual_summary_short'] ?? '') : '';
            $storedLong = isset($result['textual_summary_long']) ? (string) ($result['textual_summary_long'] ?? '') : '';
            $storedQuizVer = isset($result['textual_summary_quiz_version']) ? (string) ($result['textual_summary_quiz_version'] ?? '') : '';

            // Regenerate only if missing OR the quiz version changed since the summaries were generated.
            $shouldRegenerate = ($storedShort === '' || $storedLong === '');
            if ($currentQuizVersion !== '' && $storedQuizVer !== $currentQuizVersion) {
                $shouldRegenerate = true;
            }

            if ($shouldRegenerate) {
                // Load quiz config if not already loaded
                $quizConfigForRegen = $quizConfig ?? [];
                if (empty($quizConfigForRegen) && !empty($quizSlug)) {
                    try {
                        $quizConfigForRegen = $this->quizRepository->load($quizSlug);
                    } catch (\Throwable) {
                        $quizConfigForRegen = [];
                    }
                }
                [$genShort, $genLong] = $this->generateTextualSummaries($traitVector, $traitLabels, $traitDescriptions, (string) $quizAspect, $quizConfigForRegen);
                $storedShort = $storedShort !== '' ? $storedShort : $genShort;
                $storedLong = $storedLong !== '' ? $storedLong : $genLong;
                $this->resultRepository->updateSummaries(
                    (int) $result['result_id'],
                    $storedShort,
                    $storedLong,
                    $currentQuizVersion !== '' ? $currentQuizVersion : (string) ($result['quiz_version'] ?? '')
                );
            }

            $owner = null;
            $ownerId = (int) ($result['user_id'] ?? 0);
            if ($ownerId > 0) {
                $u = get_user_by('id', $ownerId);
                if ($u instanceof \WP_User) {
                    $first = (string) get_user_meta($ownerId, 'first_name', true);
                    $name = $first !== '' ? $first : (string) ($u->display_name ?: 'Someone');
                    $owner = [
                        'id' => $ownerId,
                        'name' => $name,
                        'avatar_url' => (string) get_avatar_url($ownerId, ['size' => 256]),
                    ];
                }
            }

            $shareUrls = [
                // Frontend URLs (human-friendly).
                'view' => home_url('/result/' . $shareToken . '/'),
                'compare' => home_url('/compare/' . $shareToken . '/'),
                // API URLs (machine-friendly).
                'api_view' => rest_url(self::NAMESPACE . '/result/' . $shareToken),
                'api_compare' => rest_url(self::NAMESPACE . '/result/' . $shareToken . '/compare'),
            ];

            return new \WP_REST_Response([
                'result_id' => (int) $result['result_id'],
                'quiz_title' => $quizTitle,
                'quiz_slug' => $quizSlug,
                'trait_summary' => $traitVector,
                'trait_labels' => $traitLabels,
                'textual_summary' => $storedShort, // backwards compatible
                'textual_summary_short' => $storedShort,
                'textual_summary_long' => $storedLong,
                'share_token' => (string) $shareToken,
                'share_urls' => $shareUrls,
                'owner' => $owner,
                'share_mode' => $shareMode,
                'can_compare' => $shareMode === 'share_match',
                'created_at' => $result['created_at'] ?? '',
            ], 200);

        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An unexpected error occurred while retrieving the quiz result. Please try again later.',
                ['status' => 500, 'error_code' => 'UNEXPECTED_ERROR']
            );
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
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult['allowed']) {
            $remaining = $rateLimitResult['remaining'] ?? 0;
            $resetAt = $rateLimitResult['reset_at'] ?? 0;
            $limit = $rateLimitResult['limit'] ?? self::RATE_LIMIT_MAX;
            $resetIn = $resetAt > time() ? $resetAt - time() : 0;
            $resetInMinutes = max(1, (int) ceil($resetIn / 60));
            
            return new \WP_Error(
                'rate_limit',
                sprintf(
                    'You have reached the comparison limit (%d comparisons per hour). Please wait %d minute(s) before trying again.',
                    $limit,
                    $resetInMinutes
                ),
                [
                    'status' => 429,
                    'retry_after' => $resetIn,
                    'limit' => $limit,
                    'remaining' => $remaining,
                    'reset_at' => $resetAt,
                    'error_code' => 'RATE_LIMIT_EXCEEDED'
                ]
            );
        }

        try {
            // Get first result
            $resultA = $this->resultRepository->findByShareToken($shareToken);
            if ($resultA === null) {
                return new \WP_Error(
                    'not_found',
                    'The quiz result you are trying to compare with could not be found. The link may be incorrect.',
                    ['status' => 404, 'error_code' => 'RESULT_A_NOT_FOUND']
                );
            }

            // Check share mode allows comparison
            $shareMode = $resultA['share_mode'] ?? 'private';
            if ($shareMode !== 'share_match') {
                return new \WP_Error(
                    'forbidden',
                    'This result does not allow comparisons. The owner has restricted sharing options.',
                    ['status' => 403, 'error_code' => 'COMPARISON_NOT_ALLOWED']
                );
            }

            $resultIdA = (int) $resultA['result_id'];

            // Get or create second result
            if (isset($body['result_id'])) {
                // Use existing result
                $resultIdB = (int) $body['result_id'];
                $resultB = $this->resultRepository->findById($resultIdB);
                if ($resultB === null) {
                    return new \WP_Error(
                        'not_found',
                        'The second quiz result could not be found. It may have been deleted.',
                        ['status' => 404, 'error_code' => 'RESULT_B_NOT_FOUND']
                    );
                }
                $traitVectorB = json_decode($resultB['trait_vector'] ?? '{}', true);
                if (!is_array($traitVectorB)) {
                    $traitVectorB = [];
                }
            } elseif (isset($body['answers']) && isset($body['quiz_id'])) {
                // Calculate new result from answers
                $quizId = $body['quiz_id'];
                $quizConfig = $this->quizRepository->load($quizId);
                $answers = $body['answers'];

                $traitVector = $this->calculator->calculateTraitVector($answers, $quizConfig);
                $traitVectorB = $traitVector;
                $shareTokenB = $this->tokenGenerator->generate();
                $quizVersion = $quizConfig['meta']['version'] ?? '1.0';
                $userId = is_user_logged_in() ? (int) get_current_user_id() : null;
                $dbQuizId = $this->getOrCreateQuizId($quizId, $quizConfig);

                $traitLabelsB = $this->extractTraitLabels($quizConfig);
                $traitDescriptionsB = $this->extractTraitDescriptions($quizConfig);
                [$summaryShortB, $summaryLongB] = $this->generateTextualSummaries($traitVectorB, $traitLabelsB, $traitDescriptionsB, (string) ($quizConfig['meta']['aspect'] ?? ''), $quizConfig);

                $resultIdB = $this->resultRepository->insert(
                    $dbQuizId,
                    $quizId, // quiz_slug
                    $userId,
                    $traitVector,
                    $shareTokenB,
                    'private',
                    $quizVersion,
                    $summaryShortB,
                    $summaryLongB,
                    (string) $quizVersion
                );
            } else {
                return new \WP_Error(
                    'invalid_request',
                    'To compare results, please provide either an existing result_id or submit new quiz answers with quiz_id.',
                    ['status' => 400, 'error_code' => 'MISSING_COMPARISON_DATA']
                );
            }

            // Validate quiz slug compatibility (must be from same quiz)
            $quizSlugA = (string) ($resultA['quiz_slug'] ?? '');
            $quizSlugB = isset($body['result_id']) ? (string) ($resultB['quiz_slug'] ?? '') : '';
            if (isset($body['answers'])) {
                // Get quiz slug from quiz_id for new result
                $quizSlugB = (string) ($body['quiz_id'] ?? '');
            }
            
            if ($quizSlugB !== '' && $quizSlugA !== '' && $quizSlugA !== $quizSlugB) {
                return new \WP_Error(
                    'quiz_mismatch',
                    'Cannot compare results from different quizzes. Both results must be from the same quiz.',
                    ['status' => 400, 'quiz_slug_a' => $quizSlugA, 'quiz_slug_b' => $quizSlugB]
                );
            }
            
            // Validate quiz version compatibility
            $quizVersionA = (string) ($resultA['quiz_version'] ?? '');
            $quizVersionB = isset($body['result_id']) ? (string) ($resultB['quiz_version'] ?? '') : '';
            if (isset($body['answers'])) {
                // Get version from quiz config for new result
                try {
                    $quizConfig = $this->quizRepository->load($body['quiz_id']);
                    $quizVersionB = (string) ($quizConfig['meta']['version'] ?? '');
                } catch (\Throwable) {
                    // Will be handled below
                }
            }
            
            if ($quizVersionB !== '' && $quizVersionA !== '' && $quizVersionA !== $quizVersionB) {
                return new \WP_Error(
                    'version_mismatch',
                    'Cannot compare results from different quiz versions. Please retake the quiz with the latest version.',
                    ['status' => 400, 'quiz_version_a' => $quizVersionA, 'quiz_version_b' => $quizVersionB]
                );
            }

            // Check for duplicate comparison
            $existingComparison = $this->comparisonRepository->findExistingComparison($resultIdA, $resultIdB);
            if ($existingComparison !== null) {
                // Return existing comparison instead of creating duplicate
                $existingToken = (string) ($existingComparison['share_token'] ?? '');
                if ($existingToken !== '') {
                    return new \WP_REST_Response([
                        'comparison_id' => (int) ($existingComparison['id'] ?? 0),
                        'share_token' => $existingToken,
                        'match_score' => (float) ($existingComparison['match_score'] ?? 0.0),
                        'share_url' => home_url('/match/' . $existingToken . '/'),
                        'api_url' => rest_url(self::NAMESPACE . '/comparison/' . $existingToken),
                        'is_existing' => true,
                        'message' => 'Comparison already exists',
                    ], 200);
                }
            }

            // Get algorithm
            $algorithm = $body['algorithm'] ?? 'cosine';
            if (!in_array($algorithm, ['cosine', 'euclidean', 'absolute'], true)) {
                $algorithm = 'cosine';
            }

            // Compute match
            $matchResult = $this->matchingService->matchResults($resultIdA, $resultIdB, $algorithm);

            // Determine participant display info
            $ownerA = null;
            $ownerIdA = (int) ($resultA['user_id'] ?? 0);
            if ($ownerIdA > 0) {
                $uA = get_user_by('id', $ownerIdA);
                if ($uA instanceof \WP_User) {
                    $first = (string) get_user_meta($ownerIdA, 'first_name', true);
                    $name = $first !== '' ? $first : (string) ($uA->display_name ?: 'Someone');
                    $ownerA = [
                        'id' => $ownerIdA,
                        'name' => $name,
                        'avatar_url' => (string) get_avatar_url($ownerIdA, ['size' => 256]),
                    ];
                }
            }

            $viewer = [
                'id' => (int) get_current_user_id(),
                'name' => is_user_logged_in() ? (string) wp_get_current_user()->display_name : 'You',
                'avatar_url' => is_user_logged_in() ? (string) get_avatar_url((int) get_current_user_id(), ['size' => 256]) : '',
            ];

            // Trait labels for this quiz (for rendering "your result" nicely without needing to view a private token).
            $traitLabels = [];
            try {
                $quizSlug = (string) ($resultA['quiz_slug'] ?? '');
                if ($quizSlug !== '') {
                    $quizConfigA = $this->quizRepository->load($quizSlug);
                    $traits = $quizConfigA['traits'] ?? [];
                    foreach ($traits as $traitId => $traitData) {
                        if (is_array($traitData) && isset($traitData['label'])) {
                            $traitLabels[$traitId] = (string) $traitData['label'];
                        }
                    }
                }
            } catch (\Throwable) {
                // ignore
            }

            // Store shareable comparison token
            $comparisonShareToken = $this->tokenGenerator->generate();

            // Generate deterministic comparison narrative (short + long), cached on comparison row.
            $cmpNameA = ($ownerA && isset($ownerA['name'])) ? (string) $ownerA['name'] : 'Them';
            $cmpNameB = (is_array($viewer) && isset($viewer['name'])) ? (string) $viewer['name'] : 'You';
            $quizTitle = 'Quiz Results';
            $quizAspect = '';
            $quizVersionA = '';
            $cmpMinWords = 450;
            $quizConfigForCompare = [];
            try {
                $quizSlugA = (string) ($resultA['quiz_slug'] ?? '');
                if ($quizSlugA !== '') {
                    $quizConfigForCompare = $this->quizRepository->load($quizSlugA);
                    $quizTitle = (string) (($quizConfigForCompare['meta']['title'] ?? '') ?: $quizTitle);
                    $quizAspect = (string) (($quizConfigForCompare['meta']['aspect'] ?? '') ?: '');
                    $quizVersionA = (string) (($quizConfigForCompare['meta']['version'] ?? '') ?: '');
                    $traitLabels = $this->extractTraitLabels($quizConfigForCompare);
                    $cmpMinWordsCfg = $quizConfigForCompare['comparison_narrative']['min_words'] ?? null;
                    if (is_numeric($cmpMinWordsCfg)) {
                        $cmpMinWords = max(250, (int) $cmpMinWordsCfg);
                    }
                }
            } catch (\Throwable) {
                // ignore
            }

            [$cmpShort, $cmpLong] = $this->generateComparisonSummaries(
                (float) ($matchResult['match_score'] ?? 0.0),
                is_array($matchResult['breakdown'] ?? null) ? (array) $matchResult['breakdown'] : [],
                $traitLabels,
                $cmpNameB,
                $cmpNameA,
                $quizTitle,
                $quizAspect,
                $cmpMinWords,
                $quizConfigForCompare ?? []
            );

            // Store comparison
            $comparisonId = $this->comparisonRepository->insert(
                $resultIdA,
                $resultIdB,
                $comparisonShareToken,
                $matchResult['match_score'],
                $matchResult['breakdown'],
                $matchResult['algorithm_used'],
                $cmpShort,
                $cmpLong,
                $quizVersionA
            );

            // Email the owner of result A (the shared result) that someone compared with them.
            $this->sendComparisonEmailNotification(
                $ownerIdA,
                [
                    'viewer_id' => (int) ($viewer['id'] ?? 0),
                    'viewer_name' => (string) ($viewer['name'] ?? 'Someone'),
                    'viewer_avatar_url' => (string) ($viewer['avatar_url'] ?? ''),
                ],
                (float) ($matchResult['match_score'] ?? 0.0),
                home_url('/match/' . $comparisonShareToken . '/'),
                (string) $quizTitle
            );

            return new \WP_REST_Response([
                'comparison_id' => $comparisonId,
                'comparison_share_token' => $comparisonShareToken,
                'share_urls' => [
                    'match' => home_url('/match/' . $comparisonShareToken . '/'),
                    'api_match' => rest_url(self::NAMESPACE . '/comparison/' . $comparisonShareToken),
                ],
                'match_score' => $matchResult['match_score'],
                'breakdown' => $matchResult['breakdown'],
                'algorithm_used' => $matchResult['algorithm_used'],
                'comparison_summary_short' => $cmpShort,
                'comparison_summary_long' => $cmpLong,
                'participants' => [
                    'a' => $ownerA,
                    'b' => $viewer,
                ],
                'you_result' => [
                    'trait_summary' => $traitVectorB ?? [],
                    'trait_labels' => $traitLabels,
                ],
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_request', $e->getMessage(), ['status' => 400]);
        } catch (\Throwable $e) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * Send an HTML email to the owner when someone compares with their shared result.
     *
     * @param int $ownerUserId
     * @param array{viewer_id:int,viewer_name:string,viewer_avatar_url:string} $viewer
     * @param float $matchScore
     * @param string $matchUrl
     * @param string $quizTitle
     */
    private function sendComparisonEmailNotification(int $ownerUserId, array $viewer, float $matchScore, string $matchUrl, string $quizTitle): void
    {
        $ownerUserId = (int) $ownerUserId;
        if ($ownerUserId <= 0) {
            return;
        }

        // Don't email if the owner compared with themselves.
        if (isset($viewer['viewer_id']) && (int) $viewer['viewer_id'] === $ownerUserId) {
            return;
        }

        $owner = get_user_by('id', $ownerUserId);
        if (!$owner instanceof \WP_User) {
            return;
        }

        $to = (string) $owner->user_email;
        if ($to === '') {
            return;
        }
        // Don't send to known placeholder emails (e.g., Instagram pseudo-email).
        if (str_contains($to, '@instagram.invalid')) {
            return;
        }
        // Some providers may create accounts with placeholder emails; respect that marker too.
        if ((string) get_user_meta($ownerUserId, 'has_placeholder_email', true) === 'true') {
            return;
        }

        // Optional future opt-out support. Default: send.
        $pref = get_user_meta($ownerUserId, self::EMAIL_NOTIFY_META, true);
        if (is_string($pref) && $pref !== '' && $pref === 'off') {
            return;
        }

        $siteName = (string) get_bloginfo('name');
        $viewerName = (string) ($viewer['viewer_name'] ?? 'Someone');
        $viewerAvatar = (string) ($viewer['viewer_avatar_url'] ?? '');
        $matchPct = (int) round(max(0.0, min(100.0, $matchScore)));

        $subject = sprintf('%s compared with you — %d%% match', $viewerName, $matchPct);
        if ($siteName !== '') {
            $subject .= ' • ' . $siteName;
        }

        $html = $this->buildComparisonEmailHtml([
            'site_name' => $siteName,
            'viewer_name' => $viewerName,
            'viewer_avatar_url' => $viewerAvatar,
            'match_pct' => $matchPct,
            'match_url' => $matchUrl,
            'quiz_title' => $quizTitle,
        ]);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Best-effort: don't fail the API call if email sending fails.
        try {
            wp_mail($to, $subject, $html, $headers);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @param array{site_name:string,viewer_name:string,viewer_avatar_url:string,match_pct:int,match_url:string,quiz_title:string} $data
     */
    private function buildComparisonEmailHtml(array $data): string
    {
        $siteName = esc_html((string) ($data['site_name'] ?? ''));
        $viewerName = esc_html((string) ($data['viewer_name'] ?? 'Someone'));
        $avatar = (string) ($data['viewer_avatar_url'] ?? '');
        $avatarEsc = $avatar !== '' ? esc_url($avatar) : '';
        $matchPct = (int) ($data['match_pct'] ?? 0);
        $matchUrl = esc_url((string) ($data['match_url'] ?? home_url('/')));
        $quizTitle = esc_html((string) ($data['quiz_title'] ?? 'Quiz'));

        $preheader = esc_html($viewerName . ' did a comparison with you. See your match and the full breakdown.');

        $brand = '#1E2A44';
        $bg = '#F6F5F2';
        $card = '#FFFFFF';
        $muted = '#6B7280';
        $border = 'rgba(30,42,68,0.12)';

        $avatarBlock = $avatarEsc !== ''
            ? '<img src="' . $avatarEsc . '" width="48" height="48" style="display:block;width:48px;height:48px;border-radius:999px;object-fit:cover;" alt="">'
            : '<div style="width:48px;height:48px;border-radius:999px;background:rgba(30,42,68,0.08);display:flex;align-items:center;justify-content:center;font-weight:800;color:' . $brand . ';">' . esc_html(mb_substr((string) ($data['viewer_name'] ?? 'S'), 0, 1)) . '</div>';

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . $viewerName . ' compared with you</title>
</head>
<body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Inter,Arial,sans-serif;color:#2B2E34;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $preheader . '</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . $bg . ';padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:92vw;">
          <tr>
            <td style="padding:0 12px 12px 12px;">
              <div style="font-weight:800;letter-spacing:-0.01em;color:' . $brand . ';font-size:16px;">' . ($siteName !== '' ? $siteName : 'm2me.me') . '</div>
            </td>
          </tr>
          <tr>
            <td style="background:' . $card . ';border:1px solid ' . $border . ';border-radius:18px;box-shadow:0 18px 46px rgba(30,42,68,0.12);padding:18px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="vertical-align:top;width:56px;">' . $avatarBlock . '</td>
                  <td style="vertical-align:top;padding-left:12px;">
                    <div style="font-size:18px;line-height:1.2;font-weight:900;letter-spacing:-0.02em;color:' . $brand . ';">' . $viewerName . ' did a comparison with you</div>
                    <div style="margin-top:6px;color:' . $muted . ';font-size:13px;line-height:1.35;">' . $quizTitle . '</div>
                  </td>
                </tr>
              </table>

              <div style="margin-top:16px;padding:14px;border-radius:16px;background:rgba(143,174,163,0.10);border:1px solid rgba(143,174,163,0.25);">
                <div style="font-size:13px;color:' . $muted . ';margin-bottom:6px;">Your match score</div>
                <div style="font-size:32px;font-weight:900;letter-spacing:-0.02em;color:' . $brand . ';">' . (int) $matchPct . '%</div>
              </div>

              <div style="margin-top:16px;">
                <a href="' . $matchUrl . '" style="display:inline-block;background:' . $brand . ';color:#F6F5F2;text-decoration:none;padding:12px 16px;border-radius:999px;font-weight:800;">
                  Check full result
                </a>
              </div>

              <div style="margin-top:14px;color:' . $muted . ';font-size:12px;line-height:1.4;">
                If the button doesn’t work, copy this link:<br>
                <a href="' . $matchUrl . '" style="color:' . $brand . ';text-decoration:underline;">' . $matchUrl . '</a>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:12px;color:' . $muted . ';font-size:12px;line-height:1.4;">
              You’re receiving this because someone used your shared comparison link.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
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
                return new \WP_Error(
                    'not_found',
                    'The quiz result could not be found. It may have been deleted.',
                    ['status' => 404, 'error_code' => 'RESULT_NOT_FOUND']
                );
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Token revoked successfully',
            ], 200);

        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An unexpected error occurred while revoking the share link. Please try again later.',
                ['status' => 500, 'error_code' => 'UNEXPECTED_ERROR']
            );
        }
    }

    /**
     * Check rate limit for comparisons (user-based if logged in, IP-based if anonymous).
     *
     * @return array{allowed: bool, remaining: int, reset_at: int}
     */
    private function checkRateLimit(): array
    {
        $userId = (int) get_current_user_id();
        $ip = $this->getClientIp();
        $hour = (int) (time() / self::RATE_LIMIT_WINDOW);
        $resetAt = ($hour + 1) * self::RATE_LIMIT_WINDOW;

        // Use user ID if logged in, otherwise use IP
        if ($userId > 0) {
            $key = self::RATE_LIMIT_KEY_PREFIX . 'user_' . $userId . '_' . $hour;
            // Higher limit for authenticated users (2x)
            $limit = self::RATE_LIMIT_MAX * 2;
        } else {
            $key = self::RATE_LIMIT_KEY_PREFIX . 'ip_' . $ip . '_' . $hour;
            $limit = self::RATE_LIMIT_MAX;
        }

        $count = (int) get_transient($key);
        $remaining = max(0, $limit - $count - 1);
        $allowed = $count < $limit;

        if ($allowed) {
            set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'limit' => $limit,
        ];
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
     * Extract trait labels from quiz config.
     */
    private function extractTraitLabels(array $quizConfig): array
    {
        $labels = [];
        $traits = $quizConfig['traits'] ?? [];
        if (!is_array($traits)) {
            return $labels;
        }
        foreach ($traits as $traitId => $traitData) {
            if (is_string($traitId) && is_array($traitData) && isset($traitData['label'])) {
                $labels[$traitId] = (string) $traitData['label'];
            }
        }
        return $labels;
    }

    /**
     * Extract trait descriptions from quiz config.
     *
     * @return array<string, string>
     */
    private function extractTraitDescriptions(array $quizConfig): array
    {
        $descs = [];
        $traits = $quizConfig['traits'] ?? [];
        if (!is_array($traits)) {
            return $descs;
        }
        foreach ($traits as $traitId => $traitData) {
            if (!is_string($traitId) || !is_array($traitData)) {
                continue;
            }
            $d = isset($traitData['description']) ? trim((string) $traitData['description']) : '';
            if ($d !== '') {
                $descs[$traitId] = $d;
            }
        }
        return $descs;
    }

    /**
     * Get trait level based on score.
     *
     * @param float $score
     * @return string 'high', 'mid', or 'low'
     */
    private function getTraitLevel(float $score): string
    {
        if ($score >= 0.70) {
            return 'high';
        }
        if ($score >= 0.45) {
            return 'mid';
        }
        return 'low';
    }

    /**
     * Calculate profile dominance (difference between top and third trait).
     *
     * @param array<string, float> $traitVector
     * @return float
     */
    private function calculateDominance(array $traitVector): float
    {
        if (empty($traitVector)) {
            return 0.0;
        }
        arsort($traitVector);
        $keys = array_keys($traitVector);
        $top1 = $keys[0] ?? null;
        $top3 = $keys[2] ?? null;
        if ($top1 === null) {
            return 0.0;
        }
        $score1 = (float) ($traitVector[$top1] ?? 0.0);
        $score3 = $top3 !== null ? (float) ($traitVector[$top3] ?? 0.0) : ($keys[1] !== null ? (float) ($traitVector[$keys[1]] ?? 0.0) : 0.0);
        return $score1 - $score3;
    }

    /**
     * Select variation index based on score confidence.
     *
     * @param float $score
     * @param int $variationCount
     * @return int
     */
    private function selectVariationIndex(float $score, int $variationCount): int
    {
        if ($variationCount <= 0) {
            return 0;
        }
        // Use score to influence selection (higher scores = more confident variations)
        // For now, use random selection for variety
        return mt_rand(0, $variationCount - 1);
    }

    /**
     * Select trait copy with context awareness.
     *
     * @param string $traitId
     * @param float $score
     * @param array $traitCopy
     * @param array $context
     * @return string
     */
    private function selectTraitCopy(string $traitId, float $score, array $traitCopy, array $context = []): string
    {
        if (!isset($traitCopy[$traitId]) || !is_array($traitCopy[$traitId])) {
            return '';
        }

        $level = $this->getTraitLevel($score);
        $variations = $traitCopy[$traitId][$level] ?? [];

        if (!is_array($variations) || empty($variations)) {
            // Fallback to generic description
            return '';
        }

        // Check for context modifiers
        if (isset($traitCopy[$traitId]['context_modifiers']) && is_array($traitCopy[$traitId]['context_modifiers'])) {
            foreach ($context as $otherTrait => $otherScore) {
                $modifierKey = $otherScore >= 0.70 ? "with_high_{$otherTrait}" : "with_low_{$otherTrait}";
                if (isset($traitCopy[$traitId]['context_modifiers'][$modifierKey])) {
                    $modifier = $traitCopy[$traitId]['context_modifiers'][$modifierKey];
                    return is_string($modifier) ? $modifier : '';
                }
            }
        }

        // Select variation based on score confidence
        $variationCount = count($variations);
        if ($variationCount === 0) {
            return '';
        }
        $index = $this->selectVariationIndex($score, $variationCount);
        return is_string($variations[$index] ?? null) ? $variations[$index] : '';
    }

    /**
     * Evaluate rule conditions for pair rules.
     *
     * @param array $when
     * @param array<string, float> $traitVector
     * @param array $conditions
     * @return bool
     */
    private function evaluateRuleConditions(array $when, array $traitVector, array $conditions = []): bool
    {
        foreach ($when as $condition) {
            if (strpos($condition, ':') !== false) {
                [$trait, $level] = explode(':', $condition, 2);
                $score = $traitVector[$trait] ?? 0.0;
                $expectedLevel = $this->getTraitLevel($score);
                if ($expectedLevel !== $level && $level !== 'any') {
                    return false;
                }
            } elseif ($condition === 'profile_type:balanced') {
                $dominance = $this->calculateDominance($traitVector);
                if ($dominance > 0.10) {
                    return false;
                }
            } elseif ($condition === 'profile_type:decisive') {
                $dominance = $this->calculateDominance($traitVector);
                if ($dominance < 0.18) {
                    return false;
                }
            } elseif ($condition === 'all_traits:high') {
                foreach ($traitVector as $score) {
                    if ($score < 0.70) {
                        return false;
                    }
                }
            } elseif ($condition === 'all_traits:low') {
                foreach ($traitVector as $score) {
                    if ($score >= 0.45) {
                        return false;
                    }
                }
            }
        }

        // Check additional conditions
        if (isset($conditions['min_dominance'])) {
            $dominance = $this->calculateDominance($traitVector);
            if ($dominance < (float) $conditions['min_dominance']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match pair rules based on trait vector.
     *
     * @param array<string, float> $traitVector
     * @param array $pairRules
     * @return array
     */
    private function matchPairRules(array $traitVector, array $pairRules): array
    {
        $matchedRules = [];

        if (!is_array($pairRules) || empty($pairRules)) {
            return $matchedRules;
        }

        foreach ($pairRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            
            $when = $rule['when'] ?? [];
            if (!is_array($when) || empty($when)) {
                continue;
            }
            
            if ($this->evaluateRuleConditions($when, $traitVector, $rule['conditions'] ?? [])) {
                $matchedRules[] = $rule;
            }
        }

        // Sort by priority (higher priority first)
        usort($matchedRules, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $matchedRules;
    }

    /**
     * Get confidence modifier based on score.
     *
     * @param float $score
     * @return string
     */
    private function getConfidenceModifier(float $score): string
    {
        if ($score >= 0.75) {
            return 'strongly';
        }
        if ($score >= 0.60) {
            return 'tend to';
        }
        if ($score >= 0.45) {
            return 'often';
        }
        return 'may';
    }

    /**
     * Apply aspect-specific language to sentence.
     *
     * @param string $sentence
     * @param string $aspect
     * @return string
     */
    private function applyAspectLanguage(string $sentence, string $aspect): string
    {
        // For now, return as-is. Can be enhanced with aspect-specific replacements.
        return $sentence;
    }

    /**
     * Build dynamic sentence from template.
     *
     * @param string $template
     * @param array<string, float> $traitVector
     * @param array<string, string> $traitLabels
     * @param string $aspect
     * @return string
     */
    private function buildDynamicSentence(string $template, array $traitVector, array $traitLabels, string $aspect): string
    {
        $sentence = $template;

        // Replace placeholders with context-aware content
        foreach ($traitVector as $trait => $score) {
            $confidence = $this->getConfidenceModifier($score);
            $sentence = str_replace("{{{$trait}_confidence}}", $confidence, $sentence);
        }

        // Aspect-specific language
        $sentence = $this->applyAspectLanguage($sentence, $aspect);

        return $sentence;
    }

    /**
     * Find trait gap scenario for a specific trait pair.
     *
     * @param string $trait1
     * @param array $topGaps Array of gap data
     * @param float $gapSize
     * @param array $traitPairCopy
     * @return array|null
     */
    private function findTraitGapScenario(string $trait1, array $topGaps, float $gapSize, array $traitPairCopy): ?array
    {
        foreach ($traitPairCopy as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            
            $whenGap = $scenario['when_gap'] ?? [];
            $requiredGapSize = $scenario['gap_size'] ?? 'any';
            
            if (!is_array($whenGap) || empty($whenGap)) {
                continue;
            }
            
            // Check if trait1 is in the gap pair (when_gap contains trait pairs like ["directness", "empathy"])
            if (in_array($trait1, $whenGap, true)) {
                // Check gap size match
                $gapSizeMatches = false;
                if ($requiredGapSize === 'any') {
                    $gapSizeMatches = true;
                } elseif ($requiredGapSize === 'large' && $gapSize >= 0.40) {
                    $gapSizeMatches = true;
                } elseif ($requiredGapSize === 'medium' && $gapSize >= 0.25 && $gapSize < 0.40) {
                    $gapSizeMatches = true;
                } elseif ($requiredGapSize === 'small' && $gapSize < 0.25) {
                    $gapSizeMatches = true;
                }
                
                if ($gapSizeMatches) {
                    return $scenario;
                }
            }
        }
        
        return null;
    }

    /**
     * Generate deterministic short + long result narratives.
     *
     * @param array<string, float> $traitVector
     * @param array<string, string> $traitLabels
     * @param array<string, string> $traitDescriptions
     * @param string $aspect
     * @param array $quizConfig
     * @return array{0:string,1:string} [short, long]
     */
    private function generateTextualSummaries(array $traitVector, array $traitLabels, array $traitDescriptions, string $aspect, array $quizConfig = []): array
    {
        if ($traitVector === []) {
            return ['Quiz completed successfully.', "We couldn't generate a detailed overview because trait data is missing."];
        }

        // Extract narrative block if available
        $narrative = $quizConfig['narrative'] ?? null;
        $traitCopy = is_array($narrative) && isset($narrative['trait_copy']) ? $narrative['trait_copy'] : [];
        $pairRules = is_array($narrative) && isset($narrative['pair_rules']) ? $narrative['pair_rules'] : [];
        $sectionTemplates = is_array($narrative) && isset($narrative['section_templates']) ? $narrative['section_templates'] : [];
        $aspectLanguage = is_array($narrative) && isset($narrative['aspect_specific_language']) ? $narrative['aspect_specific_language'] : [];
        $minWords = (is_array($narrative) && isset($narrative['min_words']) && is_numeric($narrative['min_words'])) 
            ? max(350, (int) $narrative['min_words']) 
            : 350;

        // Normalize / clamp.
        $clean = [];
        foreach ($traitVector as $k => $v) {
            if (!is_string($k)) continue;
            $val = (float) $v;
            if ($val < 0.0) $val = 0.0;
            if ($val > 1.0) $val = 1.0;
            $clean[$k] = $val;
        }
        if ($clean === []) {
            return ['Quiz completed successfully.', "We couldn't generate a detailed overview because trait data is missing."];
        }

        arsort($clean);
        $keys = array_keys($clean);

        $top1 = $keys[0] ?? null;
        $top2 = $keys[1] ?? null;
        $top3 = $keys[2] ?? null;
        $bottom1 = $keys[count($keys) - 1] ?? null;

        $score1 = $top1 !== null ? (float) ($clean[$top1] ?? 0.0) : 0.0;
        $score3 = $top3 !== null ? (float) ($clean[$top3] ?? 0.0) : ($top2 !== null ? (float) ($clean[$top2] ?? 0.0) : 0.0);
        $dominance = $score1 - $score3;

        $profileType = 'flexible';
        if ($dominance >= 0.18) {
            $profileType = 'decisive';
        } elseif ($dominance <= 0.10) {
            $profileType = 'balanced';
        }

        $label = function (?string $traitId) use ($traitLabels): string {
            if ($traitId === null || $traitId === '') return 'this trait';
            return $traitLabels[$traitId] ?? ucfirst(str_replace('_', ' ', $traitId));
        };

        $desc = function (?string $traitId) use ($traitDescriptions): string {
            if ($traitId === null || $traitId === '') return '';
            $d = $traitDescriptions[$traitId] ?? '';
            $d = trim((string) $d);
            if ($d === '') return '';
            return preg_match('/[.!?]$/', $d) ? $d : ($d . '.');
        };

        $primary = $label($top1);
        $secondary = $label($top2);
        $support = $label($top3);
        $low = $label($bottom1);

        // Check for pair rules first
        $matchedRules = !empty($pairRules) ? $this->matchPairRules($clean, $pairRules) : [];
        $overviewRule = null;
        foreach ($matchedRules as $rule) {
            if (($rule['section'] ?? '') === 'overview' || !isset($rule['section'])) {
                $overviewRule = $rule;
                break;
            }
        }

        // Short summary (1–2 sentences) for UI headers, share text, meta, etc.
        // Use pair rule copy if available, otherwise use generic
        if ($overviewRule && !empty($overviewRule['copy'])) {
            $short = is_array($overviewRule['copy']) ? ($overviewRule['copy'][0] ?? '') : (string) $overviewRule['copy'];
            if ($short === '') {
                // Fallback to generic
                if ($profileType === 'balanced') {
                    $short = "Your results show a balanced profile with a slight tilt toward {$primary} and {$secondary}.";
                } elseif ($profileType === 'decisive') {
                    $short = "Your results strongly highlight {$primary}, supported by {$secondary}.";
                } else {
                    $short = "Your profile leans toward {$primary} and {$secondary}, with {$support} as a supporting trait.";
                }
            }
        } else {
            // Generic fallback
            if ($profileType === 'balanced') {
                $short = "Your results show a balanced profile with a slight tilt toward {$primary} and {$secondary}.";
            } elseif ($profileType === 'decisive') {
                $short = "Your results strongly highlight {$primary}, supported by {$secondary}.";
            } else {
                $short = "Your profile leans toward {$primary} and {$secondary}, with {$support} as a supporting trait.";
            }
        }

        // Long overview: plain text with simple section headings + bullets (UI will format).
        $overview = [];
        $overview[] = "Overview";
        
        // Use pair rule copy for overview if available
        if ($overviewRule && !empty($overviewRule['copy'])) {
            $ruleCopy = is_array($overviewRule['copy']) ? ($overviewRule['copy'][0] ?? '') : (string) $overviewRule['copy'];
            if ($ruleCopy !== '') {
                $overview[] = $ruleCopy;
            } else {
                // Fallback to generic
                $overview[] = "Your strongest signals are in {$primary} and {$secondary}. " .
                    ($support !== '' ? "A supporting theme is {$support}. " : '') .
                    ($profileType === 'decisive'
                        ? "This is a more \"decisive\" pattern, meaning a few traits clearly stand out."
                        : ($profileType === 'balanced'
                            ? "This is a more \"balanced\" pattern, meaning you likely adapt depending on context."
                            : "This looks \"context-flexible\": you have clear preferences, but you can still shift when needed."));
            }
        } else {
            // Try to use trait copy if available
            $primaryCopy = '';
            $secondaryCopy = '';
            if (!empty($traitCopy) && $top1 !== null) {
                $primaryCopy = $this->selectTraitCopy($top1, $clean[$top1], $traitCopy, [$top2 => $clean[$top2] ?? 0.0]);
            }
            if (!empty($traitCopy) && $top2 !== null) {
                $secondaryCopy = $this->selectTraitCopy($top2, $clean[$top2], $traitCopy, [$top1 => $clean[$top1] ?? 0.0]);
            }
            
            if ($primaryCopy !== '' && $secondaryCopy !== '') {
                $overview[] = $primaryCopy . ' ' . $secondaryCopy;
            } else {
                // Generic fallback
                $overview[] = "Your strongest signals are in {$primary} and {$secondary}. " .
                    ($support !== '' ? "A supporting theme is {$support}. " : '') .
                    ($profileType === 'decisive'
                        ? "This is a more \"decisive\" pattern, meaning a few traits clearly stand out."
                        : ($profileType === 'balanced'
                            ? "This is a more \"balanced\" pattern, meaning you likely adapt depending on context."
                            : "This looks \"context-flexible\": you have clear preferences, but you can still shift when needed."));
            }
        }
        
        $overview[] = "These percentages aren't good or bad. They're simply a snapshot of what you tend to default to in decisions, collaboration, and under pressure.";
        $overview[] = "This is informational and not a diagnosis.";

        $strengths = [];
        $strengths[] = "Strengths";
        
        // Check for pair rules for strengths section
        $strengthsRules = [];
        foreach ($matchedRules as $rule) {
            if (($rule['section'] ?? '') === 'strengths') {
                $strengthsRules[] = $rule;
            }
        }
        
        // Use pair rule copy if available
        if (!empty($strengthsRules)) {
            $strengthRule = $strengthsRules[0];
            $strengthCopy = is_array($strengthRule['copy']) ? ($strengthRule['copy'][0] ?? '') : (string) $strengthRule['copy'];
            if ($strengthCopy !== '') {
                $strengths[] = "- " . $strengthCopy;
            }
        }
        
        // Use trait copy if available
        if (empty($strengthsRules)) {
            $primaryCopy = '';
            $secondaryCopy = '';
            if (!empty($traitCopy) && $top1 !== null) {
                $primaryCopy = $this->selectTraitCopy($top1, $clean[$top1], $traitCopy, [$top2 => $clean[$top2] ?? 0.0]);
            }
            if (!empty($traitCopy) && $top2 !== null) {
                $secondaryCopy = $this->selectTraitCopy($top2, $clean[$top2], $traitCopy, [$top1 => $clean[$top1] ?? 0.0]);
            }
            
            if ($primaryCopy !== '' || $secondaryCopy !== '') {
                if ($primaryCopy !== '') {
                    $strengths[] = "- " . $primaryCopy;
                }
                if ($secondaryCopy !== '') {
                    $strengths[] = "- " . $secondaryCopy;
                }
            } else {
                // Generic fallback
                $strengths[] = "- You can rely on your {$primary} side to create momentum when something needs a push forward.";
                $strengths[] = "- Your {$secondary} side helps you stay consistent and follow through, especially when things get busy.";
                if ($support !== '') {
                    $strengths[] = "- With {$support} as a supporting trait, you can round out your approach instead of using only one style.";
                }
            }
        } else {
            // Add generic strengths if we only have one rule
            if (count($strengthsRules) === 1) {
                $strengths[] = "- You can rely on your {$primary} side to create momentum when something needs a push forward.";
                $strengths[] = "- Your {$secondary} side helps you stay consistent and follow through, especially when things get busy.";
            }
        }
        
        $d1 = $desc($top1);
        $d2 = $desc($top2);
        if (($d1 !== '' || $d2 !== '') && empty($strengthsRules)) {
            $strengths[] = "- In plain language: " . trim(($d1 !== '' ? "{$primary}: {$d1} " : '') . ($d2 !== '' ? "{$secondary}: {$d2}" : ''));
        }

        $edges = [];
        $edges[] = "Growth edges";
        
        // Check for pair rules for growth_edges section
        $edgesRules = [];
        foreach ($matchedRules as $rule) {
            if (($rule['section'] ?? '') === 'growth_edges') {
                $edgesRules[] = $rule;
            }
        }
        
        // Use pair rule copy if available
        if (!empty($edgesRules)) {
            foreach ($edgesRules as $edgeRule) {
                $edgeCopy = is_array($edgeRule['copy']) ? ($edgeRule['copy'][0] ?? '') : (string) $edgeRule['copy'];
                if ($edgeCopy !== '') {
                    $edges[] = "- " . $edgeCopy;
                }
            }
        }
        
        // Add generic growth edges if no rules or to supplement
        if (empty($edgesRules) || count($edgesRules) < 2) {
            $edges[] = "- When you overuse {$primary}, you might move too fast for others or skip alignment.";
            $edges[] = "- When {$secondary} runs high, you may prefer certainty, so ambiguity can feel uncomfortable.";
            if ($low !== '') {
                $edges[] = "- With lower {$low}, you might not naturally prioritize that style unless you choose it intentionally.";
            }
        }

        $stress = [];
        $stress[] = "Under stress";
        if ($profileType === 'decisive') {
            $stress[] = "Under pressure, you may double down on what works: faster decisions, more direction, more structure. That can be effective—but it can also feel intense to others.";
        } elseif ($profileType === 'balanced') {
            $stress[] = "Under pressure, balanced profiles often start scanning for the “best” option. The upside is flexibility; the downside can be overthinking or delaying a clear decision.";
        } else {
            $stress[] = "Under pressure, you may switch styles quickly: sometimes pushing forward, other times pausing to reassess. The key is to pick one next step and commit to it.";
        }
        $stress[] = "A simple reset: name the goal, choose one next step, and decide what would count as “good enough” for today.";

        $rel = [];
        $rel[] = ($aspect === 'personality') ? "Relationships & teamwork" : "How this shows up with other people";
        $rel[] = "People may experience you as someone who brings {$primary} energy with a {$secondary} backbone. In collaboration, this often looks like wanting clarity, momentum, and a practical path forward.";
        $rel[] = "If conflict appears, try to separate intent from impact: you can stay direct while also checking how it lands on the other person.";

        $next = [];
        $next[] = "Next steps";
        $next[] = "- Before offering a solution, ask: “What outcome matters most here?”";
        $next[] = "- If you feel urgency, say it out loud: “I’m feeling urgency—can we decide on one next step?”";
        $next[] = "- Practice balance: pick one small moment today to deliberately act from a lower trait (like {$low}) and notice what changes.";

        $long = implode("\n", array_merge(
            $overview,
            [''],
            $strengths,
            [''],
            $edges,
            [''],
            $stress,
            [''],
            $rel,
            [''],
            $next
        ));

        // Ensure we hit the "long enough" target (doc suggests 350–600+ words).
        // $minWords already set from narrative block or default above
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z0-9]+/u', ' ', $long) ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        if ($wordCount < $minWords) {
            $extra = [];
            $extra[] = "How to use this";
            $extra[] = "Treat your top traits as your default tools, not your identity. When something feels stuck, ask yourself: am I overusing my strongest tool, or avoiding a tool that would help right now?";
            $extra[] = "A simple way to get value from these results is to notice patterns for one week: when do you feel most like {$primary}, and when does {$secondary} show up? The goal is awareness first—change comes after you can name what’s happening.";
            $extra[] = "Practical prompts";
            $extra[] = "- What situations bring out the best of {$primary} in you?";
            $extra[] = "- Where does {$secondary} help you stay grounded, and where does it slow you down?";
            $extra[] = "- What’s one small habit you can try for 7 days to strengthen a lower style like {$low}?";
            $long = $long . "\n\n" . implode("\n", $extra);
        }

        return [$short, $long];
    }

    /**
     * Get or create quiz ID in database.
     * Looks up or creates the quiz in the match_me_quizzes table.
     */
    private function getOrCreateQuizId(string $quizSlug, array $quizConfig): int
    {
        $title = (string) ($quizConfig['meta']['title'] ?? $quizSlug);
        $version = (string) ($quizConfig['meta']['version'] ?? '1.0');
        $meta = $quizConfig['meta'] ?? [];

        return $this->quizDbRepository->getOrCreate($quizSlug, $title, $version, $meta);
    }

    /**
     * GET /wp-json/match-me/v1/comparison/{share_token}
     */
    public function getComparison(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $shareToken = (string) $request->get_param('share_token');

        try {
            $comparison = $this->comparisonRepository->findByShareToken($shareToken);
            if ($comparison === null) {
                return new \WP_Error('not_found', 'Comparison not found', ['status' => 404]);
            }

            $resultA = $this->resultRepository->findById((int) ($comparison['result_a'] ?? 0));
            $resultB = $this->resultRepository->findById((int) ($comparison['result_b'] ?? 0));
            if ($resultA === null || $resultB === null) {
                return new \WP_Error('not_found', 'Comparison results not found', ['status' => 404]);
            }

            // Result A must still allow comparison
            $shareModeA = (string) ($resultA['share_mode'] ?? 'private');
            if ($shareModeA !== 'share_match' || !empty($resultA['revoked_at'])) {
                return new \WP_Error('forbidden', 'This comparison is not available', ['status' => 403]);
            }

            // If either participant retook the quiz, recompute this comparison using latest results (keep share link stable).
            $cmpId = (int) ($comparison['id'] ?? 0);
            $algo = (string) ($comparison['algorithm_used'] ?? 'cosine');
            $ownerIdA = (int) ($resultA['user_id'] ?? 0);
            $ownerIdB = (int) ($resultB['user_id'] ?? 0);
            $quizSlugA = (string) ($resultA['quiz_slug'] ?? '');
            $canRefresh = ($cmpId > 0 && $ownerIdA > 0 && $ownerIdB > 0 && $quizSlugA !== '');
            if ($canRefresh) {
                $latestA = $this->resultRepository->latestByUserAndQuizSlug($ownerIdA, $quizSlugA);
                $latestB = $this->resultRepository->latestByUserAndQuizSlug($ownerIdB, $quizSlugA);

                if (is_array($latestA) && is_array($latestB)) {
                    $latestAId = (int) ($latestA['result_id'] ?? 0);
                    $latestBId = (int) ($latestB['result_id'] ?? 0);

                    $needsUpdate = ($latestAId > 0 && $latestBId > 0)
                        && ($latestAId !== (int) ($resultA['result_id'] ?? 0) || $latestBId !== (int) ($resultB['result_id'] ?? 0));

                    // Ensure the shared owner result still allows comparison.
                    if ($needsUpdate) {
                        $latestShareModeA = (string) ($latestA['share_mode'] ?? 'private');
                        if ($latestShareModeA === 'share_match' && empty($latestA['revoked_at'])) {
                            $matchResult = $this->matchingService->matchResults($latestAId, $latestBId, $algo);

                            // Trait labels + quiz info for narrative and display.
                            $traitLabelsLive = [];
                            $quizTitleLive = 'Quiz Results';
                            $quizAspectLive = '';
                            $currentQuizVersionLive = '';
                            $cmpMinWordsLive = 450;
                            try {
                                $quizConfig = $this->quizRepository->load($quizSlugA);
                                $quizTitleLive = (string) (($quizConfig['meta']['title'] ?? '') ?: $quizTitleLive);
                                $quizAspectLive = (string) (($quizConfig['meta']['aspect'] ?? '') ?: '');
                                $currentQuizVersionLive = (string) (($quizConfig['meta']['version'] ?? '') ?: '');
                                $traitLabelsLive = $this->extractTraitLabels($quizConfig);
                                $cmpMinWordsCfg = $quizConfig['comparison_narrative']['min_words'] ?? null;
                                if (is_numeric($cmpMinWordsCfg)) {
                                    $cmpMinWordsLive = max(250, (int) $cmpMinWordsCfg);
                                }
                            } catch (\Throwable) {
                                // ignore
                            }

                            // Names: B is the viewer in our UI convention, A is the shared person.
                            $nameA = 'Them';
                            $nameB = 'You';
                            $uA = get_user_by('id', $ownerIdA);
                            $uB = get_user_by('id', $ownerIdB);
                            if ($uA instanceof \WP_User) {
                                $first = (string) get_user_meta($ownerIdA, 'first_name', true);
                                $nameA = $first !== '' ? $first : (string) ($uA->display_name ?: $nameA);
                            }
                            if ($uB instanceof \WP_User) {
                                $first = (string) get_user_meta($ownerIdB, 'first_name', true);
                                $nameB = $first !== '' ? $first : (string) ($uB->display_name ?: $nameB);
                            }

                            [$cmpShortLive, $cmpLongLive] = $this->generateComparisonSummaries(
                                (float) ($matchResult['match_score'] ?? 0.0),
                                is_array($matchResult['breakdown'] ?? null) ? (array) $matchResult['breakdown'] : [],
                                $traitLabelsLive,
                                $nameB,
                                $nameA,
                                $quizTitleLive,
                                $quizAspectLive,
                                $cmpMinWordsLive,
                                $quizConfig ?? []
                            );

                            $this->comparisonRepository->updateComparison(
                                $cmpId,
                                $latestAId,
                                $latestBId,
                                (float) ($matchResult['match_score'] ?? 0.0),
                                is_array($matchResult['breakdown'] ?? null) ? (array) $matchResult['breakdown'] : [],
                                (string) ($matchResult['algorithm_used'] ?? $algo),
                                $cmpShortLive,
                                $cmpLongLive,
                                $currentQuizVersionLive !== '' ? $currentQuizVersionLive : (string) ($latestA['quiz_version'] ?? '')
                            );

                            // Reload local variables for response (ensures updated match_score/breakdown/summaries).
                            $comparison = $this->comparisonRepository->findByShareToken($shareToken) ?? $comparison;
                            $resultA = $this->resultRepository->findById($latestAId) ?? $resultA;
                            $resultB = $this->resultRepository->findById($latestBId) ?? $resultB;
                        }
                    }
                }
            }

            $ownerA = null;
            $ownerIdA = (int) ($resultA['user_id'] ?? 0);
            if ($ownerIdA > 0) {
                $uA = get_user_by('id', $ownerIdA);
                if ($uA instanceof \WP_User) {
                    $first = (string) get_user_meta($ownerIdA, 'first_name', true);
                    $name = $first !== '' ? $first : (string) ($uA->display_name ?: 'Someone');
                    $ownerA = [
                        'id' => $ownerIdA,
                        'name' => $name,
                        'avatar_url' => (string) get_avatar_url($ownerIdA, ['size' => 256]),
                    ];
                }
            }

            $ownerB = null;
            $ownerIdB = (int) ($resultB['user_id'] ?? 0);
            if ($ownerIdB > 0) {
                $uB = get_user_by('id', $ownerIdB);
                if ($uB instanceof \WP_User) {
                    $first = (string) get_user_meta($ownerIdB, 'first_name', true);
                    $name = $first !== '' ? $first : (string) ($uB->display_name ?: 'Someone');
                    $ownerB = [
                        'id' => $ownerIdB,
                        'name' => $name,
                        'avatar_url' => (string) get_avatar_url($ownerIdB, ['size' => 256]),
                    ];
                }
            }

            $breakdown = json_decode((string) ($comparison['breakdown'] ?? '{}'), true);
            if (!is_array($breakdown)) {
                $breakdown = [];
            }

            // Build trait labels + quiz info for narrative and display.
            $traitLabels = [];
            $quizTitle = 'Quiz Results';
            $quizAspect = '';
            $currentQuizVersion = '';
            $cmpMinWords = 450;
            try {
                $quizSlug = (string) ($resultA['quiz_slug'] ?? '');
                if ($quizSlug !== '') {
                    $quizConfig = $this->quizRepository->load($quizSlug);
                    $quizTitle = (string) (($quizConfig['meta']['title'] ?? '') ?: $quizTitle);
                    $quizAspect = (string) (($quizConfig['meta']['aspect'] ?? '') ?: '');
                    $currentQuizVersion = (string) (($quizConfig['meta']['version'] ?? '') ?: '');
                    $traitLabels = $this->extractTraitLabels($quizConfig);
                    $cmpMinWordsCfg = $quizConfig['comparison_narrative']['min_words'] ?? null;
                    if (is_numeric($cmpMinWordsCfg)) {
                        $cmpMinWords = max(250, (int) $cmpMinWordsCfg);
                    }
                }
            } catch (\Throwable) {
                // ignore
            }

            // Prefer stored comparison narrative; regenerate only when missing or quiz version changes.
            $storedShort = isset($comparison['comparison_summary_short']) ? (string) ($comparison['comparison_summary_short'] ?? '') : '';
            $storedLong = isset($comparison['comparison_summary_long']) ? (string) ($comparison['comparison_summary_long'] ?? '') : '';
            $storedQuizVer = isset($comparison['comparison_summary_quiz_version']) ? (string) ($comparison['comparison_summary_quiz_version'] ?? '') : '';

            $shouldRegenerate = ($storedShort === '' || $storedLong === '');
            if ($currentQuizVersion !== '' && $storedQuizVer !== $currentQuizVersion) {
                $shouldRegenerate = true;
            }

            $nameA = $ownerA && isset($ownerA['name']) ? (string) $ownerA['name'] : 'Them';
            $nameB = $ownerB && isset($ownerB['name']) ? (string) $ownerB['name'] : 'You';

            if ($shouldRegenerate) {
                [$genShort, $genLong] = $this->generateComparisonSummaries(
                    (float) ($comparison['match_score'] ?? 0.0),
                    $breakdown,
                    $traitLabels,
                    $nameB,
                    $nameA,
                    $quizTitle,
                    $quizAspect,
                    $cmpMinWords,
                    $quizConfig ?? []
                );
                $storedShort = $genShort;
                $storedLong = $genLong;
                $cmpId = (int) ($comparison['id'] ?? 0);
                if ($cmpId > 0) {
                    $this->comparisonRepository->updateSummaries(
                        $cmpId,
                        $storedShort,
                        $storedLong,
                        $currentQuizVersion !== '' ? $currentQuizVersion : (string) ($resultA['quiz_version'] ?? '')
                    );
                }
            }

            return new \WP_REST_Response([
                'comparison_id' => (int) ($comparison['id'] ?? 0),
                'share_token' => (string) ($comparison['share_token'] ?? ''),
                'match_score' => (float) ($comparison['match_score'] ?? 0.0),
                'breakdown' => $breakdown,
                'algorithm_used' => (string) ($comparison['algorithm_used'] ?? 'cosine'),
                'quiz_title' => $quizTitle,
                'comparison_summary_short' => $storedShort,
                'comparison_summary_long' => $storedLong,
                'share_urls' => [
                    'match' => home_url('/match/' . rawurlencode($shareToken) . '/'),
                    'api_match' => rest_url(self::NAMESPACE . '/comparison/' . rawurlencode($shareToken)),
                ],
                'participants' => [
                    'a' => $ownerA,
                    'b' => $ownerB,
                ],
            ], 200);
        } catch (\Throwable) {
            return new \WP_Error('server_error', 'An unexpected error occurred', ['status' => 500]);
        }
    }

    /**
     * Generate deterministic comparison summaries based on match score + breakdown.
     *
     * @param float $matchScore 0-100
     * @param array<string,mixed> $breakdown
     * @param array<string,string> $traitLabels
     * @return array{0:string,1:string} [short, long]
     */
    private function generateComparisonSummaries(
        float $matchScore,
        array $breakdown,
        array $traitLabels,
        string $nameYou,
        string $nameThem,
        string $quizTitle,
        string $aspect,
        int $minWords = 450,
        array $quizConfig = []
    ): array {
        // Extract comparison narrative block if available
        $comparisonNarrative = $quizConfig['comparison_narrative'] ?? null;
        $traitPairCopy = is_array($comparisonNarrative) && isset($comparisonNarrative['trait_pair_copy']) 
            ? $comparisonNarrative['trait_pair_copy'] 
            : [];
        $matchInterpretations = is_array($comparisonNarrative) && isset($comparisonNarrative['match_score_interpretations']) 
            ? $comparisonNarrative['match_score_interpretations'] 
            : [];
        $communicationTips = is_array($comparisonNarrative) && isset($comparisonNarrative['communication_tips']) 
            ? $comparisonNarrative['communication_tips'] 
            : [];
        $aspectAdvice = is_array($comparisonNarrative) && isset($comparisonNarrative['aspect_specific_advice']) 
            ? $comparisonNarrative['aspect_specific_advice'] 
            : [];
        $minWords = (is_array($comparisonNarrative) && isset($comparisonNarrative['min_words']) && is_numeric($comparisonNarrative['min_words'])) 
            ? max(250, (int) $comparisonNarrative['min_words']) 
            : max(250, $minWords);

        $score = max(0.0, min(100.0, (float) $matchScore));
        $band = ($score >= 80.0) ? 'high' : (($score >= 55.0) ? 'medium' : 'low');

        $you = $nameYou !== '' ? $nameYou : 'You';
        $them = $nameThem !== '' ? $nameThem : 'Them';

        $label = function (string $traitId) use ($traitLabels): string {
            return $traitLabels[$traitId] ?? ucfirst(str_replace('_', ' ', $traitId));
        };

        $traits = [];
        $rawTraits = $breakdown['traits'] ?? null;
        if (is_array($rawTraits)) {
            foreach ($rawTraits as $traitId => $data) {
                if (!is_string($traitId) || !is_array($data)) continue;
                $sim = isset($data['similarity']) ? (float) $data['similarity'] : 0.0;
                $hasA = array_key_exists('a', $data);
                $hasB = array_key_exists('b', $data);
                $a = $hasA ? (float) $data['a'] : null;
                $b = $hasB ? (float) $data['b'] : null;
                $gap = (is_float($a) && is_float($b)) ? abs($a - $b) : null;
                $traits[] = [
                    'id' => $traitId,
                    'label' => $label($traitId),
                    'similarity' => max(0.0, min(1.0, $sim)),
                    'gap' => $gap,
                ];
            }
        }

        $bySim = $traits;
        usort($bySim, fn($x, $y) => ($y['similarity'] <=> $x['similarity']));
        $topAlignPref = array_values(array_filter($bySim, fn($t) => (float) ($t['similarity'] ?? 0.0) >= 0.70));
        $topAlign = array_slice(($topAlignPref !== [] ? $topAlignPref : $bySim), 0, 4);

        $byGap = array_values(array_filter($traits, fn($t) => is_float($t['gap'] ?? null)));
        usort($byGap, fn($x, $y) => (($y['gap'] ?? 0.0) <=> ($x['gap'] ?? 0.0)));
        $topGapsPref = array_values(array_filter($byGap, fn($t) => (float) ($t['gap'] ?? 0.0) >= 0.25));
        $topGaps = array_slice(($topGapsPref !== [] ? $topGapsPref : $byGap), 0, 4);

        // Short summary
        if ($band === 'high') {
            $short = "{$you} and {$them} show high alignment ({$score}%). You likely share similar defaults in how you approach decisions and daily life.";
        } elseif ($band === 'medium') {
            $short = "{$you} and {$them} show a mixed-but-promising match ({$score}%). You align in some areas and differ in others—often a complementary pairing when coordinated well.";
        } else {
            $short = "{$you} and {$them} have different defaults ({$score}%). This can be workable with clear communication and shared routines, but it may not feel effortless.";
        }

        $lines = [];
        $lines[] = "Headline";
        if ($band === 'high') {
            $lines[] = "{$you} and {$them} show high alignment in your defaults. This often feels smooth day-to-day—especially around pace, decisions, and follow-through.";
        } elseif ($band === 'medium') {
            $lines[] = "{$you} and {$them} share some strong overlap, with a few meaningful differences. This can feel exciting and growth-oriented when you coordinate well.";
        } else {
            $lines[] = "{$you} and {$them} have different defaults. That doesn’t mean “bad”—but it usually means you’ll need clearer agreements so you don’t rely on mind-reading.";
        }
        $lines[] = "This {$quizTitle} comparison is based on trait similarity across your results. Similarity can feel comfortable; differences can be complementary—but they usually require clearer coordination.";
        $lines[] = "Score: " . round($score) . "% (" . ($band === 'high' ? 'high alignment' : ($band === 'medium' ? 'mixed' : 'different styles')) . ").";

        $lines[] = "";
        $lines[] = "Where you align";
        if ($topAlign === []) {
            $lines[] = "Your results don’t show strong alignment peaks. That usually means your overlap is spread across smaller areas rather than a few dominant shared traits.";
        } else {
            foreach ($topAlign as $t) {
                $pct = (int) round(($t['similarity'] ?? 0) * 100);
                $lines[] = "- {$t['label']} ({$pct}% similar): You’ll more easily “get” each other’s default approach here, which often reduces friction and speeds up repair.";
            }
        }

        $lines[] = "";
        $lines[] = "Where friction may show up";
        if ($topGaps === []) {
            $lines[] = "No clear trait gaps were detected (or per-person values weren’t available). If you still feel friction, it’s likely situational: timing, stress, expectations, or communication habits.";
        } else {
            foreach ($topGaps as $t) {
                $gapPct = (int) round((float) ($t['gap'] ?? 0) * 100);
                
                // Try to find trait gap scenario
                $gapScenario = null;
                if (!empty($traitPairCopy) && isset($t['id']) && is_string($t['id'])) {
                    $gapScenario = $this->findTraitGapScenario($t['id'], $topGaps, (float) ($t['gap'] ?? 0), $traitPairCopy);
                }
                
                if ($gapScenario && !empty($gapScenario['copy'])) {
                    $gapCopy = is_array($gapScenario['copy']) ? ($gapScenario['copy'][0] ?? '') : (string) $gapScenario['copy'];
                    if ($gapCopy !== '') {
                        $lines[] = "- {$t['label']} (gap ~{$gapPct}%): " . $gapCopy;
                    } else {
                        $lines[] = "- {$t['label']} (gap ~{$gapPct}%): One of you likely defaults to this more strongly. When rushed, this can look like mismatched pace, priorities, or expectations.";
                    }
                } else {
                    $lines[] = "- {$t['label']} (gap ~{$gapPct}%): One of you likely defaults to this more strongly. When rushed, this can look like mismatched pace, priorities, or expectations.";
                }
            }
        }

        $lines[] = "";
        $lines[] = ($aspect === 'personality') ? "Communication tips" : "Coordination tips";
        
        // Use aspect-specific communication tips if available
        $tips = $communicationTips[$band] ?? [];
        if (!empty($tips) && is_array($tips)) {
            foreach ($tips as $tip) {
                $lines[] = "- " . $tip;
            }
        } else {
            // Fallback to generic tips
            if ($band === 'high') {
                $lines[] = "- Try saying: \"I want to move fast, and I also want you to feel fully on my side. Are we aligned?\"";
                $lines[] = "- Try saying: \"What would 'good enough' look like for today so we don't over-optimize?\"";
            } elseif ($band === 'medium') {
                $lines[] = "- Try saying: \"Can we pick a simple plan for today, and keep it flexible if new info shows up?\"";
                $lines[] = "- Try saying: \"What do you need before you feel ready to commit? More info, more time, or more reassurance?\"";
            } else {
                $lines[] = "- Try saying: \"Help me understand what matters most to you here. Speed, certainty, or connection?\"";
                $lines[] = "- Try saying: \"Can we slow down for 2 minutes so we don't misread each other?\"";
            }
        }
        $lines[] = "When conflict appears: validate first (\"I get why that matters to you\"), then propose one small experiment (\"Can we try X for 7 days?\").";

        $lines[] = "";
        $lines[] = "Do more / Do less";
        $lines[] = "- Do more: name expectations early (time, plans, tone) and confirm them out loud.";
        $lines[] = "- Do more: pick one weekly ritual that reduces ambiguity (10-minute planning check-in works well).";
        $lines[] = "- Do less: assume intent from tone; ask one clarifying question instead.";
        $lines[] = "- Do less: re-litigate the past—focus on the next repeatable process.";

        $lines[] = "";
        $lines[] = "Next steps";
        $lines[] = "- Choose one area to be “structured” and one to be “flexible,” so you stop fighting the same category repeatedly.";
        $lines[] = "- If a conflict repeats, ask: “Is this a values issue, or a process issue?”";
        $lines[] = "- Use a bridge sentence before solutions: “I’m on your team—here’s what I’m noticing.”";

        $lines[] = "";
        $lines[] = "This is an informational snapshot, not professional advice.";

        $long = implode("\n", $lines);

        // Ensure it's long enough (comparison doc suggests ~450+ words).
        // $minWords already set from narrative block or default above
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z0-9]+/u', ' ', $long) ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        if ($wordCount < $minWords) {
            $pad = [];
            $pad[] = "How to interpret this";
            $pad[] = "If your match is high, the main risk is moving fast and skipping emotional alignment. If your match is medium, the main task is coordination—agree on decision rules. If your match is low, the main skill is translation: build shared routines so you don’t rely on “mind-reading.”";
            $long .= "\n\n" . implode("\n", $pad);
        }

        return [$short, $long];
    }
}

