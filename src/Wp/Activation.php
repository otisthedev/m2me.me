<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\Migrations\CreateQuizTables;
use MatchMe\Infrastructure\Db\QuizResultsTable;

final class Activation
{
    public function __construct(
        private ThemeConfig $config,
        private QuizResultsTable $resultsTable,
    ) {
    }

    public function onThemeSwitch(): void
    {
        $quizDir = $this->config->quizDirectory();
        if (!is_dir($quizDir)) {
            wp_mkdir_p($quizDir);
        }

        // Create/upgrade new modular quiz tables.
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            (new CreateQuizTables($wpdb))->run();
        }

        $this->resultsTable->createOrUpdate();
        flush_rewrite_rules();
    }
}



