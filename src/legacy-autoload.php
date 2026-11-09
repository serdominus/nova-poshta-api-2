<?php

use Serdominus\Delivery\NovaPoshtaApi2;

// Composer can resolve the legacy name before the client has been loaded.
spl_autoload_register(static function (string $class): void {
    if (strcasecmp($class, 'LisDev\Delivery\NovaPoshtaApi2') === 0) {
        class_exists(NovaPoshtaApi2::class);
    }
});
