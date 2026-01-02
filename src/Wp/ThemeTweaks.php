<?php
declare(strict_types=1);

namespace MatchMe\Wp;

final class ThemeTweaks
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'restrictSubscriberDashboard']);
        add_action('after_setup_theme', [$this, 'hideAdminBarForSubscribers']);
        add_action('wp_enqueue_scripts', [$this, 'hideHeaderButtonForLoggedInUsers']);
    }

    public function restrictSubscriberDashboard(): void
    {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        $currentUser = wp_get_current_user();
        if (!in_array('subscriber', (array) $currentUser->roles, true)) {
            return;
        }

        if (isset($_GET['action'])) {
            return;
        }

        if (defined('IS_PROFILE_PAGE') && IS_PROFILE_PAGE) {
            return;
        }

        wp_redirect(home_url('/'));
        exit;
    }

    public function hideAdminBarForSubscribers(): void
    {
        if (current_user_can('subscriber')) {
            show_admin_bar(false);
        }
    }

    public function hideHeaderButtonForLoggedInUsers(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $script = "document.addEventListener('DOMContentLoaded',function(){var b=document.querySelector('header .ast-custom-button-link');if(b){b.style.display='none';}});";
        wp_add_inline_script('wp-hooks', $script);
    }
}



