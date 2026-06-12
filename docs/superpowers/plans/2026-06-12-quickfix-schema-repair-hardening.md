# Quick-Fix Schema-Repair Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 10 source-grounded safety gaps in the Quick-Fix schema-repair UI so its enforced behaviour matches the safety guarantees printed in its docblocks and operator UI.

**Architecture:** Surgical changes inside two existing services (`SchemaHealthService`, `SchemaMaintenanceService`), one controller (`QuickFixController`), one template (`quick_fix/index.html.twig`), the force job, plus one new focused service (`SchemaSnapshotService`). A single shared drift-classifier becomes the one source of truth for additive-vs-destructive partitioning. Every mutating path gains: pre-mutation snapshot, advisory lock, destructive-gate, and a final clean-verdict.

**Tech Stack:** Symfony 7.4, Doctrine ORM 3.6 / Migrations 4.0, DBAL 4.x (MySQL/MariaDB), PHPUnit 13.1 (`TestCase` with mocked Doctrine value-objects; `KernelTestCase` where a real EM is needed).

**Baseline note:** Service-layer tests (`SchemaMaintenanceService*`, `SchemaHealthService*`) are green in the worktree. The 7 `DataRepairControllerTest` failures are environment setup-redirect (`302 → /de/setup`) artifacts of the freshly schema-updated test DB, not code regressions; CI provides proper fixtures. Do not chase them — keep the service-layer suite green and rely on CI for functional coverage.

**Convention reminders:**
- `AuditLogger::logCustom(string $action, string $entityType, ?int $entityId, ?array $old, ?array $new, ?string $description, ?string $userName=null)`.
- New tests follow the existing `tests/Service/SchemaMaintenanceService*Test.php` pattern: plain `PHPUnit\Framework\TestCase`, mock the collaborators, use concrete (final) Doctrine value-objects.
- Each task ends with a commit. Commit format `feat(quick-fix): …` / `fix(quick-fix): …`.

---

## Task 1: Shared drift classifier (QF-8 foundation)

Single source of truth for "is this statement destructive?" and one drift computation reused by display, apply, and the future clean-verdict. Today `DESTRUCTIVE_PATTERNS` lives in `SchemaMaintenanceService` while the executing path in `SchemaHealthService` never consults it.

**Files:**
- Modify: `src/Service/SchemaHealthService.php`
- Test: `tests/Service/SchemaHealthServiceDriftClassifierTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SchemaHealthService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaHealthServiceDriftClassifierTest extends TestCase
{
    #[Test]
    public function classifiesAdditiveAndDestructiveStatements(): void
    {
        $sql = [
            'ALTER TABLE asset ADD COLUMN foo VARCHAR(255) DEFAULT NULL',
            'CREATE TABLE bar (id INT)',
            'ALTER TABLE asset DROP COLUMN legacy',
            'DROP TABLE obsolete',
            '-- ERROR: something',
        ];

        $result = SchemaHealthService::classifyStatements($sql);

        self::assertSame(
            [
                'ALTER TABLE asset ADD COLUMN foo VARCHAR(255) DEFAULT NULL',
                'CREATE TABLE bar (id INT)',
            ],
            $result['additive'],
        );
        self::assertSame(
            [
                'ALTER TABLE asset DROP COLUMN legacy',
                'DROP TABLE obsolete',
            ],
            $result['destructive'],
        );
        self::assertSame(['-- ERROR: something'], $result['errors']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceDriftClassifierTest.php`
Expected: FAIL — `Call to undefined method ...::classifyStatements()`

- [ ] **Step 3: Add the classifier to `SchemaHealthService`**

Add near the top of the class (after the constructor):

```php
    /** Patterns the SchemaTool emits when it would drop tables/columns/constraints. */
    public const DESTRUCTIVE_PATTERNS = [
        '/^DROP TABLE/i',
        '/^ALTER TABLE .+ DROP /i',
    ];

    /**
     * Partitions a SchemaTool/SchemaValidator SQL list into additive,
     * destructive and error-marker buckets. Single source of truth so the
     * display, the apply-gate and the clean-verdict all agree.
     *
     * @param list<string> $sql
     * @return array{additive: list<string>, destructive: list<string>, errors: list<string>}
     */
    public static function classifyStatements(array $sql): array
    {
        $additive = [];
        $destructive = [];
        $errors = [];
        foreach ($sql as $statement) {
            if (str_starts_with($statement, '-- ERROR:')) {
                $errors[] = $statement;
                continue;
            }
            $isDestructive = false;
            foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $isDestructive = true;
                    break;
                }
            }
            if ($isDestructive) {
                $destructive[] = $statement;
            } else {
                $additive[] = $statement;
            }
        }

        return ['additive' => $additive, 'destructive' => $destructive, 'errors' => $errors];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceDriftClassifierTest.php`
Expected: PASS

- [ ] **Step 5: Point `SchemaMaintenanceService` at the shared classifier**

In `src/Service/SchemaMaintenanceService.php`, delete the private `DESTRUCTIVE_PATTERNS` const (lines 32-36) and replace the two local destructive loops.

In `getMaintenanceStatus()` replace the `$destructive = array_values(array_filter(...))` block (around `:76-86`) with:

```php
        $destructive = SchemaHealthService::classifyStatements($statements)['destructive'];
```

In `getEntityVsDbDrift()` replace the whole `array_filter` body (around `:347-360`) with:

```php
        return SchemaHealthService::classifyStatements($validation['pending_sql'])['additive'];
```

- [ ] **Step 6: Run the affected service suites**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceForceMarkTest.php tests/Service/SchemaMaintenanceServiceForceSchemaUpdateTest.php tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php tests/Service/SchemaHealthServiceDriftClassifierTest.php`
Expected: PASS (all)

- [ ] **Step 7: Commit**

```bash
git add src/Service/SchemaHealthService.php src/Service/SchemaMaintenanceService.php tests/Service/SchemaHealthServiceDriftClassifierTest.php
git commit -m "refactor(quick-fix): single drift classifier as source of truth (QF-8)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Destructive-DDL gate in applyUpdate (QF-1)

`applyUpdate()` executes whatever `SchemaTool::getUpdateSchemaSql()` returns — including `DROP` — despite docblocks promising "additive only / never drops". Default-filter destructive; execute only with explicit opt-in; return the skipped list.

