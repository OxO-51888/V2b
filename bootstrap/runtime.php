<?php

if (PHP_VERSION_ID < 80512 || PHP_VERSION_ID >= 80600) {
    throw new RuntimeException('This panel requires PHP 8.5.12 or a newer PHP 8.5 patch release.');
}

if (!extension_loaded('sodium')) {
    throw new RuntimeException('The native sodium extension is required.');
}
