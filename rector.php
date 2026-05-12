<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Php84\Rector\FuncCall\AddEscapeArgumentRector;
use Rector\ValueObject\PhpVersion;
use Rector\Php83\Rector\BooleanAnd\JsonValidateRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php83\Rector\FuncCall\CombineHostPortLdapUriRector;
use Rector\Php83\Rector\FuncCall\DynamicClassConstFetchRector;
use Rector\Php83\Rector\FuncCall\RemoveGetClassGetParentClassNoArgsRector;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRules([
        CompleteDynamicPropertiesRector::class,
        DynamicClassConstFetchRector::class,
        CombineHostPortLdapUriRector::class,
        RemoveGetClassGetParentClassNoArgsRector::class,
        AddTypeToConstRector::class,
        JsonValidateRector::class,
        ReadOnlyAnonymousClassRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        ExplicitNullableParamTypeRector::class,
        AddEscapeArgumentRector::class
    ]);
