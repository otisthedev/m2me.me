<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;
use MatchMe\Infrastructure\Db\QuizResultRepository;
use MatchMe\Infrastructure\Db\ResultRepository;
use MatchMe\Infrastructure\Quiz\QuizJsonRepository;
use MatchMe\Wp\Auth\GoogleAuth;
use MatchMe\Wp\Auth\FacebookAuth;
use MatchMe\Wp\Auth\InstagramAuth;
use MatchMe\Wp\Auth\AuthMenu;
use MatchMe\Wp\Rewrite\RsIdRewrite;
use MatchMe\Wp\Rewrite\ShareTokenRewrite;
use MatchMe\Wp\Session\TempResultsAssigner;
use MatchMe\Wp\UserProfilePicture;
use MatchMe\Wp\ShareMeta;
use MatchMe\Wp\ShareImage;

final class QuizFeatureSet
{
    public function __construct(
        private ThemeConfig $config,
        private QuizResultRepository $results,
        private QuizJsonRepository $quizzes,
        private ?ResultRepository $newResultRepo = null,
    ) {
    }

    public function register(): void
    {
        (new RsIdRewrite())->register();
        (new ShareTokenRewrite())->register();

        // Get new repository (use injected or create new)
        $newRepo = $this->newResultRepo;
        if ($newRepo === null) {
            global $wpdb;
            $newRepo = new \MatchMe\Infrastructure\Db\ResultRepository($wpdb);
        }

        (new QuizShortcodes($this->config, $this->results, $this->quizzes, $newRepo))->register();
        (new QuizTitle($this->results))->register();
        (new ThemeTweaks())->register();
        (new QuizAdmin($this->config))->register();
        (new AuthMenu($this->config))->register();
        (new UserProfilePicture())->register();
        (new ShareMeta())->register();
        (new ShareImage())->register();

        // Use same $newRepo for TempResultsAssigner
        $assigner = new TempResultsAssigner($this->results, $newRepo);
        (new GoogleAuth($this->config, $assigner))->register();
        (new FacebookAuth($this->config, $assigner))->register();
        (new InstagramAuth($this->config, $assigner))->register();
    }
}


