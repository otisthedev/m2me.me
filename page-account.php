<?php
/**
 * Template Name: Account
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

get_header();

$redirectTo = (string) wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
$redirectTo = wp_validate_redirect(home_url($redirectTo), home_url('/'));
?>

<main class="mm-account container" style="max-width: 720px;">
    <h1>Account</h1>

    <?php if (!is_user_logged_in()) : ?>
        <p>You’re not logged in.</p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                Login
            </a>
            &nbsp;or&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                Register
            </a>
        </p>
    <?php else : ?>
        <?php $u = wp_get_current_user(); ?>
        <p><strong>Name:</strong> <?php echo esc_html($u->display_name ?: $u->user_login); ?></p>
        <p><strong>Email:</strong> <?php echo esc_html($u->user_email); ?></p>

        <p>
            <a href="<?php echo esc_url(wp_logout_url($redirectTo)); ?>">Logout</a>
        </p>
    <?php endif; ?>
</main>

<?php
get_footer();


