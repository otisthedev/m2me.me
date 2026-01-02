<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Wp\Auth\GoogleAuth;
use MatchMe\Wp\Auth\FacebookAuth;
use MatchMe\Wp\Auth\InstagramAuth;
use MatchMe\Wp\Auth\AuthMenu;
use MatchMe\Wp\Rewrite\RsIdRewrite;
use MatchMe\Wp\Session\TempResultsAssigner;

final class QuizFeatureSet
{
    public function __construct(
        private ThemeConfig $config,
        private QuizResultRepository $results,
        private QuizJsonRepository $quizzes,
    ) {
    }

    public function register(): void
    {
        (new RsIdRewrite())->register();
        (new QuizShortcodes($this->config, $this->results, $this->quizzes))->register();
        (new QuizTitle($this->results))->register();
        (new ThemeTweaks())->register();
        (new QuizAdmin($this->config))->register();
        (new AuthMenu($this->config))->register();

        $assigner = new TempResultsAssigner($this->results);
        (new GoogleAuth($this->config, $assigner))->register();
        (new FacebookAuth($this->config, $assigner))->register();
        (new InstagramAuth($this->config, $assigner))->register();
    }
}


