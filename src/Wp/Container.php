<?php
declare(strict_types=1);

namespace MatchMe\Wp;

use MatchMe\Config\ThemeConfig;

final class Container
{
    private static ?self $instance = null;

    private function __construct(
        private ThemeConfig $config,
        private \wpdb $wpdb,
    ) {
    }

    public static function init(ThemeConfig $config, \wpdb $wpdb): void
    {
        self::$instance = new self($config, $wpdb);
    }

    public static function config(): ThemeConfig
    {
        if (!self::$instance) {
            throw new \RuntimeException('MatchMe Container not initialized.');
        }
        return self::$instance->config;
    }

    public static function wpdb(): \wpdb
    {
        if (!self::$instance) {
            throw new \RuntimeException('MatchMe Container not initialized.');
        }
        return self::$instance->wpdb;
    }
}



