<?php
declare(strict_types=1);

namespace MatchMe\Wp\Api;

use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\ResultRepository;

/**
 * GDPR compliance API endpoints for data deletion and export.
 */
final class GdprApiController
{
    private const NAMESPACE = 'match-me/v1';

    public function __construct(
        private ResultRepository $resultRepository,
        private ComparisonRepository $comparisonRepository,
        private QuizResultRepository $oldResultRepository
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // Data export endpoint
        register_rest_route(self::NAMESPACE, '/gdpr/export', [
            'methods' => 'GET',
            'callback' => [$this, 'exportData'],
            'permission_callback' => [$this, 'checkUserPermission'],
        ]);

        // Data deletion endpoint
        register_rest_route(self::NAMESPACE, '/gdpr/delete', [
            'methods' => 'POST',
            'callback' => [$this, 'deleteData'],
            'permission_callback' => [$this, 'checkUserPermission'],
        ]);

        // Consent tracking endpoint
        register_rest_route(self::NAMESPACE, '/gdpr/consent', [
            'methods' => 'POST',
            'callback' => [$this, 'updateConsent'],
            'permission_callback' => [$this, 'checkUserPermission'],
        ]);

        // Get consent status endpoint
        register_rest_route(self::NAMESPACE, '/gdpr/consent', [
            'methods' => 'GET',
            'callback' => [$this, 'getConsent'],
            'permission_callback' => [$this, 'checkUserPermission'],
        ]);
    }

    /**
     * Check if user is logged in.
     */
    private function checkUserPermission(\WP_REST_Request $request): bool
    {
        return is_user_logged_in();
    }

    /**
     * GET /wp-json/match-me/v1/gdpr/export
     * Export all user data in JSON format (GDPR Article 20 - Right to Data Portability).
     */
    public function exportData(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error(
                'unauthorized',
                'You must be logged in to export your data.',
                ['status' => 401, 'error_code' => 'AUTHENTICATION_REQUIRED']
            );
        }

