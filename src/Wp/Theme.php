<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\ComparisonRepository;
use MatchMe\Infrastructure\Db\Migrations\CreateQuizTables;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\QuizResultsTable;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Quiz\MatchingService;
use MatchMe\Quiz\QuizCalculator;
use MatchMe\Quiz\ShareTokenGenerator;
use MatchMe\Wp\Ajax\SaveQuizResultsController;
use MatchMe\Wp\Api\QuizApiController;

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
        $targetSchemaVersion = 'quiz-v2-2026-01-03';
        if ($schemaVersion !== $targetSchemaVersion) {
            (new CreateQuizTables($wpdb))->run();
            update_option('match_me_schema_version', $targetSchemaVersion, true);
            // Ensure new rewrite rules are applied after deploy (e.g., /result/{share_token}/).
            flush_rewrite_rules();
        }

        $resultsTable = new QuizResultsTable($wpdb);
        $repo = new QuizResultRepository($wpdb, $resultsTable);
        $activation = new Activation($config, $resultsTable);

        $theme = new self($config, $activation);
        $theme->registerHooks();

        (new SaveQuizResultsController($repo))->register();
        (new QuizFeatureSet($config, $repo, new QuizJsonRepository($config)))->register();

        // Register new API endpoints
        $quizRepo = new QuizJsonRepository($config);
        $calculator = new QuizCalculator();
        $resultRepo = new ResultRepository($wpdb);
        $comparisonRepo = new ComparisonRepository($wpdb);
        $matchingService = new MatchingService($calculator, $resultRepo);
        $tokenGenerator = new ShareTokenGenerator();

        (new QuizApiController(
            $quizRepo,
            $calculator,
            $matchingService,
            $resultRepo,
            $comparisonRepo,
            $tokenGenerator,
            $config
        ))->register();
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
            'primary' => __('Primary Menu', 'match-me'),
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
    }

    public function registerCustomizer(\WP_Customize_Manager $wpCustomize): void
    {
        // Section
        $wpCustomize->add_section('match_me_header', [
            'title' => __('Header', 'match-me'),
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
                'label' => __('Header Logo', 'match-me'),
                'section' => 'match_me_header',
                'settings' => 'match_me_header_logo',
                'mime_type' => 'image',
            ]
        ));
    }
}


