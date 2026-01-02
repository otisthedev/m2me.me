<?php
declare(strict_types=1);

namespace MatchMe\Wp\Auth;

use MatchMe\Config\ThemeConfig;
use MatchMe\Wp\Session\TempResultsAssigner;

final class FacebookAuth
{
    public function __construct(
        private ThemeConfig $config,
        private TempResultsAssigner $assigner,
    ) {
    }

    public function register(): void
    {
        add_shortcode('facebook_login_button_server', [$this, 'buttonShortcode']);
        add_action('init', [$this, 'handle']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueSdk']);
    }

    public function buttonShortcode(): string
    {
        if (is_user_logged_in()) {
            return '';
        }

        $authUrl = esc_url(home_url('/?facebook_auth=1'));

        $html = '<div class="facebook-login-container" style="background-color:#f7f7f7;padding:20px;border-radius:8px;text-align:center;max-width:300px;margin:20px auto;">';
        $html .= '<a href="' . $authUrl . '" style="background-color:#1877f2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;font-weight:bold;display:inline-block;font-family:sans-serif;font-size:16px;">';
        $html .= 'Login with Facebook';
        $html .= '</a></div>';

        return $html;
    }

    public function handle(): void
    {
        if (!isset($_GET['facebook_auth'])) {
            return;
        }

        $appId = $this->config->facebookAppId();
        $appSecret = $this->config->facebookAppSecret();
        if (!$appId || !$appSecret) {
            wp_die('Facebook login is not configured.');
        }

        $redirectUri = home_url('/?facebook_auth=1');

        if (isset($_GET['error'])) {
            $msg = isset($_GET['error_description']) ? sanitize_text_field((string) $_GET['error_description']) : sanitize_text_field((string) $_GET['error']);
            wp_die('Facebook Login Error: ' . esc_html($msg));
        }

        if (!isset($_GET['code'])) {
            $scope = 'email,public_profile';
            $url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
                'client_id' => $appId,
                'redirect_uri' => $redirectUri,
                'scope' => $scope,
                'response_type' => 'code',
            ]);
            wp_redirect($url);
            exit;
        }

        $code = sanitize_text_field((string) $_GET['code']);

        $tokenUrl = 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'client_secret' => $appSecret,
            'code' => $code,
        ]);

        $tokenResponse = wp_remote_get($tokenUrl);
        if (is_wp_error($tokenResponse)) {
            wp_die('Error during Facebook token exchange.');
        }

        $tokenBody = json_decode((string) wp_remote_retrieve_body($tokenResponse), true);
        $accessToken = is_array($tokenBody) ? (string) ($tokenBody['access_token'] ?? '') : '';
        if ($accessToken === '') {
            wp_die('No access token received from Facebook.');
        }

        $userInfoUrl = 'https://graph.facebook.com/me?' . http_build_query([
            'fields' => 'id,name,email,first_name,last_name,picture.type(large)',
            'access_token' => $accessToken,
        ]);

        $userInfoResponse = wp_remote_get($userInfoUrl);
        if (is_wp_error($userInfoResponse)) {
            wp_die('Error retrieving user information from Facebook.');
        }

        $userInfo = json_decode((string) wp_remote_retrieve_body($userInfoResponse), true);
        if (!is_array($userInfo) || empty($userInfo['email'])) {
            wp_die('Unable to retrieve email from Facebook.');
        }

        $email = sanitize_email((string) $userInfo['email']);
        $firstName = isset($userInfo['first_name']) ? sanitize_text_field((string) $userInfo['first_name']) : '';
        $lastName = isset($userInfo['last_name']) ? sanitize_text_field((string) $userInfo['last_name']) : '';
        $displayName = isset($userInfo['name']) ? sanitize_text_field((string) $userInfo['name']) : '';
        $pictureUrl = isset($userInfo['picture']['data']['url']) ? esc_url_raw((string) $userInfo['picture']['data']['url']) : '';
        $fbUserId = isset($userInfo['id']) ? sanitize_text_field((string) $userInfo['id']) : '';

        $user = get_user_by('email', $email);
        if ($user) {
            $userId = (int) $user->ID;
            update_user_meta($userId, 'facebook_user_id', $fbUserId);
            update_user_meta($userId, 'facebook_profile_picture', $pictureUrl);
        } else {
            $base = sanitize_user((string) strtok($email, '@'), true);
            $username = $base !== '' ? $base : 'fb_user';
            $i = 1;
            while (username_exists($username)) {
                $username = $base . $i;
                $i++;
            }

            $userId = wp_insert_user([
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => wp_generate_password(12, false),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'role' => get_option('default_role'),
            ]);

            if (is_wp_error($userId)) {
                wp_die('Error creating WordPress user account.');
            }

            update_user_meta((int) $userId, 'facebook_user_id', $fbUserId);
            update_user_meta((int) $userId, 'profile_picture', $pictureUrl);
        }

        $userId = (int) $userId;
        $this->assigner->assignFromSessionToUser($userId);
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
        wp_redirect(home_url('/'));
        exit;
    }

    public function enqueueSdk(): void
    {
        $appId = $this->config->facebookAppId();
        if (!$appId) {
            return;
        }

        $inline = "window.fbAsyncInit=function(){FB.init({appId:'" . esc_js($appId) . "',xfbml:true,version:'v22.0'});FB.AppEvents&&FB.AppEvents.logPageView&&FB.AppEvents.logPageView();};(function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(d.getElementById(id)){return;}js=d.createElement(s);js.id=id;js.src='https://connect.facebook.net/en_US/sdk.js';fjs.parentNode.insertBefore(js,fjs);}(document,'script','facebook-jssdk'));";
        wp_register_script('match-me-facebook-sdk', '', [], null, true);
        wp_enqueue_script('match-me-facebook-sdk');
        wp_add_inline_script('match-me-facebook-sdk', $inline);
    }
}



