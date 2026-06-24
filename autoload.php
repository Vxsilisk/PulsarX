<?php

/**
 * PulsarX — zero-dependency autoloader.
 *
 * Use this when you are not installing via Composer:
 *
 *   require __DIR__ . '/autoload.php';
 *   $client = new Pulsar();
 *
 * @author  Vxsilisk — PulsarX
 * @license MIT
 */
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
