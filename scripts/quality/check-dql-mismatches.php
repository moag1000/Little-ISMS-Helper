#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * DQL Field Mismatch Scanner
 *
 * Checks that DQL field references in Repository QueryBuilder queries
 * actually exist as properties on the corresponding Entity. Aliases bound
 * to a different entity via `getRepository(Other::class)->createQueryBuilder('a')`
 * are validated against that entity, not the repo's primary entity.
 *
 * Usage: php scripts/quality/check-dql-mismatches.php
 * Exit code: 0 = no mismatches, 1 = mismatches found
 */

$repoDir = __DIR__ . '/../../src/Repository/';
$entityDir = __DIR__ . '/../../src/Entity/';
$mismatches = [];

/**
 * @return list<string>
 */
$loadEntityProps = static function (string $entityFile): array {
    if (!file_exists($entityFile)) {
        return [];
    }
    $entityContent = file_get_contents($entityFile);
    preg_match_all('/(?:private|protected|public)\s+(?:\??\w+[\s|&\w]*\s+)?\$(\w+)/', $entityContent, $props);

    return array_values(array_unique($props[1]));
};

foreach (glob($repoDir . '*.php') as $repoFile) {
    $repoContent = file_get_contents($repoFile);
    $repoName = basename($repoFile, '.php');

    // Determine the repo's primary entity from its name.
    $primaryEntity = str_replace('Repository', '', $repoName);
    $primaryProps = $loadEntityProps($entityDir . $primaryEntity . '.php');
    if ($primaryProps === []) {
        continue;
    }

    // Build a per-alias entity map. Default: every alias maps to the primary
    // entity. Override entries where the QueryBuilder is created against a
    // different entity via getRepository(SomeEntity::class)->createQueryBuilder('a').
    $aliasEntity = [];

    // Find every createQueryBuilder('alias') and walk back ~80 chars to detect
    // a getRepository(EntityClass::class) call on the same chain.
    preg_match_all('/createQueryBuilder\(["\']([a-z]+)["\']\)/', $repoContent, $cqMatches, PREG_OFFSET_CAPTURE);
    for ($i = 0, $n = count($cqMatches[0]); $i < $n; $i++) {
        $alias = $cqMatches[1][$i][0];
        $offset = (int) $cqMatches[0][$i][1];
        $back = substr($repoContent, max(0, $offset - 200), min(200, $offset));
        if (preg_match('/getRepository\(([A-Z]\w+)::class\)\s*->\s*createQueryBuilder\(["\']' . preg_quote($alias, '/') . '["\']\)/', $back . substr($repoContent, $offset, 80), $depMatch)) {
            $aliasEntity[$alias] = $depMatch[1];
        } else {
            $aliasEntity[$alias] = $primaryEntity;
        }
    }

    if ($aliasEntity === []) {
        continue;
    }

    $entityPropsCache = [$primaryEntity => $primaryProps];

    foreach ($aliasEntity as $alias => $entityName) {
        // Find every field reference like alias.fieldName.
        preg_match_all('/\b' . preg_quote($alias, '/') . '\.([a-zA-Z_]+)\b/', $repoContent, $fields);
        $dqlFields = array_unique($fields[1]);

        if (!isset($entityPropsCache[$entityName])) {
            $entityPropsCache[$entityName] = $loadEntityProps($entityDir . $entityName . '.php');
        }
        $entityProps = $entityPropsCache[$entityName];
        if ($entityProps === []) {
            // Entity outside src/Entity/ (e.g. third-party) — skip.
            continue;
        }

        foreach ($dqlFields as $field) {
            if (in_array($field, ['id', 'class', 'php', 'qb'], true)) {
                continue;
            }
            if (!in_array($field, $entityProps, true)) {
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
