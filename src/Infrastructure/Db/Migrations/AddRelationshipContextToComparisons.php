<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Db\Migrations;

final class AddRelationshipContextToComparisons
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function run(): void
    {
        $tableName = $this->wpdb->prefix . 'match_me_comparisons';

        // Check if column already exists
        $columnExists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = %s 
                 AND COLUMN_NAME = 'relationship_context'",
                $tableName
            )
        );

        if ($columnExists == 0) {
            $this->wpdb->query(
                "ALTER TABLE {$tableName} 
                 ADD COLUMN relationship_context ENUM('partner', 'friend', 'colleague', 'family', 'other', 'unspecified') 
                 NOT NULL DEFAULT 'unspecified' 
                 AFTER algorithm_used"
            );
        }
    }
}

