<?php
declare(strict_types=1);

namespace MatchMe\Wp\Auth;

use MatchMe\Config\ThemeConfig;
use MatchMe\Wp\Session\TempResultsAssigner;

final class GoogleAuth
{
    public function __construct(
        private ThemeConfig $config,
        private TempResultsAssigner $assigner,
    ) {
    }

    public function register(): void
    {
        add_shortcode('google_auth_button', [$this, 'buttonShortcode']);
        add_action('init', [$this, 'handle']);
    }

    public function buttonShortcode(): string
    {
        $authUrl = esc_url(home_url('?google_auth=1'));
        return '<p style="text-align:center;"><a href="' . $authUrl . '">Login with Google</a></p>';
    }

    public function handle(): void
    {
        if (!isset($_GET['google_auth'])) {
            return;
        }

        $clientId = $this->config->googleClientId();
        $clientSecret = $this->config->googleClientSecret();
        if (!$clientId || !$clientSecret) {
            wp_die('Google login is not configured.');
        }

        $redirectUri = home_url('?google_auth=1');

        if (!isset($_GET['code'])) {
            $scope = urlencode('https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile');
            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . $scope
                . '&access_type=online';
            wp_redirect($authUrl);
            exit;
        }

        $code = sanitize_text_field((string) $_GET['code']);

        $tokenResponse = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($tokenResponse)) {
            wp_die('Error during token exchange.');
        }

        $tokenBody = json_decode((string) wp_remote_retrieve_body($tokenResponse), true);
        $accessToken = is_array($tokenBody) ? (string) ($tokenBody['access_token'] ?? '') : '';
        if ($accessToken === '') {
            wp_die('No access token received.');
        }

        $userInfoResponse = wp_remote_get('https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . rawurlencode($accessToken));
        if (is_wp_error($userInfoResponse)) {
            wp_die('Error retrieving user information.');
        }

        $userInfo = json_decode((string) wp_remote_retrieve_body($userInfoResponse), true);
        if (!is_array($userInfo) || empty($userInfo['email'])) {
            wp_die('Unable to retrieve email from Google.');
        }

        $email = sanitize_email((string) $userInfo['email']);
        $name = sanitize_text_field((string) ($userInfo['name'] ?? ''));
        $picture = esc_url_raw((string) ($userInfo['picture'] ?? ''));

        $parts = $name !== '' ? explode(' ', $name, 2) : ['', ''];
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';

        $user = email_exists($email) ? get_user_by('email', $email) : null;
        if (!$user) {
            $username = sanitize_user((string) strtok($email, '@'));
            if (username_exists($username)) {
                $username .= '_' . wp_generate_password(4, false);
            }

            $userId = wp_create_user($username, wp_generate_password(12, false), $email);
            if (is_wp_error($userId)) {
                wp_die('Error creating user.');
            }

            update_user_meta((int) $userId, 'first_name', $firstName);
            update_user_meta((int) $userId, 'last_name', $lastName);
            update_user_meta((int) $userId, 'profile_picture', $picture);

            $user = get_user_by('id', (int) $userId);
        }

        if (!$user) {
            wp_die('Login failed.');
        }

        $this->assigner->assignFromSessionToUser((int) $user->ID);
        wp_set_current_user((int) $user->ID);
        wp_set_auth_cookie((int) $user->ID);
        wp_redirect(home_url('/'));
        exit;
    }
}



