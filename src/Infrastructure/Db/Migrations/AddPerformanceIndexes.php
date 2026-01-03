<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db\Migrations;

/**
 * Migration to add performance indexes to existing tables.
 */
final class AddPerformanceIndexes
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function run(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->addResultsTableIndexes();
        $this->addComparisonsTableIndexes();
    }

    private function addResultsTableIndexes(): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_results';

        // Check if indexes already exist before adding
        $indexes = $this->wpdb->get_results("SHOW INDEXES FROM $tableName", ARRAY_A);
        $existingIndexes = [];
        foreach ($indexes as $index) {
            $existingIndexes[] = $index['Key_name'];
        }

        // Index for user queries with quiz_slug and revoked_at filtering
        if (!in_array('idx_user_quiz_slug_revoked', $existingIndexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("CREATE INDEX idx_user_quiz_slug_revoked ON $tableName (user_id, quiz_slug, revoked_at, created_at)");
        }

        // Index for user queries grouped by quiz_slug
        if (!in_array('idx_user_created', $existingIndexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("CREATE INDEX idx_user_created ON $tableName (user_id, created_at)");
        }

        // Index for finding results by user_id (for GDPR operations)
        if (!in_array('idx_user_id', $existingIndexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("CREATE INDEX idx_user_id ON $tableName (user_id)");
        }
    }

    private function addComparisonsTableIndexes(): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_comparisons';
        $resultsTable = $this->wpdb->prefix . 'match_me_results';

        // Check if indexes already exist
        $indexes = $this->wpdb->get_results("SHOW INDEXES FROM $tableName", ARRAY_A);
        $existingIndexes = [];
        foreach ($indexes as $index) {
            $existingIndexes[] = $index['Key_name'];
        }

        // Index for finding comparisons by result_a or result_b (for GDPR operations)
        // The existing result_pair index covers (result_a, result_b), but we need individual indexes too
        if (!in_array('idx_result_a', $existingIndexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("CREATE INDEX idx_result_a ON $tableName (result_a)");
        }

        if (!in_array('idx_result_b', $existingIndexes, true)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->wpdb->query("CREATE INDEX idx_result_b ON $tableName (result_b)");
        }
    }
}

