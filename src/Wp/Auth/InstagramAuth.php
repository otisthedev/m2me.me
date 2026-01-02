<?php
declare(strict_types=1);

namespace MatchMe\Wp\Auth;

use MatchMe\Config\ThemeConfig;

final class InstagramAuth
{
    public function __construct(private ThemeConfig $config)
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
            return '<div class="instagram-login-container"><p style="color:red;">Instagram Login Error: App ID or Secret not configured.</p></div>';
        }

        if (is_user_logged_in()) {
            return '';
        }

        $authUrl = esc_url(home_url('/?instagram_auth=1'));
        $html = '<div class="instagram-login-container" style="background-color:#f0f0f0;padding:20px;border-radius:8px;text-align:center;max-width:300px;margin:20px auto;">';
        $html .= '<a href="' . $authUrl . '" style="background:radial-gradient(circle at 30% 107%,#fdf497 0%,#fdf497 5%,#fd5949 45%,#d6249f 60%,#285AEB 90%);color:white;padding:10px 20px;text-decoration:none;border-radius:5px;font-weight:bold;display:inline-block;font-family:sans-serif;font-size:16px;">';
        $html .= 'Login with Instagram';
        $html .= '</a></div>';
        return $html;
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

        if (isset($_GET['error'])) {
            $msg = isset($_GET['error_description']) ? sanitize_text_field((string) $_GET['error_description']) : sanitize_text_field((string) $_GET['error']);
            wp_die('Instagram Login Error: ' . esc_html($msg));
        }

        if (!isset($_GET['code'])) {
            $scope = 'user_profile';
            $authUrl = 'https://api.instagram.com/oauth/authorize?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
            ]);
            wp_redirect($authUrl);
            exit;
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
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
        wp_redirect(home_url('/'));
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



