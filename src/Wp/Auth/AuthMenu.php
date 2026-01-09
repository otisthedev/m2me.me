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
        add_action('init', [$this, 'ensureResultsPageOnce']);
        add_action('init', [$this, 'ensureComparisonsPageOnce']);
        add_action('init', [$this, 'ensureMatchesPageOnce']);

        add_action('admin_post_nopriv_match_me_register', [$this, 'handleRegister']);
        add_action('admin_post_match_me_register', [$this, 'handleRegister']);

        add_action('admin_post_match_me_profile_update', [$this, 'handleProfileUpdate']);

        add_action('admin_post_match_me_delete_account', [$this, 'handleDeleteAccount']);
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
            $comparisonsUrl = home_url('/comparisons/');
            $matchesUrl = home_url('/matches/');
            $resultsUrl = home_url('/results/');
            $accountUrl = home_url('/profile/');
            $logoutUrl = wp_logout_url($redirectTo);

            $items .= $this->menuItem($matchesUrl, 'Matches', 'mm-menu-matches', '');
            $items .= $this->menuItem($comparisonsUrl, 'Comparisons', 'mm-menu-comparisons', '');
            $items .= $this->menuItem($resultsUrl, 'Results', 'mm-menu-results', '');
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

    public function ensureResultsPageOnce(): void
    {
        $opt = get_option('match_me_results_page_id');
        if (is_numeric($opt) && (int) $opt > 0) {
            $p = get_post((int) $opt);
            if ($p instanceof \WP_Post && $p->post_status !== 'trash') {
                return;
            }
        }

        $slug = 'results';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option('match_me_results_page_id', (int) $existing->ID, true);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'My Results',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-results.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-results.php');
        }

        update_option('match_me_results_page_id', (int) $pageId, true);
    }

    public function ensureComparisonsPageOnce(): void
    {
        $opt = get_option('match_me_comparisons_page_id');
        if (is_numeric($opt) && (int) $opt > 0) {
            $p = get_post((int) $opt);
            if ($p instanceof \WP_Post && $p->post_status !== 'trash') {
                return;
            }
        }

        $slug = 'comparisons';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option('match_me_comparisons_page_id', (int) $existing->ID, true);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'Comparisons',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-comparisons.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-comparisons.php');
        }

        update_option('match_me_comparisons_page_id', (int) $pageId, true);
    }

    public function ensureMatchesPageOnce(): void
    {
        $opt = get_option('match_me_matches_page_id');
        if (is_numeric($opt) && (int) $opt > 0) {
            $p = get_post((int) $opt);
            if ($p instanceof \WP_Post && $p->post_status !== 'trash') {
                return;
            }
        }

        $slug = 'matches';
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            update_option('match_me_matches_page_id', (int) $existing->ID, true);
            return;
        }

        $pageId = wp_insert_post([
            'post_title' => 'Matches',
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId)) {
            return;
        }

        $templateFile = (string) get_template_directory() . '/page-matches.php';
        if (is_file($templateFile)) {
            update_post_meta((int) $pageId, '_wp_page_template', 'page-matches.php');
        }

        update_option('match_me_matches_page_id', (int) $pageId, true);
    }

    public function renderModal(): void
    {
        $home = home_url('/');
        $adminPost = admin_url('admin-post.php');
        $lostPasswordUrl = wp_lostpassword_url($home);

        $socialProviders = $this->getEnabledSocialProviders();
        ?>
        <div id="mm-auth-modal" class="mm-auth-modal" aria-hidden="true" style="display:none;">
            <div class="mm-auth-overlay" data-mm-auth-close></div>
            <div class="mm-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="mm-auth-title">
                <button type="button" class="mm-auth-close" data-mm-auth-close aria-label="Close">×</button>

                <div class="mm-auth-header">
                    <div id="mm-auth-title" class="mm-auth-title">Welcome</div>
                    <div class="mm-auth-subtitle">Login or create an account to save and compare results.</div>
                </div>

                <div class="mm-auth-alert" data-mm-auth-alert style="display:none;" role="status" aria-live="polite"></div>

                <div class="mm-auth-body">
                    <div class="mm-auth-panels">
                        <section class="mm-auth-panel is-active" data-mm-auth-panel="login">
                            <?php if ($socialProviders !== []) : ?>
                                <div class="mm-auth-social-block" aria-label="Social login">
                                    <div class="mm-auth-section-title">Continue with</div>
                                    <div class="mm-auth-social">
                                        <?php $this->renderSocialButtons($home, $socialProviders); ?>
                                    </div>
                                </div>
                                <div class="mm-auth-divider" aria-hidden="true"><span>or continue with email</span></div>
                            <?php endif; ?>

                            <form class="mm-auth-form" method="post" action="<?php echo esc_url(wp_login_url()); ?>" novalidate data-mm-auth-form="login">
                                <input type="hidden" name="redirect_to" value="" data-mm-auth-redirect>
                                <label class="mm-auth-field">
                                    <span>Email or Username</span>
                                    <input type="text" name="log" autocomplete="username" inputmode="email" required data-mm-auth-field="login_id">
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="login_id" aria-live="polite"></small>
                                </label>
                                <label class="mm-auth-field">
                                    <span>Password</span>
                                    <span class="mm-auth-password">
                                        <input type="password" name="pwd" autocomplete="current-password" required data-mm-auth-field="login_password">
                                        <button type="button" class="mm-auth-eye" data-mm-auth-toggle="login_password" aria-label="Show password" aria-pressed="false">Show</button>
                                    </span>
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="login_password" aria-live="polite"></small>
                                </label>
                                <div class="mm-auth-row">
                                    <label class="mm-auth-checkbox">
                                        <input type="checkbox" name="rememberme" value="forever">
                                        <span>Remember me</span>
                                    </label>
                                    <a class="mm-auth-link-subtle" href="<?php echo esc_url($lostPasswordUrl); ?>">Forgot password?</a>
                                </div>
                                <button type="submit" class="mm-auth-submit" data-mm-auth-submit>Login</button>
                                <div class="mm-auth-switch-row">
                                    <button type="button" class="mm-auth-switch" data-mm-auth-switch="register">Don’t have an account? Register</button>
                                </div>
                            </form>
                        </section>

                        <section class="mm-auth-panel" data-mm-auth-panel="register">
                            <?php if ($socialProviders !== []) : ?>
                                <div class="mm-auth-social-block" aria-label="Social signup">
                                    <div class="mm-auth-section-title">Continue with</div>
                                    <div class="mm-auth-social">
                                        <?php $this->renderSocialButtons($home, $socialProviders); ?>
                                    </div>
                                </div>
                                <div class="mm-auth-divider" aria-hidden="true"><span>or create an account with email</span></div>
                            <?php endif; ?>

                            <form class="mm-auth-form" method="post" action="<?php echo esc_url($adminPost); ?>" novalidate data-mm-auth-form="register">
                                <input type="hidden" name="action" value="match_me_register">
                                <?php wp_nonce_field('match_me_register', 'match_me_register_nonce'); ?>
                                <input type="hidden" name="redirect_to" value="" data-mm-auth-redirect>
                                <label class="mm-auth-field">
                                    <span>Name (optional)</span>
                                    <input type="text" name="name" autocomplete="name" data-mm-auth-field="register_name">
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="register_name" aria-live="polite"></small>
                                </label>
                                <label class="mm-auth-field">
                                    <span>Email</span>
                                    <input type="email" name="email" autocomplete="email" inputmode="email" required data-mm-auth-field="register_email">
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="register_email" aria-live="polite"></small>
                                </label>
                                <label class="mm-auth-field">
                                    <span>Password</span>
                                    <span class="mm-auth-password">
                                        <input type="password" name="password" autocomplete="new-password" minlength="8" required data-mm-auth-field="register_password">
                                        <button type="button" class="mm-auth-eye" data-mm-auth-toggle="register_password" aria-label="Show password" aria-pressed="false">Show</button>
                                    </span>
                                    <small class="mm-auth-hint">Use at least 8 characters.</small>
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="register_password" aria-live="polite"></small>
                                </label>
                                <label class="mm-auth-field">
                                    <span>Confirm password</span>
                                    <span class="mm-auth-password">
                                        <input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required data-mm-auth-field="register_password_confirm">
                                        <button type="button" class="mm-auth-eye" data-mm-auth-toggle="register_password_confirm" aria-label="Show password" aria-pressed="false">Show</button>
                                    </span>
                                    <small class="mm-auth-inline-error" data-mm-auth-error-for="register_password_confirm" aria-live="polite"></small>
                                </label>
                                <button type="submit" class="mm-auth-submit" data-mm-auth-submit>Create account</button>
                                <div class="mm-auth-fineprint">By continuing, you agree to our Terms and Privacy Policy.</div>
                                <div class="mm-auth-switch-row">
                                    <button type="button" class="mm-auth-switch" data-mm-auth-switch="login">Already have an account? Login</button>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<int,string> List of enabled providers (google/facebook/instagram)
     */
    private function getEnabledSocialProviders(): array
    {
        $providers = [];

        $googleOk = (bool) ($this->config->googleClientId() && $this->config->googleClientSecret());
        $facebookOk = (bool) ($this->config->facebookAppId() && $this->config->facebookAppSecret());
        $instagramOk = (bool) ($this->config->instagramAppId() && $this->config->instagramAppSecret());

        if ($googleOk) $providers[] = 'google';
        if ($facebookOk) $providers[] = 'facebook';
        if ($instagramOk) $providers[] = 'instagram';

        return $providers;
    }

    /**
     * @param array<int,string> $providers
     */
    private function renderSocialButtons(string $home, array $providers): void
    {
        foreach ($providers as $p) {
            if ($p === 'google') : ?>
                <a class="mm-auth-social-btn" data-mm-auth-social="google" href="<?php echo esc_url(add_query_arg('google_auth', '1', $home)); ?>">
                    <span class="mm-auth-social-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M21.805 12.23c0-.63-.057-1.232-.163-1.81H12v3.426h5.504a4.71 4.71 0 0 1-2.04 3.09v2.22h3.3c1.93-1.776 3.04-4.39 3.04-7.926Z" fill="currentColor"/>
                            <path d="M12 22c2.7 0 4.965-.896 6.62-2.424l-3.3-2.22c-.916.614-2.09.977-3.32.977-2.604 0-4.81-1.76-5.595-4.127H2.99v2.29A10 10 0 0 0 12 22Z" fill="currentColor" opacity="0.75"/>
                            <path d="M6.405 12.206A5.995 5.995 0 0 1 6.09 10.5c0-.593.106-1.17.315-1.706V6.504H2.99A10 10 0 0 0 2 10.5c0 1.61.386 3.13.99 4.496l3.415-2.29Z" fill="currentColor" opacity="0.6"/>
                            <path d="M12 4.667c1.47 0 2.79.506 3.83 1.498l2.87-2.87C16.96 1.668 14.7 1 12 1A10 10 0 0 0 2.99 6.504l3.415 2.29C7.19 6.427 9.396 4.667 12 4.667Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span class="mm-auth-social-text">Continue with Google</span>
                </a>
            <?php
            elseif ($p === 'facebook') : ?>
                <a class="mm-auth-social-btn" data-mm-auth-social="facebook" href="<?php echo esc_url(add_query_arg('facebook_auth', '1', $home)); ?>">
                    <span class="mm-auth-social-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M13.5 21v-8.2h2.76l.41-3.2H13.5V7.56c0-.93.26-1.56 1.6-1.56h1.7V3.14c-.3-.04-1.34-.14-2.55-.14-2.53 0-4.26 1.55-4.26 4.4V9.6H7.5v3.2H10v8.2h3.5Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span class="mm-auth-social-text">Continue with Facebook</span>
                </a>
            <?php
            elseif ($p === 'instagram') : ?>
                <a class="mm-auth-social-btn" data-mm-auth-social="instagram" href="<?php echo esc_url(add_query_arg('instagram_auth', '1', $home)); ?>">
                    <span class="mm-auth-social-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9A5.5 5.5 0 0 1 16.5 22h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm0 2A3.5 3.5 0 0 0 4 7.5v9A3.5 3.5 0 0 0 7.5 20h9a3.5 3.5 0 0 0 3.5-3.5v-9A3.5 3.5 0 0 0 16.5 4h-9Z" fill="currentColor"/>
                            <path d="M12 7.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5Zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5Z" fill="currentColor" opacity="0.8"/>
                            <path d="M17.6 6.4a1 1 0 1 1-1-1 1 1 0 0 1 1 1Z" fill="currentColor" opacity="0.7"/>
                        </svg>
                    </span>
                    <span class="mm-auth-social-text">Continue with Instagram</span>
                </a>
            <?php
            endif;
        }
    }

    public function handleRegister(): void
    {
        if (is_user_logged_in()) {
            wp_safe_redirect(home_url('/account/'));
            exit;
        }

        $nonce = isset($_POST['match_me_register_nonce']) ? (string) $_POST['match_me_register_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'match_me_register')) {
            wp_die('Invalid request.');
        }

        $email = isset($_POST['email']) ? sanitize_email((string) $_POST['email']) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';
        $name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';

        // Backward-compat: some old forms may still send first/last.
        $firstName = isset($_POST['first_name']) ? sanitize_text_field((string) $_POST['first_name']) : '';
        $lastName = isset($_POST['last_name']) ? sanitize_text_field((string) $_POST['last_name']) : '';
        $redirectTo = isset($_POST['redirect_to']) ? (string) $_POST['redirect_to'] : '';
        $redirectTo = wp_validate_redirect($redirectTo, home_url('/'));

        if ($firstName === '' && $lastName === '' && $name !== '') {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $firstName = (string) ($parts[0] ?? '');
            $lastName = (string) (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '');
        }

        if (!is_email($email)) {
            wp_safe_redirect(add_query_arg(['register' => '1', 'error' => 'invalid_email', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }
        if (strlen($password) < 8) {
            wp_safe_redirect(add_query_arg(['register' => '1', 'error' => 'weak_password', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }
        if ($passwordConfirm !== '' && $passwordConfirm !== $password) {
            wp_safe_redirect(add_query_arg(['register' => '1', 'error' => 'password_mismatch', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }
        if (email_exists($email)) {
            wp_safe_redirect(add_query_arg(['login' => '1', 'error' => 'email_exists', 'redirect_to' => $redirectTo], home_url('/')));
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
            wp_safe_redirect(add_query_arg(['register' => '1', 'error' => 'register_failed', 'redirect_to' => $redirectTo], home_url('/')));
            exit;
        }

        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true);

        wp_safe_redirect($redirectTo);
        exit;
    }

    public function handleProfileUpdate(): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(add_query_arg(['login' => '1', 'redirect_to' => home_url('/profile/')], home_url('/')));
            exit;
        }

        $nonce = isset($_POST['match_me_profile_nonce']) ? (string) $_POST['match_me_profile_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'match_me_profile_update')) {
            wp_die('Invalid request.');
        }

        $userId = (int) get_current_user_id();

        $firstName = isset($_POST['first_name']) ? sanitize_text_field((string) $_POST['first_name']) : '';
        $lastName = isset($_POST['last_name']) ? sanitize_text_field((string) $_POST['last_name']) : '';
        $imageUrl = isset($_POST['profile_picture_url']) ? esc_url_raw((string) $_POST['profile_picture_url']) : '';
        $emailNotify = isset($_POST['email_compare_notify']) ? 'on' : 'off';

        // Optional file upload
        $uploadedUrl = '';
        if (!empty($_FILES['profile_picture_file']) && is_array($_FILES['profile_picture_file']) && !empty($_FILES['profile_picture_file']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $file = $_FILES['profile_picture_file'];
            $overrides = ['test_form' => false];
            $movefile = wp_handle_upload($file, $overrides);

            if (is_array($movefile) && !isset($movefile['error']) && isset($movefile['file'], $movefile['url'])) {
                $uploadedUrl = (string) $movefile['url'];

                // Insert as attachment (optional but useful)
                $attachmentId = wp_insert_attachment([
                    'post_mime_type' => (string) ($movefile['type'] ?? 'image/jpeg'),
                    'post_title' => 'Profile picture',
                    'post_status' => 'inherit',
                ], (string) $movefile['file']);

                if (!is_wp_error($attachmentId) && $attachmentId) {
                    $meta = wp_generate_attachment_metadata((int) $attachmentId, (string) $movefile['file']);
                    if (is_array($meta)) {
                        wp_update_attachment_metadata((int) $attachmentId, $meta);
                    }
                    update_user_meta($userId, 'profile_picture_attachment_id', (int) $attachmentId);
                }
            }
        }

        // Persist user fields
        update_user_meta($userId, 'first_name', $firstName);
        update_user_meta($userId, 'last_name', $lastName);
        update_user_meta($userId, 'match_me_email_compare_notify', $emailNotify);

        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName !== '') {
            wp_update_user(['ID' => $userId, 'display_name' => $displayName]);
        }

        // Picture precedence: upload > URL > keep existing
        if ($uploadedUrl !== '') {
            update_user_meta($userId, 'profile_picture', $uploadedUrl);
        } elseif ($imageUrl !== '') {
            update_user_meta($userId, 'profile_picture', $imageUrl);
        }

        wp_safe_redirect(add_query_arg(['updated' => '1'], home_url('/profile/')));
        exit;
    }

    public function handleDeleteAccount(): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(add_query_arg(['login' => '1', 'redirect_to' => home_url('/profile/')], home_url('/')));
            exit;
        }

        $nonce = isset($_POST['match_me_delete_account_nonce']) ? (string) $_POST['match_me_delete_account_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'match_me_delete_account')) {
            wp_die('Invalid request.');
        }

        $confirm = isset($_POST['confirm_delete']) ? (string) $_POST['confirm_delete'] : '';
        if ($confirm !== 'DELETE') {
            wp_die('Deletion not confirmed.');
        }

        $userId = (int) get_current_user_id();

        // Delete legacy quiz history (cq_quiz_results)
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $legacyTable = $wpdb->prefix . 'cq_quiz_results';
            $wpdb->query($wpdb->prepare("DELETE FROM {$legacyTable} WHERE user_id = %d", $userId));

            // Delete v2 results (match_me_results). Comparisons referencing these results will cascade.
            $resultsTable = $wpdb->prefix . 'match_me_results';
            $wpdb->query($wpdb->prepare("DELETE FROM {$resultsTable} WHERE user_id = %d", $userId));
        }

        // Delete uploaded profile picture attachment if we created one.
        $attachmentId = (int) get_user_meta($userId, 'profile_picture_attachment_id', true);
        if ($attachmentId > 0) {
            wp_delete_attachment($attachmentId, true);
        }

        // Delete the WP user (also removes user meta; deletes authored content when reassign is null).
        require_once ABSPATH . 'wp-admin/includes/user.php';

        // Log out first to avoid weird session issues during deletion.
        wp_logout();

        wp_delete_user($userId);

        wp_safe_redirect(add_query_arg(['account_deleted' => '1'], home_url('/')));
        exit;
    }
}


