<?php
/**
 * PSR-4 autoloader for the AI Provider for Anthropic Max package.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix  = 'AnthropicMaxAiProvider\\';
    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file           = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
