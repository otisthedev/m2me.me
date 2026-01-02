<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db\Migrations;

final class CreateQuizTables
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function run(): void
    {
        $charsetCollate = $this->wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->createAspectsTable($charsetCollate);
        $this->createQuizzesTable($charsetCollate);
        $this->createQuestionsTable($charsetCollate);
        $this->createResultsTable($charsetCollate);
        $this->createComparisonsTable($charsetCollate);
    }

    private function createAspectsTable(string $charsetCollate): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_aspects';

        $sql = "CREATE TABLE $tableName (
            aspect_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            description TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (aspect_id),
            UNIQUE KEY slug (slug)
        ) $charsetCollate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private function createQuizzesTable(string $charsetCollate): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_quizzes';

        $sql = "CREATE TABLE $tableName (
            quiz_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL,
            version VARCHAR(20) NOT NULL DEFAULT '1.0',
            aspect_id BIGINT UNSIGNED,
            meta JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (quiz_id),
            UNIQUE KEY slug (slug),
            KEY aspect_id (aspect_id),
            FOREIGN KEY (aspect_id) REFERENCES {$this->wpdb->prefix}match_me_aspects(aspect_id) ON DELETE SET NULL
        ) $charsetCollate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private function createQuestionsTable(string $charsetCollate): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_questions';

        $sql = "CREATE TABLE $tableName (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_id BIGINT UNSIGNED NOT NULL,
            text TEXT NOT NULL,
            options_json JSON NOT NULL,
            weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            trait_map JSON NOT NULL,
            order_index INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY quiz_order (quiz_id, order_index),
            FOREIGN KEY (quiz_id) REFERENCES {$this->wpdb->prefix}match_me_quizzes(quiz_id) ON DELETE CASCADE
        ) $charsetCollate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private function createResultsTable(string $charsetCollate): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_results';

        // Note: Foreign key to quizzes table is optional to allow flexibility during migration
        $sql = "CREATE TABLE $tableName (
            result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_id BIGINT UNSIGNED NOT NULL,
            quiz_slug VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED,
            trait_vector JSON NOT NULL,
            share_token VARCHAR(64) NOT NULL,
            share_mode ENUM('private', 'share', 'share_match') NOT NULL DEFAULT 'private',
            quiz_version VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            PRIMARY KEY (result_id),
            UNIQUE KEY share_token (share_token),
            KEY quiz_user (quiz_id, user_id),
            KEY quiz_slug (quiz_slug),
            KEY quiz_version (quiz_version),
            KEY share_mode_revoked (share_mode, revoked_at),
            FOREIGN KEY (user_id) REFERENCES {$this->wpdb->prefix}users(ID) ON DELETE SET NULL
        ) $charsetCollate ENGINE=InnoDB;";

        dbDelta($sql);
    }

    private function createComparisonsTable(string $charsetCollate): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_comparisons';

        $sql = "CREATE TABLE $tableName (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            result_a BIGINT UNSIGNED NOT NULL,
            result_b BIGINT UNSIGNED NOT NULL,
            share_token VARCHAR(64) NOT NULL,
            match_score DECIMAL(5,2) NOT NULL,
            breakdown JSON NOT NULL,
            algorithm_used VARCHAR(50) NOT NULL DEFAULT 'cosine',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY share_token (share_token),
            KEY result_pair (result_a, result_b),
            KEY created_at (created_at),
            FOREIGN KEY (result_a) REFERENCES {$this->wpdb->prefix}match_me_results(result_id) ON DELETE CASCADE,
            FOREIGN KEY (result_b) REFERENCES {$this->wpdb->prefix}match_me_results(result_id) ON DELETE CASCADE
        ) $charsetCollate ENGINE=InnoDB;";

        dbDelta($sql);
    }
}

