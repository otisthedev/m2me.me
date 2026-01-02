<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db;

final class QuizResultsTable
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function name(): string
    {
        return $this->wpdb->prefix . 'cq_quiz_results';
    }

    public function createOrUpdate(): void
    {
        $tableName = $this->name();
        $charsetCollate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tableName (
            attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            quiz_id VARCHAR(100) NOT NULL,
            scores TEXT NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (attempt_id),
            KEY user_quiz (user_id, quiz_id)
        ) $charsetCollate ENGINE=InnoDB;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}



