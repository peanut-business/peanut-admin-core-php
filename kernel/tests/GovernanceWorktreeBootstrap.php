<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PeanutAdmin\\Kernel\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $root . '/packages/php/kernel/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);
