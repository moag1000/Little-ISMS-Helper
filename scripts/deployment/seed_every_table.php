<?php

declare(strict_types=1);

/**
 * Puts two rows into every table of a migrated database, so that the
 * migrations that follow run against data instead of an empty schema.
 *
 * Used by the CI job "Upgrade migrations with data": migrate to the last
 * release, seed, then migrate to HEAD. Data-dependent failures (a comparison
 * that strict mode turns into an error only when a row matches, a new unique
 * index, a NOT NULL column without default, a new foreign key over existing
 * values) only show up when rows exist — on an empty database the chain is
 * always green (issue #1171).
 *
 * Every column is filled, nullable ones too, so UPDATE … WHERE x IS NOT NULL
 * finds rows. Row 1 carries "empty" values ('' for strings, 0 for numbers),
 * row 2 ordinary ones — both occur in real installations. Primary keys are
 * 1 and 2 in every table, and a foreign key column takes the value its
 * referenced column has in the same row number, so every reference resolves.
 *
 * Usage: php seed_every_table.php 'mysql:host=127.0.0.1;dbname=x' user password
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php seed_every_table.php <pdo-dsn> <user> <password>\n");
    exit(2);
}

$pdo = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

$columns = [];
$stmt = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMN_KEY, EXTRA'
    . ' FROM information_schema.COLUMNS c'
    . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN'
    . "   (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE')"
    . ' ORDER BY TABLE_NAME, ORDINAL_POSITION'
);
$stmt->execute([$db, $db]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
}

$foreignKeys = [];
$stmt = $pdo->prepare(
    'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE'
    . ' WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
);
$stmt->execute([$db]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $foreignKeys[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [$row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']];
}

// MariaDB stores JSON as LONGTEXT with a json_valid() check.
$jsonColumns = [];
$stmt = $pdo->prepare('SELECT TABLE_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?');
$stmt->execute([$db]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (preg_match('/json_valid\(`([^`]+)`\)/i', (string) $row['CHECK_CLAUSE'], $m)) {
        $jsonColumns[$row['TABLE_NAME']][$m[1]] = true;
    }
}

$skip = ['doctrine_migration_versions', 'messenger_messages'];

/** @return int|float|string|null */
function seedValue(string $table, string $column, int $n, array $columns, array $foreignKeys, array $jsonColumns, int $depth = 0): mixed
{
    if ($depth < 5 && isset($foreignKeys[$table][$column])) {
        [$refTable, $refColumn] = $foreignKeys[$table][$column];
        if (isset($columns[$refTable][$refColumn])) {
            return seedValue($refTable, $refColumn, $n, $columns, $foreignKeys, $jsonColumns, $depth + 1);
        }
    }
    $c = $columns[$table][$column];
    $type = strtolower($c['DATA_TYPE']);
    $isKey = $c['COLUMN_KEY'] !== '' || isset($foreignKeys[$table][$column]);
    $empty = $n === 1 && !$isKey;

    if (isset($jsonColumns[$table][$column]) || $type === 'json') {
        return $empty ? '[]' : '{"seed":2}';
    }

    return match (true) {
        in_array($type, ['tinyint', 'bool', 'boolean', 'bit'], true) => $n === 1 ? 0 : 1,
        in_array($type, ['smallint', 'mediumint', 'int', 'integer', 'bigint'], true) => $isKey ? $n : ($empty ? 0 : $n),
        in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => $empty ? 0 : $n,
        $type === 'date' => '2026-01-0' . $n,
        in_array($type, ['datetime', 'timestamp'], true) => '2026-01-0' . $n . ' 08:00:00',
        $type === 'time' => '08:00:0' . $n,
        $type === 'year' => 2026,
        in_array($type, ['enum', 'set'], true) => enumValue($c['COLUMN_TYPE'], $n),
        in_array($type, ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true) => str_pad((string) $n, max(1, min(16, (int) ($c['CHARACTER_MAXIMUM_LENGTH'] ?? 16))), '0', STR_PAD_LEFT),
        default => stringValue($column, $n, $isKey, $empty, $c['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $c['CHARACTER_MAXIMUM_LENGTH'] : null),
    };
}

function enumValue(string $columnType, int $n): string
{
    preg_match_all("/'((?:[^']|'')*)'/", $columnType, $m);
    $values = $m[1] ?: [''];

    return str_replace("''", "'", $values[min($n - 1, count($values) - 1)]);
}

function stringValue(string $column, int $n, bool $isKey, bool $empty, ?int $max): string
{
    if ($empty) {
        return '';
    }
    $value = $isKey ? $column . '-' . $n : 'Seed ' . $n;
    if ($max !== null && strlen($value) > $max) {
        $value = substr((string) $n, 0, $max);
    }

    return $value;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$inserted = 0;
$failed = [];
foreach ($columns as $table => $tableColumns) {
    if (in_array($table, $skip, true)) {
        continue;
    }
    $names = [];
    foreach ($tableColumns as $name => $c) {
        if (!str_contains(strtolower((string) $c['EXTRA']), 'generated')) {
            $names[] = $name;
        }
    }
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn (string $n): string => '`' . $n . '`', $names)),
        implode(', ', array_fill(0, count($names), '?')),
    );
    $insert = $pdo->prepare($sql);
    foreach ([1, 2] as $n) {
        try {
            $insert->execute(array_map(
                static fn (string $name): mixed => seedValue($table, $name, $n, $columns, $foreignKeys, $jsonColumns),
                $names,
            ));
            ++$inserted;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), "'PRIMARY'")) {
                continue; // catalogue tables the migrations already filled
            }
            $failed[] = sprintf('%s row %d: %s', $table, $n, $e->getMessage());
        }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

printf("Seeded %d rows into %d tables.\n", $inserted, count($columns) - count($skip));
if ($failed !== []) {
    // A table the seeder cannot fill is a seeder gap, not a migration defect —
    // report it so it can be taught, but let the migration run decide the job.
    fwrite(STDERR, "Rows not inserted:\n  " . implode("\n  ", $failed) . "\n");
}
