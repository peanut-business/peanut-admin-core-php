<?php
declare(strict_types=1);

/** Independent cold Composer loader; no host bootstrap, database, classmap, or preceding adapter load. */
$root = dirname(__DIR__, 2);
$loaderPath = $argv[1] ?? $root . '/vendor/composer/ClassLoader.php';
if (!is_file($loaderPath)) throw new RuntimeException('COMPOSER_CLASS_LOADER_REQUIRED');
require_once $loaderPath;
$manifest = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$loader = new Composer\Autoload\ClassLoader();
foreach ($manifest['autoload']['psr-4'] as $prefix => $path) $loader->setPsr4($prefix, [$root . '/' . $path]);
$loader->register(true);
$checks = 0;
$expect = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) throw new RuntimeException($message);
};
try {
    $name = 'PeanutAdmin\\Kernel\\Host\\TypedTargetAuthorization';
    $expect(!class_exists('PeanutAdmin\\Kernel\\Host\\TypedTargetAdapter', false), 'ADAPTER_MUST_NOT_BE_PRELOADED');
    $expect($loader->findFile($name) !== false, 'TYPED_TARGET_VALUE_NOT_INDEPENDENTLY_AUTOLOADABLE');
    $value = new $name(null, []);
    $expect($value->queryConstraint === null && $value->targets === [], 'VALUE_CONTRACT_CHANGED');
    $expect(realpath((new ReflectionClass($name))->getFileName()) === realpath($root . '/kernel/src/Host/TypedTargetAuthorization.php'), 'VALUE_PATH_MISMATCH');
    $expect(!class_exists('PeanutAdmin\\Kernel\\Host\\TypedTargetAdapter', false), 'VALUE_LOADING_MUST_NOT_LOAD_ADAPTER');
    $expect(class_exists('PeanutAdmin\\Kernel\\Host\\TypedTargetAdapter'), 'ADAPTER_AUTOLOAD_FAILED');
    $expect((string)(new ReflectionMethod('PeanutAdmin\\Kernel\\Host\\TypedTargetAdapter', 'authorize'))->getReturnType() === $name, 'ADAPTER_RESULT_CONTRACT_CHANGED');
    echo 'TYPED-TARGET-COLD-AUTOLOAD-001 checks=' . $checks . " passed\n";
} finally {
    $loader->unregister();
}
