<?php
declare(strict_types=1);

namespace MatchMe\Wp\Auth;

use MatchMe\Config\ThemeConfig;

final class AuthMenu
{
    public function __construct(private ThemeConfig $config)
    {
    }

    public function register(): void
    {
        add_filter('wp_nav_menu_items', [$this, 'injectAuthItems'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderModal']);

        add_action('init', [$this, 'ensureAccountPageOnce']);
        add_action('init', [$this, 'ensureProfilePageOnce']);

        add_action('admin_post_nopriv_match_me_register', [$this, 'handleRegister']);
        add_action('admin_post_match_me_register', [$this, 'handleRegister']);
    }

    /**
     * @param string $items
     * @param \stdClass $args
     */
    public function injectAuthItems(string $items, $args): string
    {
        $location = is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '';
        if ($location !== 'primary') {
            return $items;
        }

        $current = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        $currentUrl = home_url($current);
        $redirectTo = wp_validate_redirect($currentUrl, home_url('/'));

        if (is_user_logged_in()) {
            $accountUrl = home_url('/profile/');
            $logoutUrl = wp_logout_url($redirectTo);

            $items .= $this->menuItem($accountUrl, 'My Profile', 'mm-menu-account', '');
            $items .= $this->menuItem($logoutUrl, 'Logout', 'mm-menu-logout', '');
            return $items;
        }

        // Logged out: links are also functional without JS (open home with query params).
        $loginUrl = add_query_arg(
            ['login' => '1', 'redirect_to' => $redirectTo],
            home_url('/')
        );
        $registerUrl = add_query_arg(
            ['register' => '1', 'redirect_to' => $redirectTo],
            home_url('/')
        );

        $items .= $this->menuItem($loginUrl, 'Login', 'mm-menu-login', 'login');
        $items .= $this->menuItem($registerUrl, 'Register', 'mm-menu-register', 'register');

        return $items;
    }

    private function menuItem(string $url, string $label, string $class, string $authMode): string
    {
        $data = $authMode !== '' ? ' data-auth="' . esc_attr($authMode) . '"' : '';
        $aClass = $authMode !== '' ? ' class="mm-auth-link"' : '';

        return sprintf(
            '<li class="menu-item %s"><a%s href="%s"%s>%s</a></li>',
            esc_attr($class),
            $aClass,
            esc_url($url),
            $data,
            esc_html($label)
        );
    }

    public function enqueueAssets(): void
    {
        $baseDir = (string) get_template_directory();
        $fallback = $this->config->themeVersion();

        $css = $baseDir . '/assets/css/auth-modal.css';
        $js = $baseDir . '/assets/js/auth-modal.js';

        $cssVer = is_file($css) ? (string) filemtime($css) : $fallback;
        $jsVer = is_file($js) ? (string) filemtime($js) : $fallback;

        wp_enqueue_style('match-me-auth-modal', get_template_directory_uri() . '/assets/css/auth-modal.css', [], $cssVer);
        wp_enqueue_script('match-me-auth-modal', get_template_directory_uri() . '/assets/js/auth-modal.js', [], $jsVer, true);

        $vars = [
            'homeUrl' => home_url('/'),
            'adminPostUrl' => admin_url('admin-post.php'),
        ];
        wp_add_inline_script('match-me-auth-modal', 'window.matchMeAuth=' . wp_json_encode($vars) . ';', 'before');
    }

    public function ensureAccountPageOnce(): void
    {
        $opt = get_option('match_me_account_page_id');
        if (is_numeric($opt) && (int) $opt > 0) {
            $p = get_post((int) $opt);
            if ($p instanceof \WP_Post && $p->post_status !== 'trash') {
                return;
            }
        }

        $slug = 'account';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option('match_me_account_page_id', (int) $existing->ID, true);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'Account',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-account.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-account.php');
        }

        update_option('match_me_account_page_id', (int) $pageId, true);
    }

    public function ensureProfilePageOnce(): void
    {
        $opt = get_option('match_me_profile_page_id');
        if (is_numeric($opt) && (int) $opt > 0) {
            $p = get_post((int) $opt);
            if ($p instanceof \WP_Post && $p->post_status !== 'trash') {
                return;
            }
        }

        $slug = 'profile';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option('match_me_profile_page_id', (int) $existing->ID, true);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'My Profile',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-profile.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-profile.php');
        }

        update_option('match_me_profile_page_id', (int) $pageId, true);
    }

