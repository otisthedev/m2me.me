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
    <h1><?php echo 'Account; ?></h1>

    <?php if (!is_user_logged_in()) : ?>
        <p><?php echo "You're not logged in."; ?></p>
        <p>
            <a class="mm-auth-link" data-auth="login" href="<?php echo esc_url(add_query_arg(['login' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                <?php echo 'Login; ?>
            </a>
            &nbsp;<?php echo 'or; ?>&nbsp;
            <a class="mm-auth-link" data-auth="register" href="<?php echo esc_url(add_query_arg(['register' => '1', 'redirect_to' => $redirectTo], home_url('/'))); ?>">
                <?php echo 'Register; ?>
            </a>
        </p>
    <?php else : ?>
        <?php $u = wp_get_current_user(); ?>
        <p><strong><?php echo 'Name:; ?></strong> <?php echo esc_html($u->display_name ?: $u->user_login); ?></p>
        <p><strong><?php echo 'Email:; ?></strong> <?php echo esc_html($u->user_email); ?></p>

        <p>
            <a href="<?php echo esc_url(wp_logout_url($redirectTo)); ?>"><?php echo 'Logout; ?></a>
        </p>
    <?php endif; ?>
</main>

<?php
get_footer();


