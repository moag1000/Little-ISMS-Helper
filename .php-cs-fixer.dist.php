<?php

declare(strict_types=1);

/*
 * Code style (CLAUDE.md: PSR-12). CI runs `php-cs-fixer fix --dry-run` with this file and fails on
 * any file in src/ or tests/ that is not PSR-12 — except the files listed in
 * scripts/quality/baselines/php_cs_fixer.txt (style debt from before the check ran). Fix a listed
 * file with `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <file>` and delete its line.
 */

$baseline = array_values(array_filter(
    array_map('trim', (array) file(__DIR__ . '/scripts/quality/baselines/php_cs_fixer.txt')),
    static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
));

// Paths relative to the project root, as the baseline lists them.
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'var', 'public', 'assets', 'migrations', 'config', 'docs', 'translations', 'templates'])
    ->path(['#^src/#', '#^tests/#'])
    ->notPath($baseline)
    // Gate rule fixtures keep the spellings they test (e.g. `TRUE` as a default): they are mutations, not code.
    ->notPath('#^tests/PHPStan/Rule/Gate/Fixtures/#')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setUnsupportedPhpVersionAllowed(true)
;
