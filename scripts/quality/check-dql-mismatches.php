#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * DQL Field Mismatch Scanner
 *
 * Checks that all DQL field references in Repository QueryBuilder queries
 * actually exist as properties on the corresponding Entity.
 *
 * Usage: php scripts/quality/check-dql-mismatches.php
 * Exit code: 0 = no mismatches, 1 = mismatches found
 */

$repoDir = __DIR__ . '/../../src/Repository/';
$entityDir = __DIR__ . '/../../src/Entity/';
$mismatches = [];

foreach (glob($repoDir . '*.php') as $repoFile) {
    $repoContent = file_get_contents($repoFile);
    $repoName = basename($repoFile, '.php');

    // Find QueryBuilder aliases: createQueryBuilder('x')
    preg_match_all("/createQueryBuilder\(['\"]([a-z])['\"]\)/", $repoContent, $aliases);

    foreach (array_unique($aliases[1]) as $alias) {
        // Find all field references: x.fieldName (word boundary after dot)
        preg_match_all("/\\b{$alias}\\.([a-zA-Z_]+)\\b/", $repoContent, $fields);
        $dqlFields = array_unique($fields[1]);

        // Determine entity name from repo name
        $entityName = str_replace('Repository', '', $repoName);
        $entityFile = $entityDir . $entityName . '.php';

        if (!file_exists($entityFile)) {
            continue;
        }

        $entityContent = file_get_contents($entityFile);
        // Extract all property names (private/protected/public $name)
        preg_match_all('/(?:private|protected|public)\s+(?:\??\w+[\s|&\w]*\s+)?\$(\w+)/', $entityContent, $props);
        $entityProps = array_unique($props[1]);

        foreach ($dqlFields as $field) {
            // Skip meta-fields
            if (in_array($field, ['id', 'class', 'php', 'qb'], true)) {
                continue;
            }
            if (!in_array($field, $entityProps, true)) {
                // Find line number for context
                $lines = explode("\n", $repoContent);
                $lineNum = 0;
                foreach ($lines as $i => $line) {
                    if (str_contains($line, "{$alias}.{$field}")) {
                        $lineNum = $i + 1;
                        break;
                    }
                }
                $mismatches[] = [
                    'repo' => $repoName,
                    'entity' => $entityName,
                    'alias' => $alias,
                    'field' => $field,
                    'line' => $lineNum,
                    'file' => $repoFile,
                ];
            }
        }
    }
}

if (empty($mismatches)) {
    echo "\033[32m✅ No DQL field mismatches found\033[0m\n";
    exit(0);
}

echo "\033[31m❌ " . count($mismatches) . " DQL field mismatch(es) found:\033[0m\n\n";
foreach ($mismatches as $m) {
    echo "  \033[33m{$m['repo']}\033[0m line {$m['line']}: ";
    echo "\033[31m{$m['alias']}.{$m['field']}\033[0m does not exist on ";
    echo "\033[36m{$m['entity']}\033[0m entity\n";
}
echo "\n";
exit(1);
