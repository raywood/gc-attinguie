<?php
declare(strict_types=1);

// Unit tests load neither Dotenv nor the Symfony kernel. Override inherited
// connection settings; the unsupported DB scheme fails closed if used by mistake.
foreach ([
    'APP_ENV' => 'test',
    'APP_DEBUG' => '0',
    'DATABASE_URL' => 'disabled://unit-tests',
    'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

date_default_timezone_set('Africa/Abidjan');

require dirname(__DIR__) . '/vendor/autoload.php';
