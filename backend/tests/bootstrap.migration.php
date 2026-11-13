<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Dedicated entry: never imports the runner bootstrap or real Dotenv files.
$environment = [];
foreach (['GC_TEST_PROFILE', 'APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'MESSENGER_TRANSPORT_DSN', 'TEST_TOKEN'] as $name) {
    $value = getenv($name);
    foreach ([$_ENV, $_SERVER] as $source) {
        if (array_key_exists($name, $source) && $source[$name] !== $value) {
            throw new LogicException('Conflicting migration configuration: ' . $name);
        }
    }
    $environment[$name] = $value;
}
\App\Tests\Support\MigrationProfile::assertSafe($environment);
foreach ($environment as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
}
date_default_timezone_set('Africa/Abidjan');
