<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__).'/vendor/autoload.php',
    dirname(__DIR__, 3).'/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require $autoloader;

        return;
    }
}

fwrite(STDERR, "FAIL: Composer autoloader not found. Run composer install first.\n");
exit(1);
