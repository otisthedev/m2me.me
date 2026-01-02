<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

define('MATCH_ME_VERSION', '1.0');

require_once get_template_directory() . '/src/Wp/Autoloader.php';

(new \MatchMe\Wp\Autoloader(get_template_directory() . '/src'))->register();
\MatchMe\Wp\Theme::bootstrap();