**Files:**
- Modify: `src/Service/SchemaHealthService.php:87-246` (`applyUpdate`)
- Test: `tests/Service/SchemaHealthServiceDestructiveGateTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * applyUpdate() must NOT execute destructive statements unless allowDestructive
 * is set. We subclass to stub the SQL the tool would emit, so no live DB is hit.
 */
class SchemaHealthServiceDestructiveGateTest extends TestCase
{
    private function makeService(array $stubSql, array &$executed): SchemaHealthService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);
        $mf = $this->createMock(ClassMetadataFactory::class);
        $mf->method('getAllMetadata')->willReturn([]);
        $em->method('getMetadataFactory')->willReturn($mf);
        // capture every executeStatement call
        $conn->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$executed): int {
                $executed[] = $sql;
                return 0;
            },
        );
        $conn->method('fetchOne')->willReturn('0'); // FK checks already off

        $df = $this->createMock(DependencyFactory::class);
        $audit = $this->createMock(AuditLogger::class);

        return new class($em, $audit, $df, $stubSql) extends SchemaHealthService {
            /** @param list<string> $stub */
            public function __construct($em, $audit, $df, private array $stub)
            {
                parent::__construct($em, $audit, $df);
            }
            // Force a known statement list + no pending migrations gate.
            protected function pendingMigrationVersions(): array { return []; }
            protected function computeUpdateSql(): array { return $this->stub; }
        };
    }

    #[Test]
    public function skipsDestructiveByDefault(): void
    {
        $executed = [];
        $service = $this->makeService(
            ['ALTER TABLE a ADD COLUMN x INT', 'ALTER TABLE a DROP COLUMN y'],
            $executed,
        );

        $result = $service->applyUpdate('test', bypassMigrationGate: true);

        self::assertTrue($result['success']);
        self::assertContains('ALTER TABLE a ADD COLUMN x INT', $executed);
        self::assertNotContains('ALTER TABLE a DROP COLUMN y', $executed);
        self::assertSame(['ALTER TABLE a DROP COLUMN y'], $result['skipped_destructive']);
    }

    #[Test]
    public function executesDestructiveWhenAllowed(): void
    {
        $executed = [];
        $service = $this->makeService(
            ['ALTER TABLE a DROP COLUMN y'],
            $executed,
        );

        $result = $service->applyUpdate('test', bypassMigrationGate: true, allowDestructive: true);

        self::assertTrue($result['success']);
        self::assertContains('ALTER TABLE a DROP COLUMN y', $executed);
        self::assertSame([], $result['skipped_destructive']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceDestructiveGateTest.php`
Expected: FAIL — `applyUpdate()` has no `allowDestructive` param / no `skipped_destructive` key / `computeUpdateSql()` undefined.

- [ ] **Step 3: Refactor `applyUpdate` to add the gate + a seam**

In `src/Service/SchemaHealthService.php`:

1. Change the signature (`:87`):

```php
    public function applyUpdate(string $actor = 'system', bool $bypassMigrationGate = false, bool $allowDestructive = false): array
```

2. Extract the SQL computation into an overridable protected method. Replace lines `:116-118`:

```php
        $sql = $this->computeUpdateSql();
```

   and add the method (anywhere in the class):

```php
    /**
     * Seam over SchemaTool so tests can stub the emitted SQL. Production path
     * computes the live entity-vs-DB diff.
     *
     * @return list<string>
     */
    protected function computeUpdateSql(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->entityManager);

        return $tool->getUpdateSchemaSql($metadata);
    }
```

3. Make `pendingMigrationVersions()` overridable — change `private function pendingMigrationVersions` (`:484`) to `protected function pendingMigrationVersions`.

4. Right after the `$sql === []` early-return block (`:120-128`), partition and gate:

```php
        $classified = self::classifyStatements($sql);
        $skippedDestructive = [];
        if (!$allowDestructive && $classified['destructive'] !== []) {
            $skippedDestructive = $classified['destructive'];
            $sql = $classified['additive'];
            $this->auditLogger->logCustom(
                'admin.schema.update.destructive_skipped',
                'Doctrine',
                null,
                null,
                ['skipped_count' => count($skippedDestructive), 'skipped' => array_slice($skippedDestructive, 0, 10)],
                sprintf('Schema update for %s: %d destructive statement(s) withheld (allowDestructive=false)', $actor, count($skippedDestructive)),
            );
            if ($sql === []) {
                return [
                    'success' => true,
                    'executed_sql' => [],
                    'sql_hash' => null,
                    'error' => null,
                    'blocked' => null,
                    'dropped_fks' => [],
                    'skipped_destructive' => $skippedDestructive,
                ];
            }
        }
```

5. Add `'skipped_destructive' => $skippedDestructive,` to every remaining `return [...]` array in this method (the failure return `:202-209`, and the success return `:238-245`). For the failure path inside the catch, the variable is in scope.

6. Update the method's docblock return type (`:85`) to include `skipped_destructive: list<string>` and delete the false "additive-only / never drops" wording — replace with: "Executes the entity-vs-DB diff. Destructive statements (DROP) are withheld unless $allowDestructive is true."

   Also fix the **multi-pass** block: it re-diffs via `$tool->getUpdateSchemaSql(...)` (`:167-169`). Route it through the same gate so later passes don't smuggle drops:

