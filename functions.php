<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

define('MATCH_ME_VERSION', '1.0');

require_once get_template_directory() . '/src/Wp/Autoloader.php';

(new \MatchMe\Wp\Autoloader(get_template_directory() . '/src'))->register();
\MatchMe\Wp\Theme::bootstrap();

// Load theme text domain for translations
add_action('after_setup_theme', function() {
    load_theme_textdomain('match-me', get_template_directory() . '/languages');
});

