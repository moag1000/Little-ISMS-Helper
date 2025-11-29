<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\PHPUnit90\Rector\Class_\TestListenerToHooksRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/src/Kernel.php',
    ])
    // PHP 8.4 features
    ->withPhpSets(php84: true)
    // Composer-based sets (replaces deprecated SymfonySetList and DoctrineSetList)
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true
    )
    ->withSets([
        // Code Quality
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        //SetList::NAMING,
        SetList::INSTANCEOF,
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withRules([
        TestListenerToHooksRector::class,
    ]);
