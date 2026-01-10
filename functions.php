<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

define('MATCH_ME_VERSION', '2.0');

// Use Composer autoloader if available, otherwise fall back to custom autoloader
$composerAutoload = get_template_directory() . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    require_once get_template_directory() . '/src/Wp/Autoloader.php';
    (new \MatchMe\Wp\Autoloader(get_template_directory() . '/src'))->register();
}

\MatchMe\Wp\Theme::bootstrap();

// Load theme text domain for translations
add_action('after_setup_theme', function() {
    load_theme_textdomain('match-me', get_template_directory() . '/languages');
});

