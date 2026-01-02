<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\ComparisonRepository;
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
    }

    public function afterSetupTheme(): void
    {
        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
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
}


