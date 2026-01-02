<?php
declare(strict_types=1);

namespace MatchMe\Config;

final class ThemeConfig
{
    public function themeVersion(): string
    {
        return (string) (defined('MATCH_ME_VERSION') ? MATCH_ME_VERSION : '1.0');
    }

    public function quizDirectory(): string
    {
        $dir = WP_CONTENT_DIR . '/X-quizzes/';
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function homeUrl(): string
    {
        return (string) home_url('/');
    }

    public function googleClientId(): ?string
    {
        return $this->readSecret('MATCH_ME_GOOGLE_CLIENT_ID');
    }

    public function googleClientSecret(): ?string
    {
        return $this->readSecret('MATCH_ME_GOOGLE_CLIENT_SECRET');
    }

    public function facebookAppId(): ?string
    {
        return $this->readSecret('MATCH_ME_FACEBOOK_APP_ID');
    }

    public function facebookAppSecret(): ?string
    {
        return $this->readSecret('MATCH_ME_FACEBOOK_APP_SECRET');
    }

    public function instagramAppId(): ?string
    {
        return $this->readSecret('MATCH_ME_INSTAGRAM_APP_ID');
    }

    public function instagramAppSecret(): ?string
    {
        return $this->readSecret('MATCH_ME_INSTAGRAM_APP_SECRET');
    }

    public function requireLoginForResults(): bool
    {
        // Check if option exists to avoid defaulting when value is '0'
        $optionExists = get_option('match_me_require_login_for_results') !== false;
        if (!$optionExists) {
            return true; // Default to true if option doesn't exist
        }
        
        $value = get_option('match_me_require_login_for_results', '1');
        // Handle both string ('1'/'0') and boolean values for backward compatibility
        if (is_bool($value)) {
            return $value;
        }
        return (string) $value === '1';
    }

    private function readSecret(string $key): ?string
    {
        if (defined($key)) {
            $value = (string) constant($key);
            return $value !== '' ? $value : null;
        }

        $env = getenv($key);
        if ($env !== false) {
            $value = (string) $env;
            return $value !== '' ? $value : null;
        }

        return null;
    }
}