        try {
            $user = get_user_by('id', $userId);
            if (!$user instanceof \WP_User) {
                return new \WP_Error(
                    'not_found',
                    'User not found.',
                    ['status' => 404, 'error_code' => 'USER_NOT_FOUND']
                );
            }

            $exportData = [
                'exported_at' => current_time('c'),
                'user_id' => $userId,
                'user_data' => [
                    'email' => $user->user_email,
                    'username' => $user->user_login,
                    'display_name' => $user->display_name,
                    'registered_date' => $user->user_registered,
                ],
                'user_meta' => $this->getUserMeta($userId),
                'quiz_results' => $this->getQuizResults($userId),
                'comparisons' => $this->getComparisons($userId),
            ];

            // Return JSON response (JavaScript will handle download)
            $response = new \WP_REST_Response($exportData, 200);
            $response->set_headers([
                'Content-Type' => 'application/json; charset=utf-8',
            ]);
            return $response;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An error occurred while exporting your data. Please try again later.',
                ['status' => 500, 'error_code' => 'EXPORT_ERROR']
            );
        }
    }

    /**
     * POST /wp-json/match-me/v1/gdpr/delete
     * Delete all user data (GDPR Article 17 - Right to Erasure).
     */
    public function deleteData(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error(
                'unauthorized',
                'You must be logged in to delete your data.',
                ['status' => 401, 'error_code' => 'AUTHENTICATION_REQUIRED']
            );
        }

        $body = $request->get_json_params();
        $confirm = isset($body['confirm']) && $body['confirm'] === true;

        if (!$confirm) {
            return new \WP_Error(
                'confirmation_required',
                'You must confirm the deletion by setting "confirm" to true in the request body.',
                ['status' => 400, 'error_code' => 'CONFIRMATION_REQUIRED']
            );
        }

        try {
            // Delete quiz results (new system)
            $deletedResults = $this->resultRepository->deleteByUser($userId);

            // Delete quiz results (old system)
            $deletedOldResults = $this->oldResultRepository->deleteByUser($userId);

            // Delete comparisons (will cascade via foreign keys, but also delete explicitly)
            $deletedComparisons = $this->comparisonRepository->deleteByUser($userId);

            // Delete user meta (except WordPress core meta)
            $this->deleteUserMeta($userId);

            // Note: We don't delete the WordPress user account itself as that's managed by WordPress
            // Users should use WordPress account deletion for that

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Your data has been deleted successfully.',
                'deleted' => [
                    'results' => $deletedResults,
                    'old_results' => $deletedOldResults,
                    'comparisons' => $deletedComparisons,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An error occurred while deleting your data. Please try again later.',
                ['status' => 500, 'error_code' => 'DELETION_ERROR']
            );
        }
    }

    /**
     * POST /wp-json/match-me/v1/gdpr/consent
     * Update user consent (GDPR Article 7 - Conditions for Consent).
     */
    public function updateConsent(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error(
                'unauthorized',
                'You must be logged in to update consent.',
                ['status' => 401, 'error_code' => 'AUTHENTICATION_REQUIRED']
            );
        }

        $body = $request->get_json_params();

        $privacyPolicyConsent = isset($body['privacy_policy']) ? (bool) $body['privacy_policy'] : null;
        $termsConsent = isset($body['terms']) ? (bool) $body['terms'] : null;
        $marketingConsent = isset($body['marketing']) ? (bool) $body['marketing'] : null;

        try {
            $consentData = [];

            if ($privacyPolicyConsent !== null) {
                $consentData['privacy_policy'] = [
                    'consented' => $privacyPolicyConsent,
                    'timestamp' => current_time('mysql'),
                    'ip_address' => $this->getClientIp(),
                ];
                update_user_meta($userId, 'match_me_consent_privacy_policy', $consentData['privacy_policy']);
            }

            if ($termsConsent !== null) {
                $consentData['terms'] = [
                    'consented' => $termsConsent,
                    'timestamp' => current_time('mysql'),
                    'ip_address' => $this->getClientIp(),
                ];
                update_user_meta($userId, 'match_me_consent_terms', $consentData['terms']);
            }

            if ($marketingConsent !== null) {
                $consentData['marketing'] = [
                    'consented' => $marketingConsent,
                    'timestamp' => current_time('mysql'),
                    'ip_address' => $this->getClientIp(),
                ];
                update_user_meta($userId, 'match_me_consent_marketing', $consentData['marketing']);
            }

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Consent updated successfully.',
                'consent' => $consentData,
            ], 200);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An error occurred while updating consent. Please try again later.',
                ['status' => 500, 'error_code' => 'CONSENT_UPDATE_ERROR']
            );
        }
    }

    /**
     * GET /wp-json/match-me/v1/gdpr/consent
     * Get user consent status.
     */
    public function getConsent(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error(
                'unauthorized',
                'You must be logged in to view consent.',
                ['status' => 401, 'error_code' => 'AUTHENTICATION_REQUIRED']
            );
        }

        try {
            $consent = [
                'privacy_policy' => get_user_meta($userId, 'match_me_consent_privacy_policy', true),
                'terms' => get_user_meta($userId, 'match_me_consent_terms', true),
                'marketing' => get_user_meta($userId, 'match_me_consent_marketing', true),
            ];

            return new \WP_REST_Response([
                'consent' => $consent,
            ], 200);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'server_error',
                'An error occurred while retrieving consent. Please try again later.',
                ['status' => 500, 'error_code' => 'CONSENT_RETRIEVAL_ERROR']
            );
        }
    }

    /**
     * Get user meta data for export (excluding WordPress core meta).
     */
    private function getUserMeta(int $userId): array
    {
        $meta = get_user_meta($userId);
        $exportMeta = [];

        // Filter out WordPress core meta keys
        $coreMetaKeys = [
            'session_tokens',
            'wp_user_level',
            'wp_user-settings',
            'wp_user-settings-time',
        ];

        foreach ($meta as $key => $values) {
            if (in_array($key, $coreMetaKeys, true)) {
                continue;
            }

            // Get the actual value (not array of values)
            $value = get_user_meta($userId, $key, true);
            $exportMeta[$key] = $value;
        }

        return $exportMeta;
    }

    /**
     * Get quiz results for export.
     */
    private function getQuizResults(int $userId): array
    {
        // New system results
        $newResults = $this->resultRepository->findByUser($userId);
        $results = [];

        foreach ($newResults as $result) {
            $results[] = [
                'system' => 'new',
                'result_id' => (int) ($result['result_id'] ?? 0),
                'quiz_slug' => (string) ($result['quiz_slug'] ?? ''),
                'quiz_version' => (string) ($result['quiz_version'] ?? ''),
                'share_token' => (string) ($result['share_token'] ?? ''),
                'share_mode' => (string) ($result['share_mode'] ?? 'private'),
                'trait_vector' => json_decode($result['trait_vector'] ?? '{}', true),
                'textual_summary_short' => (string) ($result['textual_summary_short'] ?? ''),
                'textual_summary_long' => (string) ($result['textual_summary_long'] ?? ''),
                'created_at' => (string) ($result['created_at'] ?? ''),
                'revoked_at' => (string) ($result['revoked_at'] ?? ''),
            ];
        }

        // Old system results
        $oldResults = $this->oldResultRepository->findByUser($userId);
        foreach ($oldResults as $result) {
            $results[] = [
                'system' => 'old',
                'attempt_id' => (int) ($result['attempt_id'] ?? 0),
                'quiz_id' => (string) ($result['quiz_id'] ?? ''),
                'scores' => (string) ($result['scores'] ?? ''),
                'content' => (string) ($result['content'] ?? ''),
                'created_at' => (string) ($result['created_at'] ?? ''),
            ];
        }

        return $results;
    }

    /**
     * Get comparisons for export.
     */
    private function getComparisons(int $userId): array
    {
        $comparisons = $this->comparisonRepository->findByUser($userId);
        $export = [];

        foreach ($comparisons as $comparison) {
            $export[] = [
                'comparison_id' => (int) ($comparison['id'] ?? 0),
                'result_a_id' => (int) ($comparison['result_a'] ?? 0),
                'result_b_id' => (int) ($comparison['result_b'] ?? 0),
                'share_token' => (string) ($comparison['share_token'] ?? ''),
                'match_score' => (float) ($comparison['match_score'] ?? 0.0),
                'algorithm_used' => (string) ($comparison['algorithm_used'] ?? 'cosine'),
                'breakdown' => json_decode($comparison['breakdown'] ?? '{}', true),
                'comparison_summary_short' => (string) ($comparison['comparison_summary_short'] ?? ''),
                'comparison_summary_long' => (string) ($comparison['comparison_summary_long'] ?? ''),
                'created_at' => (string) ($comparison['created_at'] ?? ''),
            ];
        }

        return $export;
    }

    /**
     * Delete user meta (excluding WordPress core meta).
     */
    private function deleteUserMeta(int $userId): void
    {
        $meta = get_user_meta($userId);
        $coreMetaKeys = [
            'session_tokens',
            'wp_user_level',
            'wp_user-settings',
            'wp_user-settings-time',
            'wp_capabilities',
            'wp_user_level',
        ];

        foreach ($meta as $key => $values) {
            if (in_array($key, $coreMetaKeys, true)) {
                continue;
            }

            // Only delete match-me related meta
            if (str_starts_with($key, 'match_me_') || str_starts_with($key, 'cq_')) {
                delete_user_meta($userId, $key);
            }
        }
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
}