```php
                $passClassified = self::classifyStatements($passSql);
                $passToRun = $allowDestructive ? $passSql : $passClassified['additive'];
                if (!$allowDestructive && $passClassified['destructive'] !== []) {
                    foreach ($passClassified['destructive'] as $d) {
                        if (!in_array($d, $skippedDestructive, true)) { $skippedDestructive[] = $d; }
                    }
                }
                $currentHash = hash('sha256', implode(";\n", $passToRun));
                if ($passToRun === [] || $currentHash === $previousPassHash) {
                    break;
                }
                $previousPassHash = $currentHash;
                foreach ($passToRun as $statement) {
                    $this->executeStatementFkAware($conn, $statement, $droppedFks);
                }
```

   (Replace the existing pass body `:170-182` with the above; keep the surrounding `for ($pass...)` and the `$tool->getUpdateSchemaSql` call producing `$passSql`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceDestructiveGateTest.php`
Expected: PASS (both cases)

- [ ] **Step 5: Keep existing callers compiling**

`reconcileSchema()` and `forceSchemaUpdate()` in `SchemaMaintenanceService` read `$result['executed_sql']` — unchanged keys, so they still work. Run their suites:

Run: `php bin/phpunit tests/Service/SchemaHealthServiceFkAwareTest.php tests/Service/SchemaMaintenanceServiceForceSchemaUpdateTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Service/SchemaHealthService.php tests/Service/SchemaHealthServiceDestructiveGateTest.php
git commit -m "fix(quick-fix): withhold destructive DDL in applyUpdate unless opted in (QF-1)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: FK re-add failure is fatal-loud (QF-4)

`executeStatementFkAware()` step 3 re-adds the dropped FK with no error handling. If re-add throws, the FK is permanently lost. Make it loud and fatal.

**Files:**
- Modify: `src/Service/SchemaHealthService.php:309-315` (step 3 re-add)
- Test: add a case to `tests/Service/SchemaHealthServiceFkAwareTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Service/SchemaHealthServiceFkAwareTest.php` (follow the file's existing mock setup; this case drives the re-add failure). If the existing file constructs the connection via a helper, reuse it; otherwise add:

```php
    #[Test]
    public function reAddFailureSurfacesLoudlyAndDoesNotSwallow(): void
    {
        // ALTER triggers errno 1832; drop succeeds; retry succeeds; re-add throws.
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $calls = [];
        $conn->method('executeStatement')->willReturnCallback(function (string $sql) use (&$calls): int {
            $calls[] = $sql;
            if (str_contains($sql, 'ALTER TABLE `child` MODIFY')) {
                if (count(array_filter($calls, fn($s) => str_contains($s, 'MODIFY'))) === 1) {
                    throw new class('SQLSTATE[HY000]: General error: 1833 Cannot change column \'pid\': used in a foreign key constraint \'fk_child_parent\'') extends \Doctrine\DBAL\Exception {};
                }
                return 0; // retry after FK drop succeeds
            }
            if (str_contains($sql, 'ADD CONSTRAINT')) {
                throw new \Doctrine\DBAL\Exception('re-add failed: referenced table gone');
            }
            return 0; // DROP FOREIGN KEY
        });
        $conn->method('fetchOne')->willReturn('child');
        $conn->method('fetchAllAssociative')->willReturn([
            ['COLUMN_NAME' => 'pid', 'REFERENCED_TABLE_NAME' => 'parent', 'REFERENCED_COLUMN_NAME' => 'id'],
        ]);
        $conn->method('fetchAssociative')->willReturn(['DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT']);

        $service = $this->makeServiceWithConnection($conn); // existing helper in this test file
        $dropped = [];

        $this->expectException(\Throwable::class);
        $this->invokeExecuteStatementFkAware($service, $conn, 'ALTER TABLE `child` MODIFY pid INT', $dropped);
    }
```

If `makeServiceWithConnection()` / `invokeExecuteStatementFkAware()` helpers do not yet exist in the file, add them:

```php
    private function makeServiceWithConnection(\Doctrine\DBAL\Connection $conn): SchemaHealthService
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        return new SchemaHealthService(
            $em,
            $this->createMock(\App\Service\AuditLogger::class),
            $this->createMock(\Doctrine\Migrations\DependencyFactory::class),
        );
    }

    private function invokeExecuteStatementFkAware(SchemaHealthService $s, \Doctrine\DBAL\Connection $conn, string $sql, array &$dropped): void
    {
        $ref = new \ReflectionMethod($s, 'executeStatementFkAware');
        $ref->invokeArgs($s, [$conn, $sql, &$dropped, 0]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceFkAwareTest.php --filter reAddFailure`
Expected: FAIL — no exception thrown (re-add failure currently silently bubbles raw, but the dropped FK metadata is recorded as success). Confirm the test currently does NOT get a clean fatal path with audit.

- [ ] **Step 3: Wrap step 3 in try-catch with loud audit**

Replace the step-3 re-add block (`:309-315`):

```php
                // Step 3: re-add the FK on its owner table — MUST succeed, else
                // we have silently dropped a referential-integrity constraint.
                try {
                    $conn->executeStatement(sprintf(
                        'ALTER TABLE `%s` ADD CONSTRAINT `%s` %s',
                        $fkOwnerTable,
                        $fkName,
                        $fkDef,
                    ));
                } catch (\Throwable $reAddError) {
                    $this->auditLogger->logCustom(
                        'admin.schema.fk_aware_readd_failed',
                        'Doctrine',
                        null,
                        null,
                        [
                            'table' => $fkOwnerTable,
                            'fk' => $fkName,
                            'readd_sql' => sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $fkOwnerTable, $fkName, $fkDef),
                            'error' => $reAddError->getMessage(),
                        ],
                        sprintf(
                            'CRITICAL: FK %s on %s was dropped to apply an ALTER but could NOT be recreated: %s. Manual re-add required.',
                            $fkName,
                            $fkOwnerTable,
                            $reAddError->getMessage(),
                        ),
                    );
                    throw new \App\Exception\Io\IoException(sprintf(
                        'FK-aware reconcile dropped %s.%s but failed to recreate it: %s — re-add SQL: ALTER TABLE `%s` ADD CONSTRAINT `%s` %s',
                        $fkOwnerTable,
                        $fkName,
                        $reAddError->getMessage(),
                        $fkOwnerTable,
                        $fkName,
                        $fkDef,
                    ), 0, $reAddError);
                }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceFkAwareTest.php`
Expected: PASS (new case + existing cases)

- [ ] **Step 5: Commit**

```bash
git add src/Service/SchemaHealthService.php tests/Service/SchemaHealthServiceFkAwareTest.php
git commit -m "fix(quick-fix): fail loud when a dropped FK cannot be recreated (QF-4)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: verify-before-mark (QF-3)

`markMigrationAsExecuted()` inserts into `doctrine_migration_versions` without confirming the migration's effect is actually present. Add a behaviour-based check: marking must not leave additive entity-vs-DB drift behind. If it would, roll the INSERT back and refuse.

**Files:**
- Modify: `src/Service/SchemaMaintenanceService.php:389-469` (`markMigrationAsExecuted`)
- Test: add cases to `tests/Service/SchemaMaintenanceServiceForceMarkTest.php`

- [ ] **Step 1: Write the failing test**

Append two cases (the file already wires `$this->schemaHealthService` as a mock):

```php
    #[Test]
    public function refusesMarkWhenAdditiveDriftRemains(): void
    {
        // Version is known + not yet executed.
        $this->primeKnownPendingVersion(self::VERSION_STRING);
        // After a hypothetical mark, additive drift still references the table
        // the migration was supposed to create → not phantom → refuse.
        $this->schemaHealthService->method('validate')->willReturn([
            'mapping_in_sync' => true,
            'database_in_sync' => false,
            'mapping_errors' => [],
            'pending_sql' => ['ALTER TABLE widget ADD COLUMN gadget INT'],
            'pending_migrations' => [],
            'overall_status' => 'warning',
        ]);
        // The connection INSERT must be followed by a DELETE rollback.
        $ops = [];
        $this->connection->method('insert')->willReturnCallback(function (...$a) use (&$ops) { $ops[] = 'insert'; return 1; });
        $this->connection->method('delete')->willReturnCallback(function (...$a) use (&$ops) { $ops[] = 'delete'; return 1; });

        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        self::assertFalse($result['success']);
        self::assertStringContainsString('drift', strtolower((string) $result['error']));
        self::assertSame(['insert', 'delete'], $ops, 'INSERT must be rolled back when drift remains');
    }

    #[Test]
    public function marksWhenNoAdditiveDriftRemains(): void
    {
        $this->primeKnownPendingVersion(self::VERSION_STRING);
        $this->schemaHealthService->method('validate')->willReturn([
            'mapping_in_sync' => true,
            'database_in_sync' => true,
            'mapping_errors' => [],
            'pending_sql' => [],
            'pending_migrations' => [],
            'overall_status' => 'healthy',
        ]);
        $inserted = false;
        $this->connection->method('insert')->willReturnCallback(function (...$a) use (&$inserted) { $inserted = true; return 1; });
        $this->connection->expects(self::never())->method('delete');

        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        self::assertTrue($result['success']);
        self::assertTrue($inserted);
    }
```

Add the helper `primeKnownPendingVersion()` to the test class if not present — it should make `getMigrationRepository()->getMigrations()` return a set containing the version and `getMetadataStorage()->getExecutedMigrations()` return an empty list (mirror the existing happy-path setUp in this file; extract it into this helper so both new cases reuse it).

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceForceMarkTest.php --filter Drift`
Expected: FAIL — current code inserts unconditionally, never deletes, returns success.

- [ ] **Step 3: Implement verify-before-mark with rollback**

In `markMigrationAsExecuted()`, replace the direct INSERT block (`:442-446`) with INSERT-then-verify-then-maybe-rollback:

```php
            // 3. Insert directly into doctrine_migration_versions, then verify
            // the schema genuinely matches the migration's end-state. If
            // additive entity-vs-DB drift remains, this version was NOT phantom
            // — undo the metadata row and refuse so the caller runs migrate/reconcile.
            $df->getConnection()->insert('doctrine_migration_versions', [
                'version' => $version,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => 0,
            ]);

            $remainingDrift = $this->getEntityVsDbDrift();
            if ($remainingDrift !== []) {
                $df->getConnection()->delete('doctrine_migration_versions', ['version' => $version]);
                $this->auditLogger->logCustom(
                    'admin.schema.force_mark_executed.refused_drift',
                    'Doctrine',
                    null,
                    null,
                    ['version' => $version, 'remaining_drift' => array_slice($remainingDrift, 0, 5)],
                    sprintf('QuickFix: refused to mark %s — %d additive drift statement(s) remain; not phantom. Run migrate/reconcile.', $version, count($remainingDrift)),
                );
                return [
                    'success' => false,
                    'version' => $version,
                    'error' => sprintf('Refused: %d additive schema drift statement(s) remain after marking — migration "%s" is not phantom. Run migrate or reconcile instead.', count($remainingDrift), $version),
                ];
            }
```

Note: `getEntityVsDbDrift()` already returns additive-only statements (Task 1 routed it through the classifier). The INSERT+DELETE is safe because the metadata table is not under the FK envelope.

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceForceMarkTest.php`
Expected: PASS (all cases, including the original ones)

- [ ] **Step 5: Commit**

```bash
git add src/Service/SchemaMaintenanceService.php tests/Service/SchemaMaintenanceServiceForceMarkTest.php
git commit -m "fix(quick-fix): verify-before-mark — refuse non-phantom migrations (QF-3)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: markAllPhantomDiff post-drift guard + docblock truth (QF-2)

Bulk mark-all now inherits QF-3's per-version refusal automatically (it calls `markMigrationAsExecuted`). Add a post-loop drift check that, if drift remains, runs an additive reconcile and warns. Align the divergent docblock with the actual mark-only behaviour.

**Files:**
- Modify: `src/Service/SchemaMaintenanceService.php:471-573` (`markAllPhantomDiffMigrationsAsExecuted` + its docblock)
- Test: add a case to `tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php`

- [ ] **Step 1: Write the failing test**

```php
    #[Test]
    public function postDriftTriggersReconcileAndWarns(): void
    {
        // Two pending versions; both mark successfully, but drift remains after.
        $this->primePendingVersions(['App\\Migrations\\VersionA', 'App\\Migrations\\VersionB']);

        // First validate() (inside per-version verify) reports clean so marks succeed;
        // the post-loop getEntityVsDbDrift() reports residual additive drift.
        $this->schemaHealthService->method('validate')->willReturnOnConsecutiveCalls(
            $this->cleanValidation(),               // version A verify
            $this->cleanValidation(),               // version B verify
            $this->driftValidation(['ALTER TABLE z ADD COLUMN q INT']), // post-loop drift probe
        );
        $this->schemaHealthService->expects(self::once())
            ->method('applyUpdate')
            ->with('quick-fix', true, false)
            ->willReturn($this->successApplyResult());

        $result = $this->service->markAllPhantomDiffMigrationsAsExecuted('quick-fix');

        self::assertTrue($result['post_drift_reconciled'] ?? false);
    }
```

Add small fixture helpers (`primePendingVersions`, `cleanValidation`, `driftValidation`, `successApplyResult`) to the test class mirroring the existing setUp wiring.

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php --filter postDrift`
Expected: FAIL — no `post_drift_reconciled` key; `applyUpdate` never called.

- [ ] **Step 3: Add the post-loop guard**

After the `foreach ($pending as $version)` loop closes and before `$remainingPending = ...` (`:564`), insert:

```php
        // Post-loop integrity guard: if additive entity-vs-DB drift remains, the
        // operator's "they're all already applied" assertion was wrong for at
        // least one version. Reconcile additively (never destructive) + warn.
        $postDriftReconciled = false;
        $residualDrift = $this->getEntityVsDbDrift();
        if ($residualDrift !== []) {
            $reconcile = $this->schemaHealthService->applyUpdate('quick-fix', true, false);
            $postDriftReconciled = (bool) $reconcile['success'];
            $this->auditLogger->logCustom(
                'admin.schema.mark_all.post_drift_reconcile',
                'Doctrine',
                null,
                null,
                [
                    'residual_drift_count' => count($residualDrift),
                    'residual_drift' => array_slice($residualDrift, 0, 5),
                    'reconcile_success' => $postDriftReconciled,
                ],
                sprintf('QuickFix/mark-all: %d additive drift statement(s) remained after marking — additive reconcile %s.', count($residualDrift), $postDriftReconciled ? 'applied' : 'FAILED'),
            );
        }
```

Add `'post_drift_reconciled' => $postDriftReconciled,` to the final `return [...]` array (`:566-572`).

- [ ] **Step 4: Fix the docblock**

Replace the divergent docblock (`:471-495`) so it describes the real mark-only-then-verify behaviour:

```php
    /**
     * Records every file-system-pending migration as executed WITHOUT running
     * its DDL (metadata-only INSERT, equivalent to
     * `doctrine:migrations:version --add --all`). Each version is individually
     * verified by markMigrationAsExecuted(): if marking would leave additive
     * entity-vs-DB drift, that version is refused (it is not phantom).
     *
     * After the loop, a residual-drift probe runs an additive-only reconcile to
     * close any gap the operator's "all already applied" assertion missed.
     * Never executes destructive DDL.
     *
     * @return array{
     *     success: bool,
     *     marked: list<string>,
     *     skipped: array<string, string>,
     *     remaining_pending: int,
     *     post_drift_reconciled: bool,
     *     stopped_at_error: null,
     * }
     */
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Service/SchemaMaintenanceService.php tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php
git commit -m "fix(quick-fix): post-drift reconcile guard for mark-all + honest docblock (QF-2)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Accurate executed-count + offending version (QF-5, QF-10)

`executePendingMigrations()` reports `count(plan)` even on partial failure, and `diagnoseMigrationFailure()` blames the last planned version. Compute both from the executed-migrations delta.

**Files:**
- Modify: `src/Service/SchemaMaintenanceService.php:123-202` (`executePendingMigrations`), `:619-699` (`diagnoseMigrationFailure`)
- Test: `tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Version;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * executePendingMigrations() must report the number ACTUALLY executed (the
 * before/after delta in doctrine_migration_versions), not the planned count.
 */
class SchemaMaintenanceServiceExecuteDeltaTest extends TestCase
{
    #[Test]
    public function executedCountReflectsRealDeltaOnPartialFailure(): void
    {
        $storage = $this->createMock(MetadataStorage::class);
        // before: 0 executed; after: 1 executed (only the first of two landed)
        $storage->method('getExecutedMigrations')->willReturnOnConsecutiveCalls(
            new ExecutedMigrationsList([]),
            new ExecutedMigrationsList([new ExecutedMigration(new Version('App\\Migrations\\VersionA'))]),
        );

        $df = $this->createMock(DependencyFactory::class);
        $df->method('getMetadataStorage')->willReturn($storage);
        // Plan resolves to two versions; migrate() throws mid-way (FK error).
        $service = $this->makeServiceWithFailingMigrate($df, 'SQLSTATE[23000] foreign key');

        $result = $service->executePendingMigrations('test');

        self::assertFalse($result['success']);
        self::assertSame(1, $result['executed'], 'must reflect the 1 version that actually landed');
    }
}
```

Implement `makeServiceWithFailingMigrate()` in the test by mocking the `DependencyFactory` chain (`getVersionAliasResolver`, `getMigrationPlanCalculator`, `getMigrator()->migrate()` throwing). Mirror the chain already mocked in `SchemaMaintenanceServiceForceMarkTest`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php`
Expected: FAIL — current code returns `executed => 0` on failure (and `count(plan)` on success), never the delta.

- [ ] **Step 3: Compute the delta in `executePendingMigrations`**

Capture the executed set before the migrate call. Right after `if ($this->isPlanEmpty($plan)) {...}` (`:155`):

```php
        $executedBefore = $this->countExecutedMigrations();
```

Add the helper:

```php
    private function countExecutedMigrations(): int
    {
        try {
            return count(
                $this->migrationsDependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems(),
            );
        } catch (\Throwable) {
            return 0;
        }
    }
```

In the `catch (\Throwable $e)` block of the migrate call (`:163`), replace `'executed' => 0,` with the real delta:

```php
            $executedDelta = max(0, $this->countExecutedMigrations() - $executedBefore);
            // ... existing audit log ...
            return [
                'success' => false,
                'executed' => $executedDelta,
                'error' => $e->getMessage(),
                'diagnosis' => $diagnosis,
            ];
```

On the success path, replace `$executed = count($plan->getItems());` (`:185`) with:

```php
        $executed = max(0, $this->countExecutedMigrations() - $executedBefore);
```

- [ ] **Step 4: Fix offending-version identification (QF-10)**

`diagnoseMigrationFailure()` takes the plan; change it to take the executed-delta so it can identify the first UN-executed planned version. Update the call site (`:164`) to pass the executed set, and change the signature:

```php
    private function diagnoseMigrationFailure(string $errorMessage, MigrationPlanList $plan): array
    {
        $executedAfter = [];
        try {
            foreach ($this->migrationsDependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems() as $m) {
                $executedAfter[(string) $m->getVersion()] = true;
            }
        } catch (\Throwable) {
            // fall through — best-effort
        }
        $offending = null;
        foreach ($plan->getItems() as $item) {
            $v = (string) $item->getVersion();
            if (!isset($executedAfter[$v])) { $offending = $v; break; } // first not-yet-executed = the one that failed
        }
        if ($offending === null) {
            $items = $plan->getItems();
            $offending = $items !== [] ? (string) end($items)->getVersion() : null;
        }
        // ... rest of the method unchanged, using $offending ...
```

(Keep the existing pattern-matching body below; only the `$offending` derivation at the top changes.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php tests/Service/SchemaMaintenanceServiceForceMarkTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Service/SchemaMaintenanceService.php tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php
git commit -m "fix(quick-fix): real executed-count delta + accurate offending version (QF-5, QF-10)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: SchemaSnapshotService — pre-mutation anchor (QF-6)

New focused service: dump the DB before any mutation. `mysqldump` primary (schema+data → `var/quickfix-snapshots/`), `BackupService` logical export fallback, graceful-skip + loud warn if neither works.

**Files:**
- Create: `src/Service/SchemaSnapshotService.php`
- Test: `tests/Service/SchemaSnapshotServiceTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\BackupService;
use App\Service\SchemaSnapshotService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaSnapshotServiceTest extends TestCase
{
    #[Test]
    public function returnsSkippedWithWarningWhenNoMysqldumpAndLogicalFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'localhost', 'dbname' => 'x', 'user' => 'u', 'password' => 'p']);
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackup')->willThrowException(new \RuntimeException('schema broken'));

        $service = new SchemaSnapshotService(
            $conn,
            $backup,
            $this->createMock(AuditLogger::class),
            sys_get_temp_dir() . '/quickfix-test-' . getmypid(),
            mysqldumpBinary: '/nonexistent/mysqldump',
        );

        $result = $service->snapshot('test-reason');

        self::assertSame('skipped', $result['method']);
        self::assertNotNull($result['warning']);
    }

    #[Test]
    public function fallsBackToLogicalWhenMysqldumpMissing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'x']);
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackup')->willReturn(['meta' => [], 'data' => []]);
        $backup->method('saveBackupToFile')->willReturn('/tmp/logical-snap.json');

        $service = new SchemaSnapshotService(
            $conn,
            $backup,
            $this->createMock(AuditLogger::class),
            sys_get_temp_dir() . '/quickfix-test-' . getmypid(),
            mysqldumpBinary: '/nonexistent/mysqldump',
        );

        $result = $service->snapshot('test-reason');

        self::assertSame('logical', $result['method']);
        self::assertSame('/tmp/logical-snap.json', $result['path']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaSnapshotServiceTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Produces a pre-mutation snapshot of the database so every Quick-Fix
 * schema/data repair has a rollback anchor.
 *
 * Strategy: mysqldump (schema + data) when the binary is available, else the
 * logical BackupService export as a weaker fallback, else a logged skip.
 * Never throws — a missing dump tool must not block an emergency repair, but
 * the operator is warned loudly.
 */
final class SchemaSnapshotService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupService $backupService,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%kernel.project_dir%/var/quickfix-snapshots')]
        private readonly string $snapshotDir,
        #[Autowire('mysqldump')]
        private readonly string $mysqldumpBinary = 'mysqldump',
    ) {
    }

    /**
     * @return array{method: 'mysqldump'|'logical'|'skipped', path: ?string, warning: ?string}
     */
    public function snapshot(string $reason): array
    {
        if (!is_dir($this->snapshotDir)) {
            @mkdir($this->snapshotDir, 0775, true);
        }
        $stamp = (new \DateTimeImmutable())->format('Ymd_His');
        $params = $this->connection->getParams();
        $driver = (string) ($params['driver'] ?? '');

        // 1. mysqldump (MySQL/MariaDB only)
        if (str_contains($driver, 'mysql')) {
            $dumpPath = sprintf('%s/snap_%s.sql', $this->snapshotDir, $stamp);
            $dumped = $this->tryMysqldump($params, $dumpPath);
            if ($dumped) {
                $this->auditLogger->logCustom(
                    'admin.schema.snapshot.created',
                    'Doctrine', null, null,
                    ['method' => 'mysqldump', 'path' => $dumpPath, 'reason' => $reason],
                    sprintf('Quick-Fix snapshot (mysqldump) before %s → %s', $reason, $dumpPath),
                );
                return ['method' => 'mysqldump', 'path' => $dumpPath, 'warning' => null];
            }
        }

        // 2. Logical fallback
        try {
            $backup = $this->backupService->createBackup();
            $path = $this->backupService->saveBackupToFile($backup, sprintf('quickfix_logical_%s.json', $stamp));
            $this->auditLogger->logCustom(
                'admin.schema.snapshot.created',
                'Doctrine', null, null,
                ['method' => 'logical', 'path' => $path, 'reason' => $reason],
                sprintf('Quick-Fix snapshot (logical export) before %s → %s', $reason, $path),
            );
            return ['method' => 'logical', 'path' => $path, 'warning' => 'mysqldump unavailable — logical export only (no schema DDL captured)'];
        } catch (\Throwable $e) {
            $warning = sprintf('No snapshot taken before %s — mysqldump unavailable and logical export failed: %s', $reason, $e->getMessage());
            $this->auditLogger->logCustom(
                'admin.schema.snapshot.skipped',
                'Doctrine', null, null,
                ['reason' => $reason, 'error' => $e->getMessage()],
                'CRITICAL: ' . $warning,
            );
            return ['method' => 'skipped', 'path' => null, 'warning' => $warning];
        }
    }

    /** @param array<string,mixed> $params */
    private function tryMysqldump(array $params, string $outPath): bool
    {
        $host = (string) ($params['host'] ?? '127.0.0.1');
        $port = (string) ($params['port'] ?? '3306');
        $db = (string) ($params['dbname'] ?? '');
        $user = (string) ($params['user'] ?? '');
        $pass = (string) ($params['password'] ?? '');
        if ($db === '') {
            return false;
        }

        $process = new Process([
            $this->mysqldumpBinary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $user,
            '--single-transaction',
            '--routines',
            '--result-file=' . $outPath,
            $db,
        ], env: ['MYSQL_PWD' => $pass]); // password via env, never on the cmdline
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($outPath) && filesize($outPath) > 0;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaSnapshotServiceTest.php`
Expected: PASS

- [ ] **Step 5: Ignore the snapshot dir**

Add to `.gitignore`:

```
/var/quickfix-snapshots/
```

- [ ] **Step 6: Wire snapshot into mutating jobs**

In each of `src/Job/QuickFixApplyMigrationsJob.php`, `QuickFixReconcileSchemaJob.php`, `QuickFixForceSchemaUpdateJob.php`, `QuickFixRepairAllJob.php`: inject `SchemaSnapshotService` via the constructor and call it as the first line of `run()`. Example for `QuickFixForceSchemaUpdateJob`:

```php
    public function __construct(
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
        private readonly SchemaSnapshotService $snapshotService,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $snap = $this->snapshotService->snapshot('force-schema-update');
        if ($snap['warning'] !== null) {
            $ctx->message('⚠ ' . $snap['warning']);
        } else {
            $ctx->message(sprintf('Snapshot saved (%s).', $snap['method']));
        }
        $ctx->message('Forcing schema update (additive only unless explicitly allowed)…');
        // ... existing body ...
    }
```

Apply the same two-line pattern (inject + snapshot-first) to the other three jobs, using a `reason` string matching the job (`'apply-migrations'`, `'reconcile-schema'`, `'repair-all'`).

- [ ] **Step 7: Verify container + run job-related tests**

Run: `php bin/console lint:container`
Expected: no errors (new service autowires; `Process` is available via symfony/process).

Run: `php bin/phpunit tests/Service/SchemaSnapshotServiceTest.php tests/Controller/QuickFixControllerTest.php`
Expected: SchemaSnapshotServiceTest PASS; controller test unchanged from baseline.

- [ ] **Step 8: Commit**

```bash
git add src/Service/SchemaSnapshotService.php tests/Service/SchemaSnapshotServiceTest.php src/Job/QuickFix*.php .gitignore
git commit -m "feat(quick-fix): pre-mutation DB snapshot anchor for every repair (QF-6)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Advisory lock around mutating paths (QF-7)

Wrap mutating service entry points in a MySQL `GET_LOCK` so two concurrent operators cannot race DDL.

**Files:**
- Modify: `src/Service/SchemaMaintenanceService.php` (add `withSchemaLock`, wrap `executePendingMigrations`, `reconcileSchema`, `forceSchemaUpdate`, `markAllPhantomDiffMigrationsAsExecuted`)
- Test: `tests/Service/SchemaMaintenanceServiceLockTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SchemaMaintenanceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaMaintenanceServiceLockTest extends TestCase
{
    #[Test]
    public function withSchemaLockReturnsBlockedWhenLockBusy(): void
    {
        // Connection's GET_LOCK returns 0 (busy).
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('fetchOne')->willReturn(0);

        $service = $this->makeServiceWithConnection($conn); // helper mirrors other tests
        $ref = new \ReflectionMethod($service, 'withSchemaLock');
        $result = $ref->invoke($service, fn () => ['success' => true]);

        self::assertSame(['success' => false, 'blocked' => 'locked', 'error' => 'Another schema operation is in progress.'], $result);
    }

    #[Test]
    public function withSchemaLockRunsAndReleasesWhenFree(): void
    {
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        // GET_LOCK → 1 (acquired); RELEASE_LOCK → 1
        $conn->method('fetchOne')->willReturnOnConsecutiveCalls(1, 1);
        $released = false;
        $conn->method('executeStatement')->willReturnCallback(function () use (&$released): int { $released = true; return 0; });

        $service = $this->makeServiceWithConnection($conn);
        $ref = new \ReflectionMethod($service, 'withSchemaLock');
        $result = $ref->invoke($service, fn () => ['success' => true, 'executed' => 3]);

        self::assertSame(['success' => true, 'executed' => 3], $result);
    }
}
```

Add `makeServiceWithConnection()` to the test (construct `SchemaMaintenanceService` with mocked collaborators; the `EntityManager` mock's `getConnection()` returns `$conn` — but note this service gets its connection via `DependencyFactory->getConnection()` and `managerRegistry`; expose the connection through whichever the lock helper uses — see Step 3).

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceLockTest.php`
Expected: FAIL — `withSchemaLock` undefined.

- [ ] **Step 3: Implement `withSchemaLock`**

Add to `SchemaMaintenanceService`. Use the migrations connection (already available via `$this->migrationsDependencyFactory->getConnection()`):

```php
    private const SCHEMA_LOCK_NAME = 'quickfix_schema';

    /**
     * Serialises mutating schema operations via a MySQL advisory lock so two
     * operators cannot race DDL. Returns a blocked-result without running the
     * callback when the lock is already held. Non-MySQL drivers run unguarded.
     *
     * @template T of array
     * @param callable():T $operation
     * @return T|array{success: false, blocked: 'locked', error: string}
     */
    private function withSchemaLock(callable $operation): array
    {
        $conn = $this->migrationsDependencyFactory->getConnection();
        try {
            $got = $conn->fetchOne('SELECT GET_LOCK(:n, 0)', ['n' => self::SCHEMA_LOCK_NAME]);
        } catch (\Throwable) {
            // Non-MySQL or locking unsupported — run unguarded.
            return $operation();
        }
        if ((int) $got !== 1) {
            return ['success' => false, 'blocked' => 'locked', 'error' => 'Another schema operation is in progress.'];
        }
        try {
            return $operation();
        } finally {
            try { $conn->executeStatement('SELECT RELEASE_LOCK(' . $conn->quote(self::SCHEMA_LOCK_NAME) . ')'); } catch (\Throwable) {}
        }
    }
```

- [ ] **Step 4: Wrap the four mutating entry points**

Rename each current public method body into a private `…Locked()` worker and have the public method delegate through `withSchemaLock`. Example for `forceSchemaUpdate`:

```php
    public function forceSchemaUpdate(string $actor = 'system'): array
    {
        return $this->withSchemaLock(fn (): array => $this->forceSchemaUpdateLocked($actor));
    }

    private function forceSchemaUpdateLocked(string $actor = 'system'): array
    {
        // ... existing body verbatim ...
    }
```

Do the same for `executePendingMigrations`, `reconcileSchema`, `markAllPhantomDiffMigrationsAsExecuted`. The auto-recovery chain in `QuickFixReconcileSchemaJob` calls `executePendingMigrations` then `reconcileSchema` — both will re-acquire the same lock sequentially in the same request; that is fine because each releases in its `finally`. (Do NOT nest: the job calls them one after another, not within each other.)

- [ ] **Step 5: Map `blocked: 'locked'` to 409 in the jobs/controller**

In each mutating job's `run()`, after calling the service, before treating success:

```php
        if (($result['blocked'] ?? null) === 'locked') {
            throw new \RuntimeException('Another schema operation is already running. Try again shortly.');
        }
```

- [ ] **Step 6: Run tests**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceLockTest.php tests/Service/SchemaMaintenanceServiceForceMarkTest.php tests/Service/SchemaMaintenanceServiceForceSchemaUpdateTest.php tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php`
Expected: PASS (the existing service tests call the now-delegating public methods; the mocked connection's `fetchOne` for GET_LOCK must return 1 — update those test setups to stub `fetchOne('SELECT GET_LOCK...')` → 1 if they assert on the public methods).

- [ ] **Step 7: Commit**

```bash
git add src/Service/SchemaMaintenanceService.php src/Job/QuickFix*.php tests/Service/SchemaMaintenanceServiceLockTest.php
git commit -m "feat(quick-fix): advisory lock serialises concurrent schema repairs (QF-7)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: force-route honest gate + clean-verdict (QF-1 UI, QF-9)

Two UI-facing closures: the force route must surface destructive content honestly (not claim "never destroys data"), and the index page must show a green/red clean-verdict.

**Files:**
- Modify: `src/Service/SchemaMaintenanceService.php` (add `verifyClean()`)
- Modify: `src/Controller/QuickFixController.php` (force route: read `confirm_destructive_force`; index: pass verdict)
- Modify: `src/Job/QuickFixForceSchemaUpdateJob.php` (thread allowDestructive)
- Modify: `src/Service/SchemaMaintenanceService.php` `forceSchemaUpdate(string $actor, bool $allowDestructive=false)`
- Modify: `templates/quick_fix/index.html.twig` (verdict band + honest force copy)
- Test: `tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php` (create)

- [ ] **Step 1: Write the failing test for `verifyClean`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaMaintenanceServiceVerifyCleanTest extends TestCase
{
    #[Test]
    public function okWhenNoPendingAndNoDrift(): void
    {
        $health = $this->createMock(SchemaHealthService::class);
        $health->method('listPendingMigrationVersions')->willReturn([]);
        $health->method('validate')->willReturn([
            'mapping_in_sync' => true, 'database_in_sync' => true, 'mapping_errors' => [],
            'pending_sql' => [], 'pending_migrations' => [], 'overall_status' => 'healthy',
        ]);

        $service = new SchemaMaintenanceService(
            $health,
            $this->createMock(DependencyFactory::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(ManagerRegistry::class),
        );

        self::assertSame(
            ['migrations_up_to_date' => true, 'drift_empty' => true, 'ok' => true],
            $service->verifyClean(),
        );
    }

    #[Test]
    public function notOkWhenDriftRemains(): void
    {
        $health = $this->createMock(SchemaHealthService::class);
        $health->method('listPendingMigrationVersions')->willReturn([]);
        $health->method('validate')->willReturn([
            'mapping_in_sync' => true, 'database_in_sync' => false, 'mapping_errors' => [],
            'pending_sql' => ['ALTER TABLE a ADD COLUMN b INT'], 'pending_migrations' => [], 'overall_status' => 'warning',
        ]);

        $service = new SchemaMaintenanceService(
            $health,
            $this->createMock(DependencyFactory::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(ManagerRegistry::class),
        );

        $result = $service->verifyClean();
        self::assertFalse($result['ok']);
        self::assertFalse($result['drift_empty']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php`
Expected: FAIL — `verifyClean()` undefined.

- [ ] **Step 3: Implement `verifyClean`**

```php
    /**
     * Final clean-verdict: true only when no migrations are pending AND the
     * entity-vs-DB additive drift is empty. Powers the index-page green/red band.
     *
     * @return array{migrations_up_to_date: bool, drift_empty: bool, ok: bool}
     */
    public function verifyClean(): array
    {
        $pending = $this->schemaHealthService->listPendingMigrationVersions();
        $driftEmpty = $this->getEntityVsDbDrift() === [];
        $migrationsUpToDate = $pending === [];

        return [
            'migrations_up_to_date' => $migrationsUpToDate,
            'drift_empty' => $driftEmpty,
            'ok' => $migrationsUpToDate && $driftEmpty,
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php`
Expected: PASS

- [ ] **Step 5: Thread allowDestructive through force**

In `SchemaMaintenanceService::forceSchemaUpdate` (now `forceSchemaUpdateLocked` after Task 8) change signature to accept `bool $allowDestructive = false` and pass it: `$this->schemaHealthService->applyUpdate($actor, bypassMigrationGate: true, allowDestructive: $allowDestructive);`. Surface `statements_skipped_destructive` in the return array from `$result['skipped_destructive']`.

In `QuickFixForceSchemaUpdateJob::run()`, read the flag from the job args (added next) and pass it: `$this->schemaMaintenanceService->forceSchemaUpdate('quick-fix', $allowDestructive)`. Add `allowDestructive` to the job constructor args via `JobContext`/dispatch payload — the controller sets it from the checkbox.

- [ ] **Step 6: Force route — honest destructive gate**

In `QuickFixController::forceSchemaUpdate()` (`:638`), after the existing `confirm_force_schema` check, read a second, destructive-specific confirmation and pass it into the job args:

```php
        $allowDestructive = (bool) $request->request->get('confirm_destructive_force', false);
```

Pass `['allowDestructive' => $allowDestructive]` as the job args (the last argument to `dispatchJobProgress`, replacing the current `[]`). Update `QuickFixForceSchemaUpdateJob` to accept the arg (jobs receive args via the dispatcher — follow `ExportRisksJob`/existing QuickFix jobs' arg pattern).

- [ ] **Step 7: Index template — verdict band + honest copy**

In `QuickFixController::index()` add to the render payload: `'clean_verdict' => $this->schemaMaintenanceService->verifyClean(),`.

In `templates/quick_fix/index.html.twig`:

a) Near the top of the status section, add the verdict band:

```twig
{% if clean_verdict.ok %}
    <div class="alert alert-success" role="status">
        ✓ {{ 'quick_fix.verdict.clean'|trans({}, 'quick_fix') }}
    </div>
{% else %}
    <div class="alert alert-warning" role="status">
        ⚠ {{ 'quick_fix.verdict.dirty'|trans({
            '%migrations%': clean_verdict.migrations_up_to_date ? '✓' : '✗',
            '%drift%': clean_verdict.drift_empty ? '✓' : '✗',
        }, 'quick_fix') }}
    </div>
{% endif %}
```

b) Replace the false "never destroys data / saveMode=true" copy near each `confirm_force_schema` checkbox (`:179`, `:252`, `:373`) with honest wording and add the destructive opt-in:

```twig
<label class="d-block">
    <input type="checkbox" name="confirm_force_schema" value="1" required style="margin-top: 3px;">
    {{ 'quick_fix.force.confirm'|trans({}, 'quick_fix') }}
</label>
<label class="d-block text-danger">
    <input type="checkbox" name="confirm_destructive_force" value="1" style="margin-top: 3px;">
    {{ 'quick_fix.force.confirm_destructive'|trans({}, 'quick_fix') }}
</label>
```

(Leave `confirm_destructive_force` un-`required` — additive force is the default; only ticking it permits DROP.)

- [ ] **Step 8: Add translations**

Add to `translations/quick_fix.de.yaml` and `quick_fix.en.yaml` (mirror existing key nesting in those files):

DE:
```yaml
verdict:
    clean: 'Schema sauber — keine ausstehenden Migrationen, keine Drift.'
    dirty: 'Schema nicht sauber. Migrationen aktuell: %migrations% · Drift leer: %drift%.'
force:
    confirm: 'Ich bestätige das erzwungene additive Schema-Update.'
    confirm_destructive: 'Auch destruktive Änderungen (DROP) zulassen — Datenverlust möglich. Snapshot wird vorher erstellt.'
```

EN:
```yaml
verdict:
    clean: 'Schema clean — no pending migrations, no drift.'
    dirty: 'Schema not clean. Migrations up to date: %migrations% · Drift empty: %drift%.'
force:
    confirm: 'I confirm the forced additive schema update.'
    confirm_destructive: 'Also allow destructive changes (DROP) — possible data loss. A snapshot is taken first.'
```

- [ ] **Step 9: Validate templates + translations + run tests**

Run: `php bin/console lint:twig templates/quick_fix/`
Expected: OK

Run: `python3 scripts/quality/check_translation_issues.py 2>&1 | tail -5`
Expected: no new issues for `quick_fix` domain (regenerate the relevant baseline if a line-shift trips Gate 26 — see CLAUDE.md / memory `feedback_gate26_line_baseline_shift`).

Run: `php bin/phpunit tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php tests/Controller/QuickFixControllerTest.php`
Expected: VerifyClean PASS; controller test green or unchanged from baseline (if a copy-assertion breaks, update it to the new honest strings).

- [ ] **Step 10: Commit**

```bash
git add src/Service/SchemaMaintenanceService.php src/Controller/QuickFixController.php src/Job/QuickFixForceSchemaUpdateJob.php templates/quick_fix/index.html.twig translations/quick_fix.de.yaml translations/quick_fix.en.yaml tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php
git commit -m "feat(quick-fix): honest force-gate + clean-verdict band (QF-1 UI, QF-9)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Full verification + finish

**Files:** none (verification only)

- [ ] **Step 1: PHP syntax**

Run: `find src -name "*.php" -newer composer.json -print0 | xargs -0 -n1 php -l`
Expected: "No syntax errors detected" for each.

- [ ] **Step 2: Container + Twig lint**

Run: `php bin/console lint:container && php bin/console lint:twig templates/`
Expected: both OK.

- [ ] **Step 3: Full service-layer suite for the touched area**

Run: `php bin/phpunit tests/Service/SchemaHealthServiceDriftClassifierTest.php tests/Service/SchemaHealthServiceDestructiveGateTest.php tests/Service/SchemaHealthServiceFkAwareTest.php tests/Service/SchemaMaintenanceServiceForceMarkTest.php tests/Service/SchemaMaintenanceServiceMarkAllPhantomDiffTest.php tests/Service/SchemaMaintenanceServiceForceSchemaUpdateTest.php tests/Service/SchemaMaintenanceServiceExecuteDeltaTest.php tests/Service/SchemaMaintenanceServiceLockTest.php tests/Service/SchemaMaintenanceServiceVerifyCleanTest.php tests/Service/SchemaSnapshotServiceTest.php tests/Service/QuickFixGuardTest.php`
Expected: PASS (all).

- [ ] **Step 4: Static analysis (if PHPStan configured in CI)**

Run: `vendor/bin/phpstan analyse src/Service/SchemaHealthService.php src/Service/SchemaMaintenanceService.php src/Service/SchemaSnapshotService.php --no-progress 2>&1 | tail -15`
Expected: no new errors above the project baseline. Fix any introduced (e.g. nullable array keys).

- [ ] **Step 5: Push + open PR**

```bash
git push -u origin fix/quickfix-schema-repair-hardening
gh pr create --title "fix(quick-fix): schema-repair hardening — 10 safety findings" --body "$(cat <<'EOF'
Closes 10 source-grounded gaps in the Quick-Fix schema-repair UI (spec: docs/superpowers/specs/2026-06-12-quickfix-schema-repair-hardening-design.md).

- QF-1 destructive DDL withheld unless explicitly opted in (+ honest force-route copy)
- QF-2 mark-all post-drift reconcile guard + truthful docblock
- QF-3 verify-before-mark with rollback
- QF-4 dropped-FK re-add failure is fatal-loud
- QF-5 real executed-count delta on partial failure
- QF-6 pre-mutation snapshot (mysqldump primary, logical fallback)
- QF-7 advisory lock serialises concurrent repairs
- QF-8 single drift classifier as source of truth
- QF-9 clean-verdict band on index page
- QF-10 accurate offending-version identification

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Watch CI; squash-merge on fully-green**

Per memory `feedback_auto_merge_on_green` + `feedback_ci_gate_on_success`: only merge when CI conclusion == SUCCESS. `gh pr merge --squash --delete-branch` when green.

---

## Self-Review

**Spec coverage:** QF-1 → Tasks 2 + 9; QF-2 → Task 5; QF-3 → Task 4; QF-4 → Task 3; QF-5 → Task 6; QF-6 → Task 7; QF-7 → Task 8; QF-8 → Task 1; QF-9 → Task 9; QF-10 → Task 6. All ten covered.

**Type consistency:** `applyUpdate()` returns `skipped_destructive` (Tasks 2, 9). `classifyStatements()` returns `{additive, destructive, errors}` (Task 1, used in 2/4/9). `verifyClean()` returns `{migrations_up_to_date, drift_empty, ok}` (Task 9). `snapshot()` returns `{method, path, warning}` (Task 7). `withSchemaLock` blocked-shape `{success:false, blocked:'locked', error}` (Task 8, mapped in jobs). Consistent.

**Ordering risk:** Task 8 wraps the public methods that earlier-task tests exercise — Step 6 of Task 8 notes the GET_LOCK stub the existing tests need. Task 4's verify-before-mark relies on Task 1's classifier-backed `getEntityVsDbDrift()`. Both dependencies are respected by the task order.

**Open follow-up (not in scope):** `sync-metadata-storage` is handled implicitly by Doctrine; no explicit task — documented as out-of-scope in the spec.
