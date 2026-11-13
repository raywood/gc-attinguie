<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Do not load Dotenv, autoload_runtime.php or the unit bootstrap.
// Require explicit process configuration and reject conflicting PHP superglobals.
$environment = [];
foreach (['APP_ENV', 'DATABASE_URL', 'MESSENGER_TRANSPORT_DSN', 'TEST_TOKEN'] as $name) {
    $value = getenv($name);
    foreach ([$_ENV, $_SERVER] as $source) {
        if (array_key_exists($name, $source) && $source[$name] !== $value) {
            throw new LogicException('Conflicting integration configuration: ' . $name);
        }
    }
    $environment[$name] = $value;
}
\App\Tests\Support\TestEnvironmentGuard::assertSafe($environment);

foreach ($environment + ['APP_DEBUG' => '0', 'KERNEL_CLASS' => 'App\\Kernel'] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
date_default_timezone_set('Africa/Abidjan');
// No kernel boot or connection here. PostgreSQL identity checks are still required
// before any future migration, fixture write or cleanup.
