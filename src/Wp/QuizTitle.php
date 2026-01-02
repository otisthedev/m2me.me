<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Infrastructure\Db\QuizResultRepository;

final class QuizTitle
{
    private string $customTitle = '';

    public function __construct(private QuizResultRepository $results)
    {
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'computeTitle']);
        add_filter('pre_get_document_title', [$this, 'filterDocumentTitle']);
        add_filter('document_title_parts', [$this, 'filterTitleParts']);
        add_filter('wpseo_title', [$this, 'filterYoastTitle'], 10, 1);
        add_filter('wpseo_opengraph_title', [$this, 'filterYoastTitle'], 10, 1);
        add_filter('wpseo_twitter_title', [$this, 'filterYoastTitle'], 10, 1);
        add_filter('wpseo_schema_article', [$this, 'filterYoastSchema'], 10, 2);
        add_filter('wpseo_schema_webpage', [$this, 'filterYoastSchema'], 10, 2);
    }

    public function computeTitle(): void
    {
        global $post;
        if (!is_singular() || !$post || !isset($post->post_name)) {
            return;
        }

        $quizId = (string) $post->post_name;
        $rsId = isset($_GET['rsID']) ? (int) $_GET['rsID'] : 0;

        $row = null;
        if ($rsId > 0) {
            $row = $this->results->findByAttemptId($rsId, $quizId);
        } else {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                $row = $this->results->findLatestByUserAndQuiz($userId, $quizId);
            }
        }

        if (!$row || empty($row['user_id'])) {
            return;
        }

        $user = get_userdata((int) $row['user_id']);
        if (!$user) {
            return;
        }

        $name = $user->first_name !== '' ? $user->first_name : $user->display_name;
        $this->customTitle = "See {$name}'s results";
    }

    public function filterDocumentTitle(string $title): string
    {
        return $this->customTitle !== '' ? $this->customTitle : $title;
    }

    /**
     * @param array<string,mixed> $parts
     * @return array<string,mixed>
     */
    public function filterTitleParts(array $parts): array
    {
        if ($this->customTitle !== '') {
            $parts['title'] = $this->customTitle;
        }
        return $parts;
    }

    public function filterYoastTitle(string $title): string
    {
        return $this->customTitle !== '' ? $this->customTitle : $title;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function filterYoastSchema(array $data, string $context): array
    {
        if ($this->customTitle === '') {
            return $data;
        }

        if ($context === 'article' && isset($data['headline'])) {
            $data['headline'] = $this->customTitle;
        }
        if ($context === 'webpage' && isset($data['name'])) {
            $data['name'] = $this->customTitle;
        }

        return $data;
    }
}



