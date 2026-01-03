<?php
declare(strict_types=1);

namespace MatchMe\Wp\Auth;

use MatchMe\Config\ThemeConfig;
use MatchMe\Wp\Session\TempResultsAssigner;

final class InstagramAuth
{
    public function __construct(
        private ThemeConfig $config,
        private TempResultsAssigner $assigner,
    )
    {
    }

    public function register(): void
    {
        add_shortcode('instagram_login_button_server', [$this, 'buttonShortcode']);
        add_action('init', [$this, 'handle']);
        add_action('admin_notices', [$this, 'profileNotice']);
        add_action('profile_update', [$this, 'removePlaceholderFlagOnEmailUpdate'], 10, 2);
    }

    public function buttonShortcode(): string
    {
        $appId = $this->config->instagramAppId();
        $appSecret = $this->config->instagramAppSecret();
        if (!$appId || !$appSecret) {
            return '<div class="mm-social-auth"><p class="mm-social-auth-error">Instagram login is not configured.</p></div>';
        }

        if (is_user_logged_in()) {
            return '';
        }

        $current = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $redirectTo = wp_validate_redirect(home_url($current), home_url('/'));
        $authUrl = esc_url(add_query_arg(['instagram_auth' => '1', 'redirect_to' => $redirectTo], home_url('/')));
        return '<div class="mm-social-auth"><a class="mm-social-auth-btn mm-social-auth-btn-instagram" href="' . $authUrl . '">Continue with Instagram</a></div>';
    }

    public function handle(): void
    {
        if (!isset($_GET['instagram_auth'])) {
            return;
        }

        $clientId = $this->config->instagramAppId();
        $clientSecret = $this->config->instagramAppSecret();
        if (!$clientId || !$clientSecret) {
            wp_die('Instagram login is not configured.');
        }

        $redirectUri = home_url('/?instagram_auth=1');
        $redirectTo = isset($_GET['redirect_to']) ? (string) $_GET['redirect_to'] : '';
        $redirectTo = wp_validate_redirect($redirectTo, home_url('/'));

        $state = isset($_GET['state']) ? sanitize_text_field((string) $_GET['state']) : '';

        if (isset($_GET['error'])) {
            $msg = isset($_GET['error_description']) ? sanitize_text_field((string) $_GET['error_description']) : sanitize_text_field((string) $_GET['error']);
            wp_die('Instagram Login Error: ' . esc_html($msg));
        }

        if (!isset($_GET['code'])) {
            // First hop: generate state and store redirect_to transient.
            $state = wp_generate_uuid4();
            set_transient('match_me_oauth_state_' . $state, ['redirect_to' => $redirectTo], 10 * MINUTE_IN_SECONDS);

            $scope = 'user_profile';
            $authUrl = 'https://api.instagram.com/oauth/authorize?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
                'state' => $state,
            ]);
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

        $tokenResponse = wp_remote_post('https://api.instagram.com/oauth/access_token', [
            'body' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($tokenResponse)) {
            wp_die('Error during Instagram token exchange.');
        }

        $tokenBody = json_decode((string) wp_remote_retrieve_body($tokenResponse), true);
        $accessToken = is_array($tokenBody) ? (string) ($tokenBody['access_token'] ?? '') : '';
        if ($accessToken === '') {
            wp_die('No access token received from Instagram.');
        }

        $userInfoResponse = wp_remote_get('https://graph.instagram.com/me?' . http_build_query([
            'fields' => 'id,username',
            'access_token' => $accessToken,
        ]));

        if (is_wp_error($userInfoResponse)) {
            wp_die('Error retrieving user information from Instagram.');
        }

        $userInfo = json_decode((string) wp_remote_retrieve_body($userInfoResponse), true);
        $igId = is_array($userInfo) ? (string) ($userInfo['id'] ?? '') : '';
        $igUsername = is_array($userInfo) ? (string) ($userInfo['username'] ?? '') : '';
        if ($igId === '' || $igUsername === '') {
            wp_die('Could not retrieve valid user details from Instagram.');
        }

        $users = get_users([
            'meta_key' => 'instagram_user_id',
            'meta_value' => $igId,
            'number' => 1,
            'count_total' => false,
        ]);

        if (!empty($users) && $users[0] instanceof \WP_User) {
            $userId = (int) $users[0]->ID;
        } else {
            $base = sanitize_user($igUsername, true);
            if ($base === '') {
                $base = 'instagram_user_' . $igId;
            }

            $username = $base;
            $i = 1;
            while (username_exists($username)) {
                $username = $base . $i;
                $i++;
            }

            $email = $username . '@instagram.invalid';
            $userId = wp_insert_user([
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => wp_generate_password(12, false),
                'display_name' => $igUsername,
                'role' => get_option('default_role'),
            ]);

            if (is_wp_error($userId)) {
                wp_die('Error creating WordPress user account.');
            }

            update_user_meta((int) $userId, 'instagram_user_id', $igId);
            update_user_meta((int) $userId, 'instagram_username', $igUsername);
            update_user_meta((int) $userId, 'has_placeholder_email', 'true');
        }

        $userId = (int) $userId;
        $this->assigner->assignFromSessionToUser($userId);
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
        wp_safe_redirect($redirectTo);
        exit;
    }

    public function profileNotice(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'profile') {
            return;
        }

        $userId = (int) get_current_user_id();
        if (get_user_meta($userId, 'has_placeholder_email', true) !== 'true') {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo wp_kses(
            sprintf(
                '<b>Important:</b> Your account was created via Instagram Login and has a placeholder email address. Please <a href="%s">update your email address</a>.',
                esc_url(admin_url('profile.php'))
            ),
            ['b' => [], 'a' => ['href' => []]]
        );
        echo '</p></div>';
    }

    public function removePlaceholderFlagOnEmailUpdate(int $userId, \WP_User $oldUserData): void
    {
        $newUserData = get_userdata($userId);
        if (!$newUserData) {
            return;
        }

        if ($oldUserData->user_email === $newUserData->user_email) {
            return;
        }

        if (!str_contains((string) $oldUserData->user_email, '@instagram.invalid')) {
            return;
        }

        if (!is_email($newUserData->user_email) || str_contains((string) $newUserData->user_email, '@instagram.invalid')) {
            return;
        }

        delete_user_meta($userId, 'has_placeholder_email');
    }
}