    public function renderModal(): void
    {
        $home = home_url('/');
        $adminPost = admin_url('admin-post.php');
        ?>
        <div id="mm-auth-modal" class="mm-auth-modal" aria-hidden="true" style="display:none;">
            <div class="mm-auth-overlay" data-mm-auth-close></div>
            <div class="mm-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="mm-auth-title">
                <button type="button" class="mm-auth-close" data-mm-auth-close aria-label="Close">×</button>

                <div class="mm-auth-header">
                    <div id="mm-auth-title" class="mm-auth-title">Welcome</div>
                    <div class="mm-auth-subtitle">Login or create an account to save and compare results.</div>
                </div>

                <div class="mm-auth-tabs" role="tablist" aria-label="Authentication">
                    <button type="button" class="mm-auth-tab is-active" role="tab" aria-selected="true" data-mm-auth-tab="login">Login</button>
                    <button type="button" class="mm-auth-tab" role="tab" aria-selected="false" data-mm-auth-tab="register">Register</button>
                </div>

                <div class="mm-auth-panels">
                    <section class="mm-auth-panel is-active" data-mm-auth-panel="login">
                        <div class="mm-auth-section-title">Continue with</div>
                        <div class="mm-auth-social">
                            <a class="mm-auth-social-btn" data-mm-auth-social="google" href="<?php echo esc_url(add_query_arg('google_auth', '1', $home)); ?>">Google</a>
                            <a class="mm-auth-social-btn" data-mm-auth-social="facebook" href="<?php echo esc_url(add_query_arg('facebook_auth', '1', $home)); ?>">Facebook</a>
                            <a class="mm-auth-social-btn" data-mm-auth-social="instagram" href="<?php echo esc_url(add_query_arg('instagram_auth', '1', $home)); ?>">Instagram</a>
                        </div>

                        <div class="mm-auth-divider"><span>or</span></div>

                        <form class="mm-auth-form" method="post" action="<?php echo esc_url(wp_login_url()); ?>">
                            <input type="hidden" name="redirect_to" value="" data-mm-auth-redirect>
                            <label class="mm-auth-field">
                                <span>Email or Username</span>
                                <input type="text" name="log" autocomplete="username" required>
                            </label>
                            <label class="mm-auth-field">
                                <span>Password</span>
                                <input type="password" name="pwd" autocomplete="current-password" required>
                            </label>
                            <label class="mm-auth-checkbox">
                                <input type="checkbox" name="rememberme" value="forever">
                                <span>Remember me</span>
                            </label>
                            <button type="submit" class="mm-auth-submit">Login</button>
                        </form>
                    </section>

                    <section class="mm-auth-panel" data-mm-auth-panel="register">
                        <div class="mm-auth-section-title">Continue with</div>
                        <div class="mm-auth-social">
                            <a class="mm-auth-social-btn" data-mm-auth-social="google" href="<?php echo esc_url(add_query_arg('google_auth', '1', $home)); ?>">Google</a>
                            <a class="mm-auth-social-btn" data-mm-auth-social="facebook" href="<?php echo esc_url(add_query_arg('facebook_auth', '1', $home)); ?>">Facebook</a>
                            <a class="mm-auth-social-btn" data-mm-auth-social="instagram" href="<?php echo esc_url(add_query_arg('instagram_auth', '1', $home)); ?>">Instagram</a>
                        </div>

                        <div class="mm-auth-divider"><span>or</span></div>

                        <form class="mm-auth-form" method="post" action="<?php echo esc_url($adminPost); ?>">
                            <input type="hidden" name="action" value="match_me_register">
                            <?php wp_nonce_field('match_me_register', 'match_me_register_nonce'); ?>
                            <input type="hidden" name="redirect_to" value="" data-mm-auth-redirect>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label class="mm-auth-field">
                                    <span>First name</span>
                                    <input type="text" name="first_name" autocomplete="given-name">
                                </label>
                                <label class="mm-auth-field">
                                    <span>Last name</span>
                                    <input type="text" name="last_name" autocomplete="family-name">
                                </label>
                            </div>
                            <label class="mm-auth-field">
                                <span>Email</span>
                                <input type="email" name="email" autocomplete="email" required>
                            </label>
                            <label class="mm-auth-field">
                                <span>Password</span>
                                <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                            </label>
                            <button type="submit" class="mm-auth-submit">Create account</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    public function handleRegister(): void
    {
        if (is_user_logged_in()) {
            wp_redirect(home_url('/account/'));
            exit;
        }

        $nonce = isset($_POST['match_me_register_nonce']) ? (string) $_POST['match_me_register_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'match_me_register')) {
            wp_die('Invalid request.');
        }

        $email = isset($_POST['email']) ? sanitize_email((string) $_POST['email']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $firstName = isset($_POST['first_name']) ? sanitize_text_field((string) $_POST['first_name']) : '';
        $lastName = isset($_POST['last_name']) ? sanitize_text_field((string) $_POST['last_name']) : '';
        $redirectTo = isset($_POST['redirect_to']) ? (string) $_POST['redirect_to'] : '';
        $redirectTo = wp_validate_redirect($redirectTo, home_url('/'));

        if (!is_email($email)) {
            wp_redirect(add_query_arg(['register' => '1', 'error' => 'invalid_email', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }
        if (strlen($password) < 8) {
            wp_redirect(add_query_arg(['register' => '1', 'error' => 'weak_password', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }
        if (email_exists($email)) {
            wp_redirect(add_query_arg(['login' => '1', 'error' => 'email_exists', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }

        $base = sanitize_user((string) strtok($email, '@'), true);
        $username = $base !== '' ? $base : 'user';
        $i = 1;
        while (username_exists($username)) {
            $username = ($base !== '' ? $base : 'user') . $i;
            $i++;
        }

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim(($firstName . ' ' . $lastName)) !== '' ? trim(($firstName . ' ' . $lastName)) : $username,
            'role' => get_option('default_role'),
        ]);

        if (is_wp_error($userId)) {
            wp_redirect(add_query_arg(['register' => '1', 'error' => 'register_failed', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }

        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true);

        wp_redirect($redirectTo);
        exit;
    }
}


