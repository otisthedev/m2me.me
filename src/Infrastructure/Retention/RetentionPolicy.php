<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Retention;

use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\ResultRepository;

/**
 * Handles data retention policies for GDPR compliance.
 * 
 * Note: Retention periods should be configured based on legal/product requirements.
 * Default values are conservative and should be reviewed.
 */
final class RetentionPolicy
{
    // Default retention periods (in days)
    // These should be configured via options or constants based on legal requirements
    private const ANONYMOUS_RESULTS_RETENTION_DAYS = 365; // 1 year default
    private const REVOKED_TOKENS_RETENTION_DAYS = 90; // 90 days default
    private const USER_DELETION_GRACE_PERIOD_DAYS = 30; // 30 days grace period

    public function __construct(
        private ResultRepository $resultRepository,
        private ComparisonRepository $comparisonRepository,
        private \wpdb $wpdb
    ) {
    }

    /**
     * Clean up expired anonymous results (user_id IS NULL).
     * 
     * @return int Number of deleted results
     */
    public function cleanupAnonymousResults(int $retentionDays = self::ANONYMOUS_RESULTS_RETENTION_DAYS): int
    {
        $table = $this->wpdb->prefix . 'match_me_results';
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM $table WHERE user_id IS NULL AND created_at < %s",
                $cutoffDate
            )
        );

        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Clean up revoked tokens older than retention period.
     * 
     * @return int Number of deleted results
     */
    public function cleanupRevokedTokens(int $retentionDays = self::REVOKED_TOKENS_RETENTION_DAYS): int
    {
        $table = $this->wpdb->prefix . 'match_me_results';
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM $table WHERE revoked_at IS NOT NULL AND revoked_at < %s",
                $cutoffDate
            )
        );

        return $deleted !== false ? (int) $deleted : 0;
    }

    /**
     * Run all retention cleanup tasks.
     * 
     * @return array{anonymous_results: int, revoked_tokens: int}
     */
    public function runCleanup(): array
    {
        // Get retention periods from options (if configured)
        $anonymousRetention = (int) get_option('match_me_retention_anonymous_days', self::ANONYMOUS_RESULTS_RETENTION_DAYS);
        $revokedRetention = (int) get_option('match_me_retention_revoked_days', self::REVOKED_TOKENS_RETENTION_DAYS);

        return [
            'anonymous_results' => $this->cleanupAnonymousResults($anonymousRetention),
            'revoked_tokens' => $this->cleanupRevokedTokens($revokedRetention),
        ];
    }

    /**
     * Get retention policy configuration.
     * 
     * @return array<string, mixed>
     */
    public function getPolicy(): array
    {
        return [
            'anonymous_results_retention_days' => (int) get_option('match_me_retention_anonymous_days', self::ANONYMOUS_RESULTS_RETENTION_DAYS),
            'revoked_tokens_retention_days' => (int) get_option('match_me_retention_revoked_days', self::REVOKED_TOKENS_RETENTION_DAYS),
            'user_deletion_grace_period_days' => (int) get_option('match_me_retention_grace_period_days', self::USER_DELETION_GRACE_PERIOD_DAYS),
        ];
    }
}

