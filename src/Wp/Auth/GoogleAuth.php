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
        $current = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $redirectTo = wp_validate_redirect(home_url($current), home_url('/'));
        $authUrl = esc_url(add_query_arg(['google_auth' => '1', 'redirect_to' => $redirectTo], home_url('/')));
        return '<div class="mm-social-auth"><a class="mm-social-auth-btn mm-social-auth-btn-google" href="' . $authUrl . '">Continue with Google</a></div>';
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
        $redirectTo = isset($_GET['redirect_to']) ? (string) $_GET['redirect_to'] : '';
        $redirectTo = wp_validate_redirect($redirectTo, home_url('/'));
        $state = isset($_GET['state']) ? sanitize_text_field((string) $_GET['state']) : '';

        if (!isset($_GET['code'])) {
            // First hop: generate state and store redirect_to transient.
            $state = wp_generate_uuid4();
            set_transient('match_me_oauth_state_' . $state, ['redirect_to' => $redirectTo], 10 * MINUTE_IN_SECONDS);

            $scope = urlencode('https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile');
            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?response_type=code'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&scope=' . $scope
                . '&access_type=online'
                . '&state=' . rawurlencode($state);
            wp_redirect($authUrl);
            exit;
        }

        // Callback: resolve redirect_to from state (preferred).
        if ($state !== '') {
            $stored = get_transient('match_me_oauth_state_' . $state);
            if (is_array($stored) && isset($stored['redirect_to'])) {
                $redirectTo = wp_validate_redirect((string) $stored['redirect_to'], home_url('/'));
            }
            delete_transient('match_me_oauth_state_' . $state);
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
        // Prefer higher-res Google profile photos when possible.
        if ($picture !== '' && str_contains($picture, 'googleusercontent.com')) {
            // Google often returns "...=s96-c". Replace/append a larger size.
            $picture = preg_replace('/=s\\d+-c$/', '=s1024-c', $picture) ?: $picture;
            if (!str_contains($picture, '=s')) {
                $picture .= (str_contains($picture, '?') ? '&' : '?') . 'sz=1024';
            }
        }

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
            if ($name !== '') {
                wp_update_user(['ID' => (int) $userId, 'display_name' => $name]);
            }

            $user = get_user_by('id', (int) $userId);
        } else {
            // Backfill profile fields on existing users if missing.
            $uid = (int) $user->ID;
            if ($firstName !== '' && get_user_meta($uid, 'first_name', true) === '') {
                update_user_meta($uid, 'first_name', $firstName);
            }
            if ($lastName !== '' && get_user_meta($uid, 'last_name', true) === '') {
                update_user_meta($uid, 'last_name', $lastName);
            }
            if ($picture !== '' && get_user_meta($uid, 'profile_picture', true) === '') {
                update_user_meta($uid, 'profile_picture', $picture);
            }
            // If display_name looks like a username/email, improve it.
            $currentDisplay = (string) $user->display_name;
            if ($name !== '' && ($currentDisplay === '' || str_contains($currentDisplay, '@') || $currentDisplay === $user->user_login)) {
                wp_update_user(['ID' => $uid, 'display_name' => $name]);
            }
        }

        if (!$user) {
            wp_die('Login failed.');
        }

        $this->assigner->assignFromSessionToUser((int) $user->ID);
        wp_set_current_user((int) $user->ID);
        wp_set_auth_cookie((int) $user->ID);
        wp_safe_redirect($redirectTo);
        exit;
    }
}



