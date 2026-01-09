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
        // Handle OAuth early (before anything can redirect logged-out users).
        add_action('init', [$this, 'handle'], 0);
        add_action('init', [$this, 'handleDeletionCallback']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueSdk']);
    }

    public function buttonShortcode(): string
    {
        if (is_user_logged_in()) {
            return '';
        }

        $current = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $redirectTo = wp_validate_redirect(home_url($current), home_url('/'));
        $authUrl = esc_url(add_query_arg(['facebook_auth' => '1', 'redirect_to' => $redirectTo], home_url('/')));

        return '<div class="mm-social-auth"><a class="mm-social-auth-btn mm-social-auth-btn-facebook" href="' . $authUrl . '">Continue with Facebook</a></div>';
    }

    public function handle(): void
    {
        if (!isset($_GET['facebook_auth'])) {
            return;
        }

        // Check config FIRST
        $appId = $this->config->facebookAppId();
        $appSecret = $this->config->facebookAppSecret();
        if (!$appId || !$appSecret) {
            // Show error but don't redirect to login
            status_header(200);
            wp_die(
                '<h1>Facebook Login Error</h1><p>Facebook login is not configured. Please contact the site administrator.</p>',
                'Facebook Login Not Configured',
                ['response' => 200]
            );
            return;
        }

        // Prevent WordPress from redirecting before we handle this
        remove_action('template_redirect', 'redirect_canonical');

        $redirectUri = home_url('/?facebook_auth=1');

        if (isset($_GET['error'])) {
            $msg = isset($_GET['error_description']) ? sanitize_text_field((string) $_GET['error_description']) : sanitize_text_field((string) $_GET['error']);
            wp_die('Facebook Login Error: ' . esc_html($msg));
        }

        if (!isset($_GET['code'])) {
            // First hop: generate state and store redirect_to transient.
            $redirectTo = isset($_GET['redirect_to']) ? (string) $_GET['redirect_to'] : '';
            $redirectTo = wp_validate_redirect($redirectTo, home_url('/'));
            
            $state = wp_generate_uuid4();
            set_transient('match_me_oauth_state_' . $state, ['redirect_to' => $redirectTo], 10 * MINUTE_IN_SECONDS);

            $scope = 'email,public_profile';
            $url = 'https://www.facebook.com/v24.0/dialog/oauth?' . http_build_query([
                'client_id' => $appId,
                'redirect_uri' => urlencode($redirectUri),
                'scope' => $scope,
                'response_type' => 'code',
                'state' => $state,
            ]);
            // Use wp_redirect for external OAuth provider URLs; wp_safe_redirect would reject
            // non-local hosts and fall back to wp-admin (which then redirects to wp-login).
            wp_redirect($url);
            exit;
        }

        // Callback: handle the OAuth response
        $code = sanitize_text_field((string) $_GET['code']);
        $incomingState = isset($_GET['state']) ? sanitize_text_field((string) $_GET['state']) : '';
        
        // FIRST: Try to get redirect from state transient
        if ($incomingState !== '') {
            $stored = get_transient('match_me_oauth_state_' . $incomingState);
            if (is_array($stored) && isset($stored['redirect_to'])) {
                $redirectTo = wp_validate_redirect((string) $stored['redirect_to'], home_url('/'));
                delete_transient('match_me_oauth_state_' . $incomingState);
            } else {
                // State is invalid/expired
                wp_die('Invalid or expired OAuth state. Please try logging in again.');
            }
        }
        // Only use GET parameter if state wasn't valid
        elseif (isset($_GET['redirect_to'])) {
            $redirectTo = wp_validate_redirect((string) $_GET['redirect_to'], home_url('/'));
        } else {
            $redirectTo = home_url('/');
        }

        $tokenUrl = 'https://graph.facebook.com/v24.0/oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'client_secret' => $appSecret,
            'code' => $code,
        ]);

        $tokenResponse = wp_remote_get($tokenUrl);
        if (is_wp_error($tokenResponse)) {
            error_log('Facebook token exchange error: ' . $tokenResponse->get_error_message());
            wp_die('Error during Facebook token exchange. Check server logs.');
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
            error_log('Facebook user info error: ' . $userInfoResponse->get_error_message());
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

        // Account linking: Check if user with this email already exists
        $user = get_user_by('email', $email);
        if ($user) {
            // Link Facebook account to existing user
            $userId = (int) $user->ID;
            update_user_meta($userId, 'facebook_user_id', $fbUserId);
            update_user_meta($userId, 'facebook_profile_picture', $pictureUrl);
            if ($pictureUrl !== '') {
                update_user_meta($userId, 'profile_picture', $pictureUrl);
            }
            // Backfill names if missing.
            if ($firstName !== '' && get_user_meta($userId, 'first_name', true) === '') {
                update_user_meta($userId, 'first_name', $firstName);
            }
            if ($lastName !== '' && get_user_meta($userId, 'last_name', true) === '') {
                update_user_meta($userId, 'last_name', $lastName);
            }
            // Improve display name if needed.
            $currentDisplay = (string) $user->display_name;
            if ($displayName !== '' && ($currentDisplay === '' || str_contains($currentDisplay, '@') || $currentDisplay === $user->user_login)) {
                wp_update_user(['ID' => $userId, 'display_name' => $displayName]);
            }
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
                error_log('WordPress user creation error: ' . $userId->get_error_message());
                wp_die('Error creating WordPress user account.');
            }

            update_user_meta((int) $userId, 'facebook_user_id', $fbUserId);
            update_user_meta((int) $userId, 'facebook_profile_picture', $pictureUrl);
            update_user_meta((int) $userId, 'profile_picture', $pictureUrl);
        }

        $userId = (int) $userId;
        $this->assigner->assignFromSessionToUser($userId);
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
        wp_safe_redirect($redirectTo);
        exit;
    }

    public function enqueueSdk(): void
    {
        $appId = $this->config->facebookAppId();
        if (!$appId) {
            return;
        }

        $inline = "window.fbAsyncInit=function(){FB.init({appId:'" . esc_js($appId) . "',xfbml:true,version:'v24.0'});FB.AppEvents&&FB.AppEvents.logPageView&&FB.AppEvents.logPageView();};(function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];if(d.getElementById(id)){return;}js=d.createElement(s);js.id=id;js.src='https://connect.facebook.net/en_US/sdk.js';fjs.parentNode.insertBefore(js,fjs);}(document,'script','facebook-jssdk'));";
        wp_register_script('match-me-facebook-sdk', '', [], null, true);
        wp_enqueue_script('match-me-facebook-sdk');
        wp_add_inline_script('match-me-facebook-sdk', $inline);
    }

    /**
     * Handle Facebook data deletion callback.
     * This endpoint is called by Facebook when a user requests data deletion.
     * 
     * Facebook sends a POST request with a signed_request parameter that must be verified.
     * URL: https://YOUR-DOMAIN/?facebook_deletion_callback=1
     */
    public function handleDeletionCallback(): void
    {
        // Only handle POST requests with the deletion callback parameter
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        if (!isset($_GET['facebook_deletion_callback']) || $requestMethod !== 'POST') {
            return;
        }

        $appSecret = $this->config->facebookAppSecret();
        if (!$appSecret) {
            // Return response even if not configured (Facebook expects a response)
            $this->sendDeletionResponse('unknown');
            return;
        }

        // Get signed_request from POST body (Facebook sends it as form data)
        $signedRequest = '';
        if (isset($_POST['signed_request'])) {
            $signedRequest = sanitize_text_field((string) $_POST['signed_request']);
        } elseif (isset($_POST['signedRequest'])) {
            $signedRequest = sanitize_text_field((string) $_POST['signedRequest']);
        } else {
            // Try to get from raw POST body (JSON)
            $rawBody = file_get_contents('php://input');
            if ($rawBody !== false) {
                $body = json_decode($rawBody, true);
                if (is_array($body) && isset($body['signed_request'])) {
                    $signedRequest = sanitize_text_field((string) $body['signed_request']);
                }
            }
        }

        if ($signedRequest === '') {
            // Return response even if missing (Facebook expects a response)
            $this->sendDeletionResponse('unknown');
            return;
        }

        // Parse and verify the signed_request
        $data = $this->parseSignedRequest($signedRequest, $appSecret);
        if ($data === null) {
            // Return response even if invalid (Facebook expects a response)
            $this->sendDeletionResponse('unknown');
            return;
        }

        // Extract user_id from the signed request
        $fbUserId = isset($data['user_id']) ? sanitize_text_field((string) $data['user_id']) : '';
        if ($fbUserId === '') {
            // Return response even if missing user_id (Facebook expects a response)
            $this->sendDeletionResponse('unknown');
            return;
        }

        // Find WordPress user by facebook_user_id meta
        $users = get_users([
            'meta_key' => 'facebook_user_id',
            'meta_value' => $fbUserId,
            'number' => 1,
            'count_total' => false,
        ]);

        if (empty($users) || !($users[0] instanceof \WP_User)) {
            // User not found, but return success anyway (user may have already been deleted)
            $this->sendDeletionResponse($fbUserId);
            return;
        }

        $userId = (int) $users[0]->ID;

        // Delete user data
        try {
            $this->deleteUserData($userId);
            $this->sendDeletionResponse($fbUserId);
        } catch (\Throwable $e) {
            // Log error but still return success to Facebook (we'll handle deletion manually)
            error_log('Facebook deletion callback error: ' . $e->getMessage());
            $this->sendDeletionResponse($fbUserId);
        }
    }

    /**
     * Parse and verify Facebook signed_request.
     * 
     * @param string $signedRequest The signed_request from Facebook
     * @param string $appSecret The Facebook App Secret
     * @return array|null Parsed data or null if invalid
     */
    private function parseSignedRequest(string $signedRequest, string $appSecret): ?array
    {
        // Split signed_request into signature and payload
        $parts = explode('.', $signedRequest, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedSig, $payload] = $parts;

        // Decode the payload
        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($data)) {
            return null;
        }

        // Verify the signature
        $expectedSig = $this->base64UrlDecode($encodedSig);
        $algorithm = isset($data['algorithm']) ? (string) $data['algorithm'] : 'HMAC-SHA256';
        
        if ($algorithm !== 'HMAC-SHA256') {
            return null;
        }

        $expectedSigHash = hash_hmac('sha256', $payload, $appSecret, true);
        if (!hash_equals($expectedSigHash, $expectedSig)) {
            return null;
        }

        return $data;
    }

    /**
     * Base64 URL decode (Facebook uses URL-safe base64).
     */
    private function base64UrlDecode(string $data): string
    {
        // Add padding if needed
        $padding = strlen($data) % 4;
        if ($padding !== 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        // Replace URL-safe characters
        $data = str_replace(['-', '_'], ['+', '/'], $data);

        return base64_decode($data, true);
    }

    /**
     * Delete all user data (results, comparisons, meta, and user account).
     */
    private function deleteUserData(int $userId): void
    {
        global $wpdb;

        // Delete legacy quiz results
        if ($wpdb instanceof \wpdb) {
            $legacyTable = $wpdb->prefix . 'cq_quiz_results';
            $wpdb->query($wpdb->prepare("DELETE FROM {$legacyTable} WHERE user_id = %d", $userId));

            // Delete v2 results (match_me_results)
            $resultsTable = $wpdb->prefix . 'match_me_results';
            $wpdb->query($wpdb->prepare("DELETE FROM {$resultsTable} WHERE user_id = %d", $userId));

            // Delete comparisons involving this user's results
            $comparisonsTable = $wpdb->prefix . 'match_me_comparisons';
            $resultIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT result_id FROM {$resultsTable} WHERE user_id = %d",
                    $userId
                )
            );
            if (!empty($resultIds)) {
                $placeholders = implode(',', array_fill(0, count($resultIds), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$comparisonsTable} WHERE result_a IN ($placeholders) OR result_b IN ($placeholders)",
                        ...array_merge($resultIds, $resultIds)
                    )
                );
            }
        }

        // Delete uploaded profile picture attachment if exists
        $attachmentId = (int) get_user_meta($userId, 'profile_picture_attachment_id', true);
        if ($attachmentId > 0) {
            wp_delete_attachment($attachmentId, true);
        }

        // Delete the WordPress user account (also removes user meta)
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);
    }

    /**
     * Send JSON response to Facebook for data deletion callback.
     * 
     * Facebook requires a JSON response with:
     * - url: URL where users can check deletion status (optional but recommended)
     * - confirmation_code: Unique identifier for the deletion request (usually user_id)
     * 
     * @param string $confirmationCode The confirmation code (usually Facebook user_id)
     */
    private function sendDeletionResponse(string $confirmationCode): void
    {
        // Set proper headers for JSON response
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        
        // Facebook expects a JSON response with url and confirmation_code
        // The url is where users can check deletion status (optional)
        $response = [
            'url' => home_url('/?facebook_deletion_status=1'),
            'confirmation_code' => $confirmationCode,
        ];

        echo wp_json_encode($response);
        exit;
    }
}