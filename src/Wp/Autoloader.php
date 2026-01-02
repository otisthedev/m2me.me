<?php
declare(strict_types=1);

namespace MatchMe\Wp;

final class Autoloader
{
    /**
     * @param non-empty-string $baseDir Absolute path to the `src/` directory (no trailing slash required)
     */
    public function __construct(private string $baseDir)
    {
        $this->baseDir = rtrim($this->baseDir, '/\\');
    }

    public function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'MatchMe\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            $file = $this->baseDir . DIRECTORY_SEPARATOR . $relativePath;

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}



