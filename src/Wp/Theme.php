<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\Migrations\AddPerformanceIndexes;
use MatchMe\Infrastructure\Db\Migrations\CreateQuizTables;
use MatchMe\Infrastructure\Db\QuizRepository;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\QuizResultsTable;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Infrastructure\Retention\RetentionPolicy;
use MatchMe\Quiz\MatchingService;
use MatchMe\Quiz\QuizCalculator;
use MatchMe\Quiz\ShareTokenGenerator;
use MatchMe\Wp\Ajax\SaveQuizResultsController;
use MatchMe\Wp\Api\GdprApiController;
use MatchMe\Wp\Api\QuizApiController;
use MatchMe\Wp\RetentionScheduler;

final class Theme
{
    public function __construct(
        private ThemeConfig $config,
        private Activation $activation,
    ) {
    }

    public static function bootstrap(): void
    {
        global $wpdb;

        $config = new ThemeConfig();
        Container::init($config, $wpdb);

        // Ensure new tables exist even if the theme was already active (runs once per schema version).
        $schemaVersion = (string) get_option('match_me_schema_version', '');
        $targetSchemaVersion = 'quiz-v2-2026-01-08';
        if ($schemaVersion !== $targetSchemaVersion) {
            (new CreateQuizTables($wpdb))->run();
            update_option('match_me_schema_version', $targetSchemaVersion, true);
            // Ensure new rewrite rules are applied after deploy (e.g., /result/{share_token}/).
            flush_rewrite_rules();
        }

        // Add performance indexes (runs once per index version).
        $indexVersion = (string) get_option('match_me_index_version', '');
        $targetIndexVersion = 'indexes-2026-01-08';
        if ($indexVersion !== $targetIndexVersion) {
            (new AddPerformanceIndexes($wpdb))->run();
            update_option('match_me_index_version', $targetIndexVersion, true);
        }

        $resultsTable = new QuizResultsTable($wpdb);
        $repo = new QuizResultRepository($wpdb, $resultsTable);
        $activation = new Activation($config, $resultsTable);

        $theme = new self($config, $activation);
        $theme->registerHooks();

        (new SaveQuizResultsController($repo))->register();

        // Register new API endpoints
        $quizRepo = new QuizJsonRepository($config);
        $calculator = new QuizCalculator();
        $resultRepo = new ResultRepository($wpdb);
        $comparisonRepo = new ComparisonRepository($wpdb);
        $quizDbRepo = new QuizRepository($wpdb);
        $matchingService = new MatchingService($calculator, $resultRepo);
        $tokenGenerator = new ShareTokenGenerator();

        (new QuizFeatureSet($config, $repo, new QuizJsonRepository($config), $resultRepo))->register();

        (new QuizApiController(
            $quizRepo,
            $calculator,
            $matchingService,
            $resultRepo,
            $comparisonRepo,
            $quizDbRepo,
            $tokenGenerator,
            $config
        ))->register();

        // Register GDPR compliance endpoints
        (new GdprApiController(
            $resultRepo,
            $comparisonRepo,
            $repo
        ))->register();

        // Register retention policy scheduler
        $retentionPolicy = new RetentionPolicy($resultRepo, $comparisonRepo, $wpdb);
        (new RetentionScheduler($retentionPolicy))->register();
    }

    public function registerHooks(): void
    {
        add_action('after_setup_theme', [$this, 'afterSetupTheme']);
        add_action('after_switch_theme', [$this->activation, 'onThemeSwitch']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueCoreStyles']);
        add_action('customize_register', [$this, 'registerCustomizer']);
    }

    public function afterSetupTheme(): void
    {
        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
        register_nav_menus([
            'primary' => 'Primary Menu',
            'footer' => 'Footer Menu',
        ]);
        add_theme_support('html5', [
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
        ]);
    }

    public function enqueueCoreStyles(): void
    {
        wp_enqueue_style('match-me-theme', get_stylesheet_uri(), [], $this->config->themeVersion());
        wp_enqueue_script('match-me-theme', get_template_directory_uri() . '/assets/js/theme.js', [], $this->config->themeVersion(), true);

        $vars = [
            'homeUrl' => home_url('/'),
            'themeUrl' => get_template_directory_uri(),
            'restUrl' => esc_url_raw(rest_url()),
            'restNonce' => wp_create_nonce('wp_rest'),
            'currentUser' => [
                'id' => (int) get_current_user_id(),
                'name' => is_user_logged_in() ? (string) (wp_get_current_user()->display_name ?: 'You') : '',
                'avatarUrl' => is_user_logged_in() ? (string) get_avatar_url((int) get_current_user_id(), ['size' => 256]) : '',
            ],
        ];
        wp_add_inline_script('match-me-theme', 'window.matchMeTheme=' . wp_json_encode($vars) . ';', 'before');
    }

    public function registerCustomizer(\WP_Customize_Manager $wpCustomize): void
    {
        // Section
        $wpCustomize->add_section('match_me_header', [
            'title' => 'Header',
            'priority' => 30,
        ]);

        // Setting: header logo (attachment ID)
        $wpCustomize->add_setting('match_me_header_logo', [
            'default' => 0,
            'sanitize_callback' => 'absint',
        ]);

        // Control
        $wpCustomize->add_control(new \WP_Customize_Media_Control(
            $wpCustomize,
            'match_me_header_logo_control',
            [
                'label' => 'Header Logo',
                'section' => 'match_me_header',
                'settings' => 'match_me_header_logo',
                'mime_type' => 'image',
            ]
        ));
    }
}


