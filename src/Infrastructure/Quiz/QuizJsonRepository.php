<?php
declare(strict_types=1);

namespace MatchMe\Infrastructure\Quiz;

use MatchMe\Config\ThemeConfig;

final class QuizJsonRepository
{
    public function __construct(private ThemeConfig $config)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function load(string $quizId): array
    {
        $quizId = sanitize_file_name($quizId);
        if ($quizId === '') {
            throw new \InvalidArgumentException('Quiz ID is required.');
        }

        // Detect current language from Polylang
        $lang = $this->getCurrentLanguage();
        $langSuffix = ($lang === 'ru') ? '-ru' : '';
        
        // Try language-specific file first, fallback to default
        $file = $this->config->quizDirectory() . $quizId . $langSuffix . '.json';
        if (!is_file($file)) {
            // Fallback to default (English) file
            $file = $this->config->quizDirectory() . $quizId . '.json';
        }
        
        if (!is_file($file)) {
            throw new \RuntimeException('Quiz not found.');
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException('Failed to read quiz file.');
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid quiz format.');
        }

        $this->validate($data);

        /** @var array<string,mixed> $data */
        return $data;
    }

    /**
     * Get current language code from Polylang, fallback to 'en'
     */
    private function getCurrentLanguage(): string
    {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language();
            return is_string($lang) && $lang !== '' ? $lang : 'en';
        }
        return 'en';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validate(array $data): void
    {
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            throw new \RuntimeException('Quiz meta missing.');
        }
        if (!isset($data['meta']['title']) || !is_string($data['meta']['title'])) {
            throw new \RuntimeException('Quiz title missing.');
        }
        if (!isset($data['meta']['description']) || !is_string($data['meta']['description'])) {
            throw new \RuntimeException('Quiz description missing.');
        }
        if (!isset($data['questions']) || !is_array($data['questions']) || $data['questions'] === []) {
            throw new \RuntimeException('Quiz questions missing.');
        }
        if (!isset($data['results']) || !is_array($data['results'])) {
            throw new \RuntimeException('Quiz results missing.');
        }
    }
}



