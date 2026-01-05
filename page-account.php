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

<main class="mm-account container mm-page mm-page-720">
    <h1><?php echo esc_html__('Account', 'match-me'); ?></h1>

    <?php if (!is_user_logged_in()) : ?>
        <p><?php echo esc_html__("You're not logged in.", 'match-me'); ?></p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                <?php echo esc_html__('Login', 'match-me'); ?>
            </a>
            &nbsp;<?php echo esc_html__('or', 'match-me'); ?>&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                <?php echo esc_html__('Register', 'match-me'); ?>
            </a>
        </p>
    <?php else : ?>
        <?php $u = wp_get_current_user(); ?>
        <p><strong><?php echo esc_html__('Name:', 'match-me'); ?></strong> <?php echo esc_html($u->display_name ?: $u->user_login); ?></p>
        <p><strong><?php echo esc_html__('Email:', 'match-me'); ?></strong> <?php echo esc_html($u->user_email); ?></p>

        <p>
            <a href="<?php echo esc_url(wp_logout_url($redirectTo)); ?>"><?php echo esc_html__('Logout', 'match-me'); ?></a>
        </p>
    <?php endif; ?>
</main>

<?php
get_footer();


