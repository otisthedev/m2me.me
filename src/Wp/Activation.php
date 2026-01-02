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

        $this->ensureAccountPage();
        $this->ensureProfilePage();

        // Create/upgrade new modular quiz tables.
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            (new CreateQuizTables($wpdb))->run();
        }

        $this->resultsTable->createOrUpdate();
        flush_rewrite_rules();
    }

    private function ensureAccountPage(): void
    {
        $slug = 'account';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'Account',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        // Assign the theme template if present.
        $templateFile = (string) get_template_directory() . '/page-account.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-account.php');
        }
    }

    private function ensureProfilePage(): void
    {
        $slug = 'profile';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'My Profile',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-profile.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-profile.php');
        }
    }
}



