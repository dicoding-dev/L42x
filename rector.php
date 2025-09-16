<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withRules([\Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector::class]);
